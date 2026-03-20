<?php
/**
 * Script to correct existing Shibboleth users
 * Run from command line: php local/shibboleth_username_fix/cli/fix_shibboleth_existing_users.php
 */

define('CLI_SCRIPT', true);
require(__DIR__.'/../../../config.php');
require_once($CFG->libdir.'/clilib.php');

// CLI parameters
list($options, $unrecognized) = cli_get_params(
    array(
        'help' => false,
        'execute' => false
    ),
    array('h' => 'help', 'e' => 'execute')
);

if ($options['help']) {
    echo "Script to normalise existing Shibboleth users

Options:
--execute     Execute the changes (without this, it only shows what it would do)
-h, --help    Show this help

Examples:
# See what changes would be made (test mode):
php local/shibboleth_username_fix/cli/fix_shibboleth_existing_users.php

# Execute the changes:
php local/shibboleth_username_fix/cli/fix_shibboleth_existing_users.php --execute
";
    exit(0);
}

$dryrun = !$options['execute'];

if ($dryrun) {
    echo "*** TEST MODE - No actual changes will be made ***\n";
    echo "*** Use --execute to apply the changes ***\n\n";
}

// Get all Shibboleth users
$users = $DB->get_records_select('user', 
    "auth = 'shibboleth' AND deleted = 0 AND username LIKE '%@%'",
    null, '', 'id, username, idnumber, email');

$total = count($users);
$updated = 0;
$skipped = 0;

echo "Found $total users with Shibboleth and @ in username\n\n";

foreach ($users as $user) {
    if (strpos($user->username, '@') !== false) {
        list($local_part, $domain) = explode('@', $user->username, 2);
        $local_part = strtolower(trim($local_part));
        
        $needs_update = false;
        $changes = [];
        
        // Check if the username needs to be changed.
        if ($user->username !== $local_part) {
            $changes[] = "username: '{$user->username}' -> '{$local_part}'";
            $needs_update = true;
        }
        
        // Check if idnumber is blank or needs changing.
        if (empty($user->idnumber) || $user->idnumber !== $local_part) {
            $old_idnumber = empty($user->idnumber) ? '(empty)' : $user->idnumber;
            $changes[] = "idnumber: '{$old_idnumber}' -> '{$local_part}'";
            $needs_update = true;
        }
        
        if ($needs_update) {
            echo "User ID {$user->id}:\n";
            foreach ($changes as $change) {
                echo "  - $change\n";
            }
            
            if (!$dryrun) {
                // Verify that there is no other user with the local username.
                $existing = $DB->get_record('user', 
                    ['username' => $local_part, 'deleted' => 0]);
                
                if ($existing && $existing->id != $user->id) {
                    echo "  ⚠ WARNING: User with username already exists. '$local_part' (ID: {$existing->id})\n";
                    echo "  ⚠ This is a duplicate that must be merged manually.\n";
                    $skipped++;
                } else {
                    $user->username = $local_part;
                    $user->idnumber = $local_part;
                    
                    try {
                        $DB->update_record('user', $user);
                        echo "  ✓ Updated\n";
                        $updated++;
                    } catch (Exception $e) {
                        echo "  ✗ Error: " . $e->getMessage() . "\n";
                        $skipped++;
                    }
                }
            }
            echo "\n";
        }
    }
}

echo "\n=== RESUME ===\n";
echo "Total users processed: $total\n";
if ($dryrun) {
    echo "Users who would be updated: " . ($updated + $skipped) . "\n";
    echo "\n*** Run with --execute to apply the changes. ***\n";
} else {
    echo "Updated users: $updated\n";
    echo "Omitted users (duplicates or errors): $skipped\n";
}
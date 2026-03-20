<?php
/**
 * Local plugin to fix Shibboleth usernames before user lookup/creation
 *
 * @package    local_shibboleth_username_fix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Hook that runs BEFORE authentication
 * Intercepts and modifies the entire eppn username to only the local part
 */
function local_shibboleth_username_fix_before_shibboleth_auth() {
    global $SESSION;    

    if (empty($_SERVER['Shib-Session-ID'])) {
        return;
    }

    $eppn_attribute = 'eppn';

    $local_domains = ['univ-lemans.fr'];

    $alliance_domain_map = [
        'uclm.es' => 'UCLM',
        'unife.it' => 'UNIFE',
        'uni-paderborn.de' => 'UPB',
        'hkr.se' => 'HKR',
        'ujd.edu.pl' => 'JDU',
        'unios.hr' => 'UNIOS',
        'uklo.edu.mk' => 'UKLO',
        'venta.lv' => 'VUAS',
    ];

    if (!empty($_SERVER[$eppn_attribute])) {
        $original_eppn = $_SERVER[$eppn_attribute];

        // Only process if contains @
        if (strpos($original_eppn, '@') !== false) {
            list($local_part, $domain) = explode('@', $original_eppn, 2);
            $local_part = strtolower(trim($local_part));
            $domain = strtolower(trim($domain));

            $_SERVER[$eppn_attribute] = $local_part;

            if (!in_array(strtolower($domain), $local_domains) && isset($alliance_domain_map[$domain])) {
                $institution_code = $alliance_domain_map[$domain];
                $_SERVER[$eppn_attribute] = "CO-{$institution_code}_{$local_part}";

                error_log("User type: COLOURS alliance member from $institution_code");
            }

            if (!isset($SESSION)) {
                $SESSION = new \stdClass();
            }

            $SESSION->user_domain = $domain;   // Saves institution domain: String after @ from Shibboleth Eppn attribute         
            error_log("User domain: ".$SESSION->user_domain);

            $possible_eppn_vars = array('EPPN', 'eduPersonPrincipalName', 'eppn');
            foreach ($possible_eppn_vars as $var) {
                if (!empty($_SERVER[$var]) && $_SERVER[$var] === $original_eppn) {
                    $_SERVER[$var] = $local_part;
                    error_log("Also updated \$_SERVER['$var'] to: $local_part");
                }
            }

        }
    }
}


/**
 * Hook for when the login page loads
 * This is the earliest point at which to intercept
 */
function local_shibboleth_username_fix_after_config() {
    global $CFG;
    error_log("Shibboleth Username Fix: Enters function local_shibboleth_username_fix_after_config()");

    if (empty($_SERVER['Shib-Session-ID'])) {
        return;
    }

    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
    if (strpos($script, '/login/') !== false || strpos($script, '/auth/shibboleth/') !== false) {
        local_shibboleth_username_fix_before_shibboleth_auth();
        local_shibboleth_username_fix_allow_cross_auth();
    }

    
}

/**
 * Hook to allow Shibboleth login for users of other methods
 */
function local_shibboleth_username_fix_allow_cross_auth() {
    global $DB, $CFG;
    
    $eppn = $_SERVER['eppn'] ?? '';
    
    if (empty($eppn)) {
        return;
    }
    
    $username = (strpos($eppn, '@') !== false) 
        ? explode('@', $eppn)[0] 
        : $eppn;
    
    $username = strtolower(trim($username));
    
    $user = $DB->get_record('user', [
        'username' => $username,
        'deleted' => 0,
        'suspended' => 0
    ]);
    
    if ($user) {
        error_log("Shibboleth: User {$user->username} authenticated via Shibboleth (original auth: {$user->auth})");
        
        complete_user_login($user);
        
        $userauth = get_auth_plugin('shibboleth');
        $shibboleth_userinfo = $userauth->get_userinfo($username);
        
        if (!empty($shibboleth_userinfo)) {
            foreach ($shibboleth_userinfo as $key => $value) {
                if (isset($user->$key) && $user->$key != $value) {
                    $user->$key = $value;
                }
            }
            $DB->update_record('user', $user);
        }
        
        $urltogo = $CFG->wwwroot.'/'; 
        unset($SESSION->wantsurl);  

        if (isset($SESSION->wantsurl) and (strpos($SESSION->wantsurl, $CFG->wwwroot) === 0)) {
            $urltogo = $SESSION->wantsurl;    /// Because it's an address in this site
            unset($SESSION->wantsurl);

        } else {
            $urltogo = $CFG->wwwroot.'/';      /// Go to the standard home page
            unset($SESSION->wantsurl);         /// Just in case
        }

        redirect($urltogo);
        exit;
    }
    
    return;
}
# UMTICE Shibboleth Username Fix For COLOURS Alliance — Moodle Local Plugin

**Package:** `local_shibboleth_username_fix`  
**Version:** 1.0 (2026-01-08)  
**Requires:** Moodle 3.9 or higher  
**Maturity:** Stable  
**License:** GNU GPL v3 or later

---

## Overview

This Moodle local plugin solves a username normalisation problem that arises when users authenticate through **Shibboleth** (federated identity / SAML2) on the **UMTICE** platform (Université du Mans).

When a user logs in via Shibboleth, their identity is carried in the **`eppn`** attribute (eduPersonPrincipalName), which has the format `localpart@domain` (e.g. `jsmith@univ-lemans.fr` or `maria.garcia@uclm.es`). Moodle would store this full value as the username, causing inconsistencies — especially for users who existed before federated login was introduced, or who have accounts under a different auth method.

The plugin intercepts the authentication flow **before** Moodle performs the user lookup or account creation and rewrites the username according to the user's origin:

| User type | Origin domain example | Resulting username |
|---|---|---|
| **Local** (home institution) | `univ-lemans.fr` | `localpart` (e.g. `jsmith`) |
| **Alliance member** (COLOURS partner) | `uclm.es` | `CO-{CODE}_localpart` (e.g. `CO-UCLM_maria.garcia`) |

---

## Background: The COLOURS Alliance

This plugin is built for the **COLOURS** European university alliance. Partner institutions whose users can authenticate on UMTICE and their assigned institution codes are:

| Institution domain | Code |
|---|---|
| `uclm.es` | `UCLM` |
| `unife.it` | `UNIFE` |
| `uni-paderborn.de` | `UPB` |
| `hkr.se` | `HKR` |
| `ujd.edu.pl` | `JDU` |
| `unios.hr` | `UNIOS` |
| `uklo.edu.mk` | `UKLO` |
| `venta.lv` | `VUAS` |

---

## How It Works

### Authentication hooks

The plugin registers two Moodle hooks via `lib.php`:

#### 1. `after_config`
Fires very early on every page load. If a Shibboleth session is detected (`Shib-Session-ID` is present) and the current script is on a login or Shibboleth auth path, it triggers the two functions below.

#### 2. `before_shibboleth_auth` — Username rewriting
Reads the raw `eppn` value from `$_SERVER` and applies the following logic:

```
eppn = "localpart@domain"
         │
         ├─ domain in $local_domains  →  username = "localpart"
         │
         └─ domain in $alliance_domain_map  →  username = "CO-{CODE}_localpart"
```

The rewritten value is written back to `$_SERVER['eppn']` (and any other common eppn-related keys) so that the Shibboleth auth plugin picks it up transparently. The original domain is saved to `$SESSION->user_domain` for downstream use.

#### 3. `allow_cross_auth` — Cross-authentication bridge
After the username is normalised, the plugin looks up the local part in the Moodle user table. If a match is found — regardless of the `auth` method that the account was originally created with — the user is logged in and their profile is refreshed with the latest Shibboleth attributes. This allows existing accounts (created manually, via LDAP, etc.) to seamlessly transition to Shibboleth login without requiring a separate migration step.

---

## Project Structure

```
src/shibboleth_username_fix/
├── lib.php                          # Core plugin logic (hooks)
├── version.php                      # Plugin metadata
├── cli/
│   └── fix_shibboleth_existing_users.php   # Migration CLI script
├── db/
│   └── events.php                   # Event observer declarations
└── lang/
    ├── eng/
    │   └── local_shibboleth_username_fix.php
    └── fr/
        └── local_shibboleth_username_fix.php
```

---

## Installation

1. Copy the `shibboleth_username_fix` folder into your Moodle installation's `local/` directory:
   ```
   /path/to/moodle/local/shibboleth_username_fix/
   ```
2. Log in as a Moodle administrator and navigate to **Site administration → Notifications** to trigger the plugin installation.
3. Ensure your Moodle instance has the **Shibboleth authentication plugin** enabled and properly configured.

### Prerequisites

Before installing this plugin, verify the following requirements are met:

- **Shibboleth Service Provider (SP)** is installed and running on the web server (Apache `mod_shib` or equivalent).
- The SP is configured to **pass the `eppn` attribute as a server variable** (i.e. `ShibUseEnvironment On` and the attribute mapping includes `eppn`). Without this, `$_SERVER['eppn']` will be empty and the plugin will not act.
- The Moodle **Shibboleth auth plugin** (`auth/shibboleth`) is enabled under **Site administration → Plugins → Authentication → Manage authentication** and its *Username field* is set to `eppn`.
- The Shibboleth SP federation metadata includes the Identity Providers of all partner institutions whose users need access.

---

## Migrating Existing Users (CLI)

If your Moodle instance already has Shibboleth users whose usernames contain a full `eppn` (i.e. `localpart@domain`), use the bundled CLI script to normalise them.

### Dry run (preview only — no changes applied)
```bash
php local/shibboleth_username_fix/cli/fix_shibboleth_existing_users.php
```

### Execute migration
```bash
php local/shibboleth_username_fix/cli/fix_shibboleth_existing_users.php --execute
```

The script will:
- Find all Shibboleth users whose `username` contains `@`.
- Strip the domain part, updating both `username` and `idnumber` to the local part.
- **Skip** any user where the resulting username would conflict with an existing account, printing a warning so that duplicates can be resolved manually.
- Print a summary of updated and skipped records.

> **Note:** Always run in dry-run mode first to review changes before executing.

---

## Configuration

The plugin does not expose admin-facing settings. Customisation is done directly in `lib.php`:

| Variable | Location | Purpose |
|---|---|---|
| `$eppn_attribute` | `before_shibboleth_auth()` | Name of the server variable carrying the eppn (default: `eppn`) |
| `$local_domains` | `before_shibboleth_auth()` | Domains treated as local/home institution |
| `$alliance_domain_map` | `before_shibboleth_auth()` | Map of partner domains to their institution codes |

### Adding a new partner institution

When a new university joins the COLOURS alliance and its users need access to UMTICE, only one change is required in `lib.php`:

1. Open `src/shibboleth_username_fix/lib.php`.
2. Add the new institution's domain and a unique code to `$alliance_domain_map` inside `local_shibboleth_username_fix_before_shibboleth_auth()`:

```php
$alliance_domain_map = [
    // ... existing entries ...
    'new-university.edu' => 'NEWU',   // <-- add this line
];
```

3. Choose a code that is short, uppercase, and not already in use. It will become part of the username prefix (`CO-NEWU_localpart`).
4. No database migration or Moodle cache purge is needed — the change takes effect on the next login attempt by a user from that domain.

> **Important:** Usernames are generated at login time, so new users from the added institution will automatically receive the correct prefix. Existing users (if any) should be handled with the CLI migration script.

---

## Troubleshooting

This plugin writes diagnostic messages to the PHP error log at key points in the authentication flow. To investigate issues, tail your server's error log while attempting a login:

```bash
tail -f /var/log/apache2/error.log   # adjust path for your server
```

### Log messages reference

| Message | What it means |
|---|---|
| `Shibboleth Username Fix: Enters function local_shibboleth_username_fix_after_config()` | The `after_config` hook fired; a Shibboleth session was detected. |
| `User type: COLOURS alliance member from {CODE}` | The user's domain matched an alliance entry; username was prefixed with `CO-{CODE}_`. |
| `User domain: {domain}` | The domain part of the eppn, saved to `$SESSION->user_domain`. |
| `Also updated $_SERVER['{var}'] to: {localpart}` | A secondary eppn-related server variable was also rewritten. |
| `Shibboleth: User {username} authenticated via Shibboleth (original auth: {auth})` | An existing user was found and logged in via the cross-auth bridge. |

### Common issues

**Plugin does nothing / username still contains `@`**  
Verify that `$_SERVER['Shib-Session-ID']` is populated. If it is empty, the Shibboleth SP is not passing session data to PHP. Check your Apache/Nginx virtual host configuration and ensure `ShibUseEnvironment On` is set.

**Alliance user logs in but gets a local username (no `CO-` prefix)**  
The user's domain is not listed in `$alliance_domain_map`. Add it following the steps in *Adding a new partner institution*.

**Cross-auth bridge does not log the user in**  
The `allow_cross_auth` function only matches on `username`. If the existing account was created with the full eppn as username (e.g. `jsmith@univ-lemans.fr`), run the CLI migration script first to strip the domain from existing records.

**Duplicate username conflict during CLI migration**  
This means two separate accounts exist for the same local part — one with the full eppn and one without. These must be merged or one deleted manually before re-running the script with `--execute`.

---

## Privacy

This plugin does not store any personal data. It only reads and rewrites transient server variables during the authentication request. The `$SESSION->user_domain` value is a session-scoped variable and is not persisted to the database.

---

## License

This plugin is released under the [GNU General Public License v3 or later](http://www.gnu.org/copyleft/gpl.html).

# CHIPS Security Checkup

Emails the studio when something changes on a client site that could mean it
has been broken into.

Two layers:

1. **Right away**, from WordPress hooks: plugin activated / deactivated /
   installed / deleted, theme changed, user created or deleted, user made an
   administrator, an administrator's email or password changed, application
   password created, critical setting changed (admin email, site URL, open
   registration, default role). Each one is a checkbox under
   **Settings → CHIPS Security Checkup**.
2. **Once a day**, a checkup for changes that never went through WordPress:
   administrator accounts added straight to the database (or hidden from the
   Users screen), plugin / theme / mu-plugin folders that appeared on their own,
   PHP files in uploads, edits to `wp-config.php` and `.htaccess`, core files
   that don't match wordpress.org's checksums, and stray PHP files among core.

Anything already reported by a hook is folded into the baseline, so the daily
email only contains what WordPress *didn't* see happen.

## Handoff checklist

1. Install and activate (via CHIPS Plugin Manager once it's in the manifest).
2. Add to `wp-config.php`:

   ```php
   define( 'CHIPS_SC_ALERT_EMAIL', 'alerts@your-studio.example' );
   // Optional: a Slack incoming webhook, so alerts survive broken site email.
   define( 'CHIPS_SC_WEBHOOK_URL', 'https://hooks.slack.com/services/…' );
   ```

   Addresses set here can't be changed or removed from wp-admin. Without it,
   the "Studio email" field on the settings page is used, and failing that the
   site admin email.
3. Visit the settings page once (this installs the must-use loader), then click
   **Send a test alert**. If it doesn't arrive, set up SMTP first.
4. Optionally add a client contact under "Also send to".

## Why it's hard to switch off

- **Must-use loader.** The plugin copies
  `mu-loader/chips-security-checkup-loader.php` into `wp-content/mu-plugins/`.
  That file loads the plugin on every request, so "Deactivate" on the Plugins
  screen doesn't stop it (and still sends a "plugin deactivated" alert).
- Deleting the loader triggers an alert and it is put back.
- Deleting the plugin folder triggers a one-off alert from the loader.
- Changing the plugin's settings alerts everyone who was on the list *before*
  the change.

None of this stops someone with file access; it means they can't do it
quietly through wp-admin.

To remove it for real: delete the loader from `mu-plugins/` first, then the
plugin. To never install the loader on a site:
`define( 'CHIPS_SC_NO_MU_LOADER', true );`

## Filters

```php
// Watch more files for changes (label => absolute path). Off by default for
// theme files because every deploy would raise an alert.
add_filter( 'chips_sc_watched_files', function ( $files ) {
    $files['theme functions.php'] = get_stylesheet_directory() . '/functions.php';
    return $files;
} );
```

## Manifest entry for CHIPS Plugin Manager

```json
{
  "slug": "chips-security-checkup",
  "repo": "danielshields/chips-security-checkup",
  "main_file": "chips-security-checkup.php",
  "branch": "main",
  "name": "CHIPS Security Checkup",
  "description": "Emails the studio when plugins, themes, users or critical settings change, plus a daily check for changes WordPress didn't see happen.",
  "author": "CHIPS"
}
```

## Limits

- Detection, not prevention. Pair it with 2FA, `DISALLOW_FILE_EDIT`, and as few
  administrator accounts as possible.
- Alerts depend on the site being able to send email (or reach the webhook) and
  on WP-Cron running for the daily checkup.
- Single-site only; multisite isn't handled.
- The core check needs to reach `api.wordpress.org`; if it can't, that part is
  skipped for the day rather than reported as a change.

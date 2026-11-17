<?php
/**
 * Plugin Name: CHIPS Security Checkup
 * Description: Emails the studio when something changes that could mean a site is compromised: plugins, themes, users, critical settings, and a daily check for changes WordPress didn't see happen.
 * Version: 0.1.0
 * Author: CHIPS
 * License: GPL-2.0-or-later
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Loaded twice when the plugin is active *and* the must-use loader pulled it
// in first. include_once normally covers that; this covers symlinked installs.
if ( defined( 'CHIPS_SC_VERSION' ) ) {
	return;
}

define( 'CHIPS_SC_VERSION', '0.1.0' );
define( 'CHIPS_SC_FILE', __FILE__ );
define( 'CHIPS_SC_DIR', plugin_dir_path( __FILE__ ) );
define( 'CHIPS_SC_SLUG', 'chips-security-checkup' );

/**
 * Read a locked setting from (1) a wp-config constant, (2) an environment
 * variable. Anything set this way can't be changed from wp-admin, which is the
 * point: someone who has taken over an admin account can't redirect the alerts.
 *
 * Names: CHIPS_SC_ALERT_EMAIL, CHIPS_SC_WEBHOOK_URL.
 *
 * @param string $key Lowercase key, e.g. 'alert_email'.
 * @return string Empty string when not set.
 */
function chips_sc_config( $key ) {
	$name = 'CHIPS_SC_' . strtoupper( $key );

	if ( defined( $name ) ) {
		return (string) constant( $name );
	}

	$env = getenv( $name );

	return false === $env ? '' : (string) $env;
}

require_once CHIPS_SC_DIR . 'includes/class-chips-sc-settings.php';
require_once CHIPS_SC_DIR . 'includes/class-chips-sc-notifier.php';
require_once CHIPS_SC_DIR . 'includes/class-chips-sc-snapshot.php';
require_once CHIPS_SC_DIR . 'includes/class-chips-sc-events.php';
require_once CHIPS_SC_DIR . 'includes/class-chips-sc-loader.php';
require_once CHIPS_SC_DIR . 'includes/class-chips-sc-admin.php';

CHIPS_SC_Notifier::init();
CHIPS_SC_Events::init();
CHIPS_SC_Snapshot::init();
CHIPS_SC_Loader::init();
CHIPS_SC_Admin::init();

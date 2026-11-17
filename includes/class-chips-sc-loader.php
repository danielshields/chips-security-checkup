<?php
/**
 * Installs and maintains the must-use loader.
 *
 * The plugin itself lives in wp-content/plugins so CHIPS Plugin Manager can
 * update it like any other. The loader is a single file in mu-plugins that
 * requires it on every request, which means "Deactivate" on the Plugins
 * screen doesn't stop the monitoring.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHIPS_SC_Loader {

	const FILE        = 'chips-security-checkup-loader.php';
	const FLAG_OPTION = 'chips_sc_loader_installed';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'ensure' ) );
	}

	public static function source() {
		return CHIPS_SC_DIR . 'mu-loader/' . self::FILE;
	}

	public static function destination() {
		return WPMU_PLUGIN_DIR . '/' . self::FILE;
	}

	/**
	 * Opt out per site with `define( 'CHIPS_SC_NO_MU_LOADER', true );`.
	 */
	public static function wanted() {
		return ! ( defined( 'CHIPS_SC_NO_MU_LOADER' ) && CHIPS_SC_NO_MU_LOADER );
	}

	/**
	 * @return string 'ok', 'disabled' or 'failed'.
	 */
	public static function status() {
		if ( ! self::wanted() ) {
			return 'disabled';
		}

		$dest = self::destination();

		return is_readable( $dest ) && md5_file( $dest ) === md5_file( self::source() ) ? 'ok' : 'failed';
	}

	/**
	 * Install the loader if it's missing or out of date. If it was there
	 * before and has disappeared, that's worth an alert in its own right.
	 */
	public static function ensure() {
		// The plugin is running, so the "plugin was deleted" one-off can fire again in future.
		if ( get_option( 'chips_sc_missing_alerted' ) ) {
			delete_option( 'chips_sc_missing_alerted' );
		}

		if ( ! self::wanted() || 'ok' === self::status() ) {
			if ( self::wanted() && ! get_option( self::FLAG_OPTION ) ) {
				update_option( self::FLAG_OPTION, time(), false );
			}
			return;
		}

		$dest = self::destination();

		if ( ! file_exists( $dest ) && get_option( self::FLAG_OPTION ) ) {
			CHIPS_SC_Notifier::add(
				'loader_removed',
				'The Security Checkup must-use loader was deleted',
				array(
					'Someone removed wp-content/mu-plugins/' . self::FILE . '.',
					'It has been put back. Removing it is the first step to switching this plugin off.',
				),
				array( 'force' => true )
			);
		}

		$copied = wp_mkdir_p( WPMU_PLUGIN_DIR ) && @copy( self::source(), $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( $copied ) {
			update_option( self::FLAG_OPTION, time(), false );
		}
	}
}

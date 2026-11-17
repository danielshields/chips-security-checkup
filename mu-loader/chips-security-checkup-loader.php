<?php
/**
 * Plugin Name: CHIPS Security Checkup (loader)
 * Description: Keeps CHIPS Security Checkup running even when it is deactivated on the Plugins screen. Installed automatically by the plugin; delete this file first if you really want to remove it.
 * Version: 0.1.0
 * Author: CHIPS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$chips_sc_main = WP_PLUGIN_DIR . '/chips-security-checkup/chips-security-checkup.php';

if ( is_readable( $chips_sc_main ) ) {
	if ( ! defined( 'CHIPS_SC_MU_LOADED' ) ) {
		define( 'CHIPS_SC_MU_LOADED', true );
	}
	require_once $chips_sc_main;
	return;
}

/*
 * The plugin folder is gone but this loader is still here: somebody deleted
 * the plugin. Say so once, using only what this file can reach on its own.
 */
add_action(
	'init',
	function () {
		if ( get_option( 'chips_sc_missing_alerted' ) ) {
			return;
		}
		update_option( 'chips_sc_missing_alerted', time(), false );

		$to = array();

		foreach ( array( 'CHIPS_SC_ALERT_EMAIL' ) as $name ) {
			$value = defined( $name ) ? constant( $name ) : getenv( $name );
			if ( $value ) {
				$to[] = $value;
			}
		}

		$settings = get_option( 'chips_sc_settings' );
		if ( is_array( $settings ) ) {
			foreach ( array( 'studio_email', 'extra_emails' ) as $field ) {
				if ( ! empty( $settings[ $field ] ) ) {
					$to = array_merge( $to, preg_split( '/[\s,;]+/', $settings[ $field ] ) );
				}
			}
		}

		$to = array_unique( array_filter( array_map( 'sanitize_email', $to ), 'is_email' ) );
		if ( ! $to ) {
			$to = array( get_option( 'admin_email' ) );
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		wp_mail(
			$to,
			sprintf( '[CHIPS Security] %s: Security Checkup plugin was deleted', $host ),
			sprintf(
				"Site: %s\n\nThe CHIPS Security Checkup plugin folder has been removed from this site, so it is no longer being monitored.\n\nIf you didn't do this, treat the site as compromised and look at who has admin access.\n\nThis message is sent once.",
				home_url()
			)
		);
	}
);

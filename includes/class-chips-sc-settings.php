<?php
/**
 * Stored settings: who gets alerts and which events send one.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHIPS_SC_Settings {

	const OPTION = 'chips_sc_settings';

	/**
	 * Every event that can be switched on or off, in display order.
	 *
	 * @return array<string, array{label: string, default: bool, note?: string}>
	 */
	public static function events() {
		return array(
			'plugin_activated'    => array(
				'label'   => 'A plugin is activated',
				'default' => true,
			),
			'plugin_deactivated'  => array(
				'label'   => 'A plugin is deactivated',
				'default' => true,
			),
			'plugin_installed'    => array(
				'label'   => 'A plugin or theme is installed or deleted',
				'default' => true,
			),
			'plugin_updated'      => array(
				'label'   => 'A plugin or theme is updated',
				'default' => false,
				'note'    => 'Noisy on sites with auto-updates.',
			),
			'theme_switched'      => array(
				'label'   => 'The active theme is changed',
				'default' => true,
			),
			'user_created'        => array(
				'label'   => 'A user is created',
				'default' => true,
			),
			'user_deleted'        => array(
				'label'   => 'A user is deleted',
				'default' => true,
			),
			'admin_granted'       => array(
				'label'   => 'An existing user is made an administrator',
				'default' => true,
			),
			'admin_credentials'   => array(
				'label'   => 'An administrator\'s email or password changes',
				'default' => true,
			),
			'app_password'        => array(
				'label'   => 'An application password is created',
				'default' => true,
				'note'    => 'A common way to keep access after the real password is changed.',
			),
			'option_changed'      => array(
				'label'   => 'A critical setting changes (admin email, site URL, open registration, default role)',
				'default' => true,
			),
			'admin_login_new_ip'  => array(
				'label'   => 'An administrator logs in from an IP address not seen before',
				'default' => false,
				'note'    => 'Noisy for people who log in from phones or while travelling.',
			),
		);
	}

	public static function defaults() {
		$events = array();
		foreach ( self::events() as $key => $event ) {
			$events[ $key ] = $event['default'];
		}

		return array(
			'studio_email'  => '',
			'extra_emails'  => '',
			'webhook_url'   => '',
			'events'        => $events,
			'daily_checkup' => true,
			'heartbeat'     => false,
		);
	}

	/**
	 * @param array|null $stored Use this instead of the saved option (for old/new comparisons).
	 * @return array
	 */
	public static function get( $stored = null ) {
		if ( null === $stored ) {
			$stored = get_option( self::OPTION, array() );
		}
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$defaults           = self::defaults();
		$settings           = array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
		$settings['events'] = array_merge(
			$defaults['events'],
			array_intersect_key( is_array( $settings['events'] ) ? $settings['events'] : array(), $defaults['events'] )
		);

		return $settings;
	}

	public static function is_enabled( $event ) {
		$settings = self::get();

		return ! empty( $settings['events'][ $event ] );
	}

	/**
	 * Everyone who should get an alert.
	 *
	 * The wp-config address always comes first and can't be removed from
	 * wp-admin. If nothing at all is configured, fall back to the site admin
	 * email so an alert is never silently dropped.
	 *
	 * @param array|null $stored Settings array to read instead of the saved one.
	 * @return string[]
	 */
	public static function recipients( $stored = null ) {
		$settings = self::get( $stored );

		$list = array_merge(
			self::parse_emails( chips_sc_config( 'alert_email' ) ),
			self::parse_emails( $settings['studio_email'] ),
			self::parse_emails( $settings['extra_emails'] )
		);

		if ( ! $list ) {
			$list = self::parse_emails( get_option( 'admin_email' ) );
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * True when alerts are only going to the fallback admin email.
	 */
	public static function has_no_recipients() {
		$settings = self::get();

		return ! self::parse_emails( chips_sc_config( 'alert_email' ) )
			&& ! self::parse_emails( $settings['studio_email'] )
			&& ! self::parse_emails( $settings['extra_emails'] );
	}

	public static function webhook_url() {
		$locked = chips_sc_config( 'webhook_url' );
		if ( $locked ) {
			return $locked;
		}

		$settings = self::get();

		return (string) $settings['webhook_url'];
	}

	/**
	 * @param string $raw Comma, semicolon or whitespace separated addresses.
	 * @return string[]
	 */
	public static function parse_emails( $raw ) {
		$out = array();

		foreach ( preg_split( '/[\s,;]+/', (string) $raw ) as $piece ) {
			$email = sanitize_email( $piece );
			if ( $email && is_email( $email ) ) {
				$out[] = strtolower( $email );
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * @param mixed $raw Submitted form values.
	 * @return array
	 */
	public static function sanitize( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = self::defaults();

		$studio              = self::parse_emails( isset( $raw['studio_email'] ) ? $raw['studio_email'] : '' );
		$out['studio_email'] = $studio ? $studio[0] : '';
		$out['extra_emails'] = implode( ', ', self::parse_emails( isset( $raw['extra_emails'] ) ? $raw['extra_emails'] : '' ) );

		$url                = isset( $raw['webhook_url'] ) ? esc_url_raw( trim( (string) $raw['webhook_url'] ), array( 'https' ) ) : '';
		$out['webhook_url'] = $url;

		// An unchecked checkbox submits nothing, so absence means off.
		foreach ( array_keys( $out['events'] ) as $key ) {
			$out['events'][ $key ] = ! empty( $raw['events'][ $key ] );
		}

		$out['daily_checkup'] = ! empty( $raw['daily_checkup'] );
		$out['heartbeat']     = ! empty( $raw['heartbeat'] );

		return $out;
	}
}

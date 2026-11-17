<?php
/**
 * WordPress hooks that raise an alert the moment something happens.
 *
 * Each handler that successfully queues an alert also patches the stored
 * snapshot, so the daily checkup only reports changes that did NOT come
 * through here (a user row inserted straight into the database, a plugin
 * folder dropped in over FTP) rather than repeating what was already emailed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHIPS_SC_Events {

	/** Options whose change is worth an alert. */
	const WATCHED_OPTIONS = array(
		'admin_email'        => 'Site admin email',
		'siteurl'            => 'WordPress address (siteurl)',
		'home'               => 'Site address (home)',
		'users_can_register' => 'Anyone can register',
		'default_role'       => 'Default role for new users',
	);

	const IP_META = 'chips_sc_known_ips';

	public static function init() {
		add_action( 'activated_plugin', array( __CLASS__, 'plugin_activated' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'plugin_deactivated' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'upgrader_done' ), 10, 2 );
		add_action( 'deleted_plugin', array( __CLASS__, 'plugin_deleted' ), 10, 2 );
		add_action( 'deleted_theme', array( __CLASS__, 'theme_deleted' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'theme_switched' ), 10, 3 );

		add_action( 'user_register', array( __CLASS__, 'user_created' ) );
		add_action( 'deleted_user', array( __CLASS__, 'user_deleted' ), 10, 3 );
		add_action( 'set_user_role', array( __CLASS__, 'role_set' ), 10, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'role_added' ), 10, 2 );
		add_action( 'profile_update', array( __CLASS__, 'profile_updated' ), 10, 2 );
		add_action( 'after_password_reset', array( __CLASS__, 'password_reset' ) );
		add_action( 'wp_create_application_password', array( __CLASS__, 'app_password_created' ), 10, 2 );
		add_action( 'wp_login', array( __CLASS__, 'logged_in' ), 10, 2 );

		add_action( 'updated_option', array( __CLASS__, 'option_updated' ), 10, 3 );
		add_action( 'update_option_' . CHIPS_SC_Settings::OPTION, array( __CLASS__, 'own_settings_changed' ), 10, 2 );
		// The first save creates the option, which fires this instead.
		add_action( 'add_option_' . CHIPS_SC_Settings::OPTION, array( __CLASS__, 'own_settings_added' ), 10, 2 );
	}

	public static function own_settings_added( $option, $value ) {
		self::own_settings_changed( array(), $value );
	}

	/* ---------------------------------------------------------------------
	 * Plugins and themes
	 * ------------------------------------------------------------------- */

	public static function plugin_activated( $plugin ) {
		$queued = CHIPS_SC_Notifier::add(
			'plugin_activated',
			'Plugin activated: ' . self::plugin_label( $plugin ),
			array(),
			array( 'review' => admin_url( 'plugins.php' ) )
		);

		if ( $queued ) {
			CHIPS_SC_Snapshot::patch_set( 'active_plugins', array( $plugin ), array() );
		}
	}

	public static function plugin_deactivated( $plugin ) {
		$lines = array();
		if ( plugin_basename( CHIPS_SC_FILE ) === $plugin ) {
			$lines[] = defined( 'CHIPS_SC_MU_LOADED' )
				? 'That is this plugin. The must-use loader keeps it running, so monitoring continues.'
				: 'That is this plugin. Monitoring stops after this message.';
		}

		$queued = CHIPS_SC_Notifier::add(
			'plugin_deactivated',
			'Plugin deactivated: ' . self::plugin_label( $plugin ),
			$lines,
			array( 'review' => admin_url( 'plugins.php' ) )
		);

		if ( $queued ) {
			CHIPS_SC_Snapshot::patch_set( 'active_plugins', array(), array( $plugin ) );
		}
	}

	/**
	 * Installs and updates done through wp-admin, WP-CLI or auto-update.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $extra    What it was doing.
	 */
	public static function upgrader_done( $upgrader, $extra ) {
		$type   = isset( $extra['type'] ) ? $extra['type'] : '';
		$action = isset( $extra['action'] ) ? $extra['action'] : '';

		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return;
		}

		$noun   = 'plugin' === $type ? 'Plugin' : 'Theme';
		$review = admin_url( 'plugin' === $type ? 'plugins.php' : 'themes.php' );

		if ( 'install' === $action ) {
			$result = isset( $upgrader->result ) ? $upgrader->result : null;
			if ( ! is_array( $result ) || empty( $result['destination_name'] ) ) {
				return; // The install failed; nothing landed on disk.
			}

			$folder = (string) $result['destination_name'];
			$label  = $folder;

			if ( 'plugin' === $type && method_exists( $upgrader, 'plugin_info' ) ) {
				$file = $upgrader->plugin_info();
				if ( $file ) {
					$label = self::plugin_label( $file );
				}
			} elseif ( 'theme' === $type && method_exists( $upgrader, 'theme_info' ) ) {
				$theme = $upgrader->theme_info();
				if ( $theme ) {
					$label = $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' );
				}
			}

			$queued = CHIPS_SC_Notifier::add(
				'plugin_installed',
				sprintf( '%s installed: %s', $noun, $label ),
				array( sprintf( 'Folder: wp-content/%ss/%s', $type, $folder ) ),
				array( 'review' => $review )
			);

			if ( $queued ) {
				CHIPS_SC_Snapshot::patch_set( $type . '_folders', array( $folder ), array() );
			}

			return;
		}

		if ( 'update' === $action ) {
			$key   = $type . 's';
			$items = array();

			if ( ! empty( $extra[ $key ] ) ) {
				$items = (array) $extra[ $key ];
			} elseif ( ! empty( $extra[ $type ] ) ) {
				$items = array( $extra[ $type ] );
			}

			foreach ( $items as $item ) {
				CHIPS_SC_Notifier::add(
					'plugin_updated',
					sprintf( '%s updated: %s', $noun, 'plugin' === $type ? self::plugin_label( $item ) : $item ),
					array(),
					array( 'review' => $review )
				);
			}
		}
	}

	public static function plugin_deleted( $plugin_file, $deleted ) {
		if ( ! $deleted ) {
			return;
		}

		$folder = false !== strpos( $plugin_file, '/' ) ? dirname( $plugin_file ) : $plugin_file;

		$queued = CHIPS_SC_Notifier::add(
			'plugin_installed',
			'Plugin deleted: ' . $plugin_file,
			array(),
			array( 'review' => admin_url( 'plugins.php' ) )
		);

		if ( $queued ) {
			CHIPS_SC_Snapshot::patch_set( 'plugin_folders', array(), array( $folder ) );
		}
	}

	public static function theme_deleted( $stylesheet, $deleted ) {
		if ( ! $deleted ) {
			return;
		}

		$queued = CHIPS_SC_Notifier::add(
			'plugin_installed',
			'Theme deleted: ' . $stylesheet,
			array(),
			array( 'review' => admin_url( 'themes.php' ) )
		);

		if ( $queued ) {
			CHIPS_SC_Snapshot::patch_set( 'theme_folders', array(), array( $stylesheet ) );
		}
	}

	public static function theme_switched( $new_name, $new_theme = null, $old_theme = null ) {
		$lines = array();
		if ( $old_theme instanceof WP_Theme ) {
			$lines[] = 'Was: ' . $old_theme->get( 'Name' ) . ' (' . $old_theme->get_stylesheet() . ')';
		}
		if ( $new_theme instanceof WP_Theme ) {
			$lines[] = 'Now: ' . $new_theme->get( 'Name' ) . ' (' . $new_theme->get_stylesheet() . ')';
		}

		$queued = CHIPS_SC_Notifier::add(
			'theme_switched',
			'Theme changed to: ' . $new_name,
			$lines,
			array( 'review' => admin_url( 'themes.php' ) )
		);

		if ( $queued ) {
			CHIPS_SC_Snapshot::patch_map( 'theme', CHIPS_SC_Snapshot::current_theme(), array() );
		}
	}

	/* ---------------------------------------------------------------------
	 * Users
	 * ------------------------------------------------------------------- */

	public static function user_created( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$is_admin = in_array( 'administrator', (array) $user->roles, true );
		$roles    = $user->roles ? implode( ', ', $user->roles ) : 'no role';

		$queued = CHIPS_SC_Notifier::add(
			'user_created',
			sprintf( 'User created: %s <%s>, role: %s', $user->user_login, $user->user_email, $roles ),
			$is_admin ? array( 'This account has full administrator access.' ) : array(),
			array( 'review' => admin_url( 'users.php' ) )
		);

		if ( $queued ) {
			// set_user_role fired a moment ago inside wp_insert_user(); one line is enough.
			CHIPS_SC_Notifier::drop( 'admin_granted', $user_id );

			if ( $is_admin ) {
				CHIPS_SC_Snapshot::patch_map( 'admins', array( CHIPS_SC_Snapshot::admin_key( $user ) => $user->user_email ), array() );
			}
		}
	}

	public static function user_deleted( $user_id, $reassign = null, $user = null ) {
		$label = $user instanceof WP_User
			? sprintf( '%s <%s>', $user->user_login, $user->user_email )
			: 'user ID ' . (int) $user_id;

		$queued = CHIPS_SC_Notifier::add(
			'user_deleted',
			'User deleted: ' . $label,
			array(),
			array( 'review' => admin_url( 'users.php' ) )
		);

		if ( $queued && $user instanceof WP_User ) {
			CHIPS_SC_Snapshot::patch_map( 'admins', array(), array( CHIPS_SC_Snapshot::admin_key( $user ) ) );
		}
	}

	public static function role_set( $user_id, $role, $old_roles = array() ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$was_admin = in_array( 'administrator', (array) $old_roles, true );

		if ( ! self::role_is_admin( $role ) ) {
			// A demotion isn't an alert, but keep the snapshot in step so the
			// daily checkup doesn't report it as an unexplained change.
			if ( $was_admin && CHIPS_SC_Settings::is_enabled( 'admin_granted' ) ) {
				CHIPS_SC_Snapshot::patch_map( 'admins', array(), array( CHIPS_SC_Snapshot::admin_key( $user ) ) );
			}
			return;
		}

		if ( $was_admin ) {
			return;
		}

		self::admin_granted( $user, $role, $old_roles ? 'Previous role: ' . implode( ', ', (array) $old_roles ) : '' );
	}

	public static function role_added( $user_id, $role ) {
		$user = get_userdata( $user_id );

		if ( $user && self::role_is_admin( $role ) ) {
			self::admin_granted( $user, $role, 'Added as an extra role.' );
		}
	}

	private static function admin_granted( $user, $role, $detail ) {
		// WP_User::set_role() fires add_user_role and then set_user_role for
		// the same change; keep only the later, more informative one.
		CHIPS_SC_Notifier::drop( 'admin_granted', $user->ID );

		$queued = CHIPS_SC_Notifier::add(
			'admin_granted',
			sprintf( 'User made an administrator: %s <%s>', $user->user_login, $user->user_email ),
			array( $detail, 'administrator' === $role ? '' : 'Role: ' . $role . ' (can manage settings)' ),
			array(
				'ref'    => $user->ID,
				'review' => admin_url( 'users.php?role=administrator' ),
			)
		);

		if ( $queued && 'administrator' === $role ) {
			CHIPS_SC_Snapshot::patch_map( 'admins', array( CHIPS_SC_Snapshot::admin_key( $user ) => $user->user_email ), array() );
		}
	}

	private static function role_is_admin( $role ) {
		if ( 'administrator' === $role ) {
			return true;
		}

		$object = get_role( $role );

		return $object && $object->has_cap( 'manage_options' );
	}

	public static function profile_updated( $user_id, $old ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! $old instanceof WP_User || ! user_can( $user, 'manage_options' ) ) {
			return;
		}

		$review = array( 'review' => admin_url( 'user-edit.php?user_id=' . (int) $user_id ) );

		if ( strtolower( $old->user_email ) !== strtolower( $user->user_email ) ) {
			$queued = CHIPS_SC_Notifier::add(
				'admin_credentials',
				'Administrator email changed: ' . $user->user_login,
				array( 'Was: ' . $old->user_email, 'Now: ' . $user->user_email ),
				$review
			);

			if ( $queued && in_array( 'administrator', (array) $user->roles, true ) ) {
				CHIPS_SC_Snapshot::patch_map( 'admins', array( CHIPS_SC_Snapshot::admin_key( $user ) => $user->user_email ), array() );
			}
		}

		if ( $old->user_pass !== $user->user_pass ) {
			CHIPS_SC_Notifier::add( 'admin_credentials', 'Administrator password changed: ' . $user->user_login, array(), $review );
		}
	}

	public static function password_reset( $user ) {
		if ( $user instanceof WP_User && user_can( $user, 'manage_options' ) ) {
			CHIPS_SC_Notifier::add(
				'admin_credentials',
				'Administrator password reset by email link: ' . $user->user_login,
				array( 'Reset link was sent to: ' . $user->user_email )
			);
		}
	}

	public static function app_password_created( $user_id, $item ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		CHIPS_SC_Notifier::add(
			'app_password',
			sprintf( 'Application password created for %s', $user->user_login ),
			array(
				'Name: ' . ( isset( $item['name'] ) ? $item['name'] : '(none)' ),
				'It gives API access as this user without 2FA until it is revoked.',
			),
			array( 'review' => admin_url( 'user-edit.php?user_id=' . (int) $user_id . '#application-passwords-section' ) )
		);
	}

	public static function logged_in( $user_login, $user = null ) {
		if ( ! $user instanceof WP_User || ! user_can( $user, 'manage_options' ) || empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return;
		}

		$ip    = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		$hash  = wp_hash( $ip );
		$known = get_user_meta( $user->ID, self::IP_META, true );
		$known = is_array( $known ) ? $known : array();

		if ( in_array( $hash, $known, true ) ) {
			return;
		}

		// Nothing to compare against on the first recorded login, so just start the list.
		if ( $known ) {
			CHIPS_SC_Notifier::add(
				'admin_login_new_ip',
				sprintf( 'Administrator %s logged in from a new IP address', $user->user_login ),
				array( 'IP: ' . $ip )
			);
		}

		$known[] = $hash;
		update_user_meta( $user->ID, self::IP_META, array_slice( $known, -20 ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	public static function option_updated( $option, $old, $new ) {
		if ( ! isset( self::WATCHED_OPTIONS[ $option ] ) ) {
			return;
		}

		// phpcs:ignore Universal.Operators.StrictComparisons -- '1' and 1 are the same setting.
		if ( $old == $new ) {
			return;
		}

		CHIPS_SC_Notifier::add(
			'option_changed',
			'Setting changed: ' . self::WATCHED_OPTIONS[ $option ],
			array(
				'Was: ' . self::printable( $old ),
				'Now: ' . self::printable( $new ),
			),
			array( 'review' => admin_url( 'options-general.php' ) )
		);
	}

	/**
	 * Changes to this plugin's own settings always alert, and the alert also
	 * goes to whoever was on the list *before* the change, so taking someone
	 * off the list can't be done quietly.
	 */
	public static function own_settings_changed( $old, $new ) {
		$before = CHIPS_SC_Settings::get( is_array( $old ) ? $old : array() );
		$after  = CHIPS_SC_Settings::get( is_array( $new ) ? $new : array() );
		$lines  = array();

		foreach ( array( 'studio_email' => 'Studio email', 'extra_emails' => 'Additional recipients' ) as $key => $label ) {
			if ( $before[ $key ] !== $after[ $key ] ) {
				$lines[] = sprintf( '%s: %s -> %s', $label, self::printable( $before[ $key ] ), self::printable( $after[ $key ] ) );
			}
		}

		if ( $before['webhook_url'] !== $after['webhook_url'] ) {
			$lines[] = 'Webhook URL: ' . ( $after['webhook_url'] ? 'changed' : 'removed' );
		}

		$events = CHIPS_SC_Settings::events();
		foreach ( $after['events'] as $key => $on ) {
			if ( (bool) $before['events'][ $key ] !== (bool) $on ) {
				$lines[] = sprintf( 'Alert turned %s: %s', $on ? 'on' : 'OFF', $events[ $key ]['label'] );
			}
		}

		foreach ( array( 'daily_checkup' => 'Daily checkup', 'heartbeat' => 'Weekly all-quiet email' ) as $key => $label ) {
			if ( (bool) $before[ $key ] !== (bool) $after[ $key ] ) {
				$lines[] = sprintf( '%s turned %s', $label, $after[ $key ] ? 'on' : 'OFF' );
			}
		}

		if ( ! $lines ) {
			return;
		}

		CHIPS_SC_Notifier::add(
			'settings_changed',
			'Security Checkup settings were changed',
			$lines,
			array(
				'force'      => true,
				'recipients' => CHIPS_SC_Settings::recipients( $before ),
				'review'     => admin_url( 'options-general.php?page=' . CHIPS_SC_SLUG ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * "Name 1.2.3 (folder/file.php)" for a plugin file, or just the path.
	 */
	public static function plugin_label( $plugin_file ) {
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;

		if ( is_readable( $path ) && is_file( $path ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$data = get_plugin_data( $path, false, false );

			if ( ! empty( $data['Name'] ) ) {
				return trim( $data['Name'] . ' ' . $data['Version'] ) . ' (' . $plugin_file . ')';
			}
		}

		return (string) $plugin_file;
	}

	private static function printable( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( is_scalar( $value ) ) {
			return '' === (string) $value ? '(empty)' : (string) $value;
		}

		return '(complex value)';
	}
}

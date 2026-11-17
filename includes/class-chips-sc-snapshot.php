<?php
/**
 * The daily checkup: record what the site looks like, compare with yesterday.
 *
 * Hooks only see changes made *through* WordPress. A user row inserted straight
 * into the database, a file dropped in through a vulnerable plugin, or a core
 * file with a few lines appended never fires one. This catches those.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHIPS_SC_Snapshot {

	const OPTION         = 'chips_sc_snapshot';
	const CRON_DAILY     = 'chips_sc_daily_checkup';
	const CRON_HEARTBEAT = 'chips_sc_heartbeat';

	/** Stop listing after this many entries in any one section. */
	const LIST_CAP = 200;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON_DAILY, array( __CLASS__, 'run' ) );
		add_action( self::CRON_HEARTBEAT, array( __CLASS__, 'heartbeat' ) );
	}

	/**
	 * What gets recorded.
	 *
	 * "always" sections are suspicious in themselves, so they're reported in
	 * full on the very first run. The rest are only a baseline until they change.
	 *
	 * @return array<string, array{label: string, type: string, always: bool}>
	 */
	public static function sections() {
		return array(
			'admins'         => array(
				'label'  => 'Administrator accounts (read straight from the database)',
				'type'   => 'map',
				'always' => false,
			),
			'hidden_admins'  => array(
				'label'  => 'Administrators in the database that WordPress is being told to hide',
				'type'   => 'set',
				'always' => true,
			),
			'active_plugins' => array(
				'label'  => 'Active plugins',
				'type'   => 'set',
				'always' => false,
			),
			'plugin_folders' => array(
				'label'  => 'Contents of wp-content/plugins',
				'type'   => 'set',
				'always' => false,
			),
			'mu_plugins'     => array(
				'label'  => 'Contents of wp-content/mu-plugins',
				'type'   => 'set',
				'always' => false,
			),
			'dropins'        => array(
				'label'  => 'PHP files directly in wp-content',
				'type'   => 'set',
				'always' => false,
			),
			'theme'          => array(
				'label'  => 'Active theme',
				'type'   => 'map',
				'always' => false,
			),
			'theme_folders'  => array(
				'label'  => 'Contents of wp-content/themes',
				'type'   => 'set',
				'always' => false,
			),
			'files'          => array(
				'label'  => 'Watched files',
				'type'   => 'map',
				'always' => false,
			),
			'uploads_php'    => array(
				'label'  => 'PHP files in the uploads folder (there should be none)',
				'type'   => 'set',
				'always' => true,
			),
			'core_modified'  => array(
				'label'  => 'WordPress core files that differ from the official release',
				'type'   => 'set',
				'always' => true,
			),
			'core_unknown'   => array(
				'label'  => 'PHP files sitting among WordPress core that are not part of WordPress',
				'type'   => 'set',
				'always' => true,
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Scheduling
	 * ------------------------------------------------------------------- */

	/**
	 * Keep the cron events in line with the settings. Done on init rather than
	 * an activation hook because must-use loading never fires one.
	 */
	public static function schedule() {
		$settings = CHIPS_SC_Settings::get();

		// First checkup a few minutes after install, so the baseline exists early.
		self::sync_event( self::CRON_DAILY, 'daily', $settings['daily_checkup'], 5 * MINUTE_IN_SECONDS );
		self::sync_event( self::CRON_HEARTBEAT, 'weekly', $settings['heartbeat'], WEEK_IN_SECONDS );
	}

	private static function sync_event( $hook, $recurrence, $wanted, $first_delay ) {
		$next = wp_next_scheduled( $hook );

		if ( $wanted && ! $next ) {
			wp_schedule_event( time() + $first_delay, $recurrence, $hook );
		} elseif ( ! $wanted && $next ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/* ---------------------------------------------------------------------
	 * Running
	 * ------------------------------------------------------------------- */

	/**
	 * Take a snapshot, compare with the last one, alert on differences.
	 *
	 * @return string[] Report lines; empty when nothing changed.
	 */
	public static function run() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$stored   = get_option( self::OPTION );
		$previous = is_array( $stored ) && isset( $stored['data'] ) && is_array( $stored['data'] ) ? $stored['data'] : null;
		$current  = self::collect();

		// A section that couldn't be checked this time (say, wordpress.org was
		// unreachable) keeps its previous value instead of looking "all removed".
		foreach ( $current as $key => $value ) {
			if ( null === $value ) {
				if ( null !== $previous && isset( $previous[ $key ] ) ) {
					$current[ $key ] = $previous[ $key ];
				} else {
					unset( $current[ $key ] );
				}
			}
		}

		$report = self::diff( $previous, $current );

		update_option(
			self::OPTION,
			array(
				'time'    => time(),
				'changes' => count( $report ),
				'data'    => $current,
			),
			false
		);

		if ( $report ) {
			CHIPS_SC_Notifier::add(
				'checkup',
				null === $previous ? 'First checkup found something to look at' : 'Daily checkup found changes WordPress did not report',
				$report,
				array(
					'force'  => true,
					'review' => admin_url( 'options-general.php?page=' . CHIPS_SC_SLUG ),
				)
			);
		}

		return $report;
	}

	/**
	 * @param array|null $previous Last snapshot data, or null on the first run.
	 * @param array      $current  This snapshot.
	 * @return string[]
	 */
	public static function diff( $previous, $current ) {
		$lines = array();

		foreach ( self::sections() as $key => $section ) {
			if ( ! isset( $current[ $key ] ) ) {
				continue;
			}

			if ( null === $previous || ! isset( $previous[ $key ] ) ) {
				if ( ! $section['always'] ) {
					continue; // Baseline only.
				}
				$before = array();
			} else {
				$before = (array) $previous[ $key ];
			}

			$now     = (array) $current[ $key ];
			$changes = array();

			if ( 'set' === $section['type'] ) {
				foreach ( array_diff( $now, $before ) as $item ) {
					$changes[] = '+ ' . $item;
				}
				foreach ( array_diff( $before, $now ) as $item ) {
					$changes[] = '- ' . $item . ' (gone)';
				}
			} else {
				foreach ( $now as $name => $value ) {
					if ( ! array_key_exists( $name, $before ) ) {
						$changes[] = '+ ' . self::describe( $key, $name, $value );
					} elseif ( $before[ $name ] !== $value ) {
						$changes[] = '~ ' . self::describe( $key, $name, $value, $before[ $name ] );
					}
				}
				foreach ( array_diff_key( $before, $now ) as $name => $value ) {
					$changes[] = '- ' . $name . ' (gone)';
				}
			}

			if ( $changes ) {
				$lines[] = $section['label'] . ':';
				foreach ( array_slice( $changes, 0, 40 ) as $change ) {
					$lines[] = '  ' . $change;
				}
				if ( count( $changes ) > 40 ) {
					$lines[] = sprintf( '  ... and %d more', count( $changes ) - 40 );
				}
			}
		}

		return $lines;
	}

	private static function describe( $section, $name, $value, $old = null ) {
		if ( 'files' === $section ) {
			$path  = self::watched_files();
			$mtime = isset( $path[ $name ] ) && is_readable( $path[ $name ] ) ? gmdate( 'Y-m-d H:i', (int) filemtime( $path[ $name ] ) ) . ' UTC' : 'unknown';

			return null === $old
				? sprintf( '%s appeared (file dated %s)', $name, $mtime )
				: sprintf( '%s was modified (file dated %s)', $name, $mtime );
		}

		return null === $old
			? sprintf( '%s: %s', $name, $value )
			: sprintf( '%s: %s -> %s', $name, $old, $value );
	}

	/* ---------------------------------------------------------------------
	 * Keeping the baseline in step with alerts already sent
	 * ------------------------------------------------------------------- */

	public static function patch_set( $key, $add, $remove ) {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || ! isset( $stored['data'][ $key ] ) ) {
			return;
		}

		$set = array_diff( (array) $stored['data'][ $key ], (array) $remove );
		$set = array_values( array_unique( array_merge( $set, (array) $add ) ) );
		sort( $set );

		$stored['data'][ $key ] = $set;
		update_option( self::OPTION, $stored, false );
	}

	public static function patch_map( $key, $set, $unset ) {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || ! isset( $stored['data'][ $key ] ) ) {
			return;
		}

		$map = array_diff_key( (array) $stored['data'][ $key ], array_flip( (array) $unset ) );
		$map = array_merge( $map, (array) $set );
		ksort( $map );

		$stored['data'][ $key ] = $map;
		update_option( self::OPTION, $stored, false );
	}

	public static function admin_key( $user ) {
		return sprintf( '%s (ID %d)', $user->user_login, $user->ID );
	}

	public static function current_theme() {
		return array(
			'stylesheet' => (string) get_option( 'stylesheet' ),
			'template'   => (string) get_option( 'template' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Collecting
	 * ------------------------------------------------------------------- */

	/**
	 * @return array<string, array|null> null means "couldn't check this time".
	 */
	public static function collect() {
		list( $admins, $hidden ) = self::admins();
		list( $modified, $unknown ) = self::core_check();

		return array(
			'admins'         => $admins,
			'hidden_admins'  => $hidden,
			'active_plugins' => self::active_plugins(),
			'plugin_folders' => self::list_dir( WP_PLUGIN_DIR ),
			'mu_plugins'     => self::list_dir( WPMU_PLUGIN_DIR ),
			'dropins'        => self::list_dir( WP_CONTENT_DIR, true ),
			'theme'          => self::current_theme(),
			'theme_folders'  => self::list_dir( get_theme_root() ),
			'files'          => self::file_hashes(),
			'uploads_php'    => self::uploads_php(),
			'core_modified'  => $modified,
			'core_unknown'   => $unknown,
		);
	}

	/**
	 * Administrators as the database has them, bypassing every WP_User_Query
	 * filter, plus any that the normal query doesn't return. Malware that
	 * creates a backdoor admin usually also filters it out of the Users screen.
	 *
	 * @return array{0: array<string,string>, 1: string[]}
	 */
	private static function admins() {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.user_login, u.user_email
				 FROM {$wpdb->users} u
				 INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID
				 WHERE m.meta_key = %s AND m.meta_value LIKE %s
				 ORDER BY u.ID",
				$wpdb->get_blog_prefix() . 'capabilities',
				'%' . $wpdb->esc_like( '"administrator"' ) . '%'
			)
		);

		$visible = array_map(
			'intval',
			get_users(
				array(
					'role'   => 'administrator',
					'fields' => 'ID',
					'number' => -1,
				)
			)
		);

		$admins = array();
		$hidden = array();

		foreach ( (array) $rows as $row ) {
			$key            = self::admin_key( $row );
			$admins[ $key ] = (string) $row->user_email;

			if ( ! in_array( (int) $row->ID, $visible, true ) ) {
				$hidden[] = sprintf( '%s <%s>', $key, $row->user_email );
			}
		}

		ksort( $admins );

		return array( $admins, $hidden );
	}

	/**
	 * Read from the database rather than get_option(), which can be filtered
	 * to hide a plugin from the Plugins screen.
	 */
	private static function active_plugins() {
		global $wpdb;

		$raw = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins' )
		);

		$list = maybe_unserialize( $raw );
		$list = is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : array();
		sort( $list );

		return $list;
	}

	/**
	 * @param string $dir      Directory to list.
	 * @param bool   $php_only Only .php files, no folders.
	 * @return string[]
	 */
	private static function list_dir( $dir, $php_only = false ) {
		$out = array();

		if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return $out;
		}

		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '' === $entry || '.' === $entry[0] || 'index.php' === $entry ) {
				continue;
			}

			$is_dir = is_dir( $dir . '/' . $entry );

			if ( $php_only && ( $is_dir || ! self::is_php( $entry ) ) ) {
				continue;
			}

			$out[] = $is_dir ? $entry . '/' : $entry;
		}

		sort( $out );

		return $out;
	}

	/**
	 * Files whose content should basically never change between deploys.
	 *
	 * @return array<string,string> Label => absolute path.
	 */
	public static function watched_files() {
		$config = file_exists( ABSPATH . 'wp-config.php' ) ? ABSPATH . 'wp-config.php' : dirname( ABSPATH ) . '/wp-config.php';

		$files = array(
			'wp-config.php' => $config,
			'.htaccess'     => ABSPATH . '.htaccess',
		);

		/**
		 * Add files to watch, e.g. the theme's functions.php on a site that is
		 * no longer being developed. Label => absolute path.
		 */
		return (array) apply_filters( 'chips_sc_watched_files', $files );
	}

	private static function file_hashes() {
		$out = array();

		foreach ( self::watched_files() as $label => $path ) {
			if ( is_readable( $path ) && is_file( $path ) ) {
				$out[ $label ] = md5_file( $path );
			}
		}

		ksort( $out );

		return $out;
	}

	private static function is_php( $name ) {
		return (bool) preg_match( '/\.(php\d?|phtml|phar)$/i', $name );
	}

	/**
	 * Nothing in uploads should be executable. The one common exception is an
	 * empty "Silence is golden" index.php, which is ignored.
	 */
	private static function uploads_php() {
		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';
		$found   = array();

		foreach ( self::php_files_in( $base ) as $path ) {
			if ( 'index.php' === basename( $path ) && self::is_empty_php( $path ) ) {
				continue;
			}

			$found[] = ltrim( str_replace( $base, '', $path ), '/' );

			if ( count( $found ) >= self::LIST_CAP ) {
				break;
			}
		}

		sort( $found );

		return $found;
	}

	private static function is_empty_php( $path ) {
		if ( filesize( $path ) > 400 ) {
			return false;
		}

		$code = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$code = preg_replace( '#/\*.*?\*/|//[^\n]*|\#[^\n]*#s', '', $code );
		$code = preg_replace( '/\s+/', '', $code );

		return in_array( $code, array( '<?php', '<?php?>', '' ), true );
	}

	/**
	 * @param string $dir Directory to walk.
	 * @return string[] Absolute paths of PHP-like files.
	 */
	private static function php_files_in( $dir ) {
		$out = array();

		if ( ! $dir || ! is_dir( $dir ) ) {
			return $out;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() && self::is_php( $file->getFilename() ) ) {
					$out[] = $file->getPathname();
				}
			}
		} catch ( Exception $e ) {
			return $out;
		}

		return $out;
	}

	/**
	 * Compare core against the checksums wordpress.org publishes for this
	 * exact version, and look for PHP files that aren't in that list at all.
	 *
	 * @return array{0: string[]|null, 1: string[]|null} Modified, unknown. Nulls if it couldn't be checked.
	 */
	private static function core_check() {
		// Straight from the file: the $wp_version global can be filtered or overwritten.
		$wp_version       = '';
		$wp_local_package = '';
		include ABSPATH . WPINC . '/version.php';

		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$checksums = get_core_checksums( $wp_version, $wp_local_package ? $wp_local_package : 'en_US' );

		if ( ! is_array( $checksums ) || ! $checksums ) {
			return array( null, null );
		}

		$modified = array();

		foreach ( $checksums as $file => $md5 ) {
			// Bundled themes and plugins get updated separately; not core's business.
			if ( 0 === strpos( $file, 'wp-content/' ) || ! self::is_php( $file ) || 'wp-config-sample.php' === $file ) {
				continue;
			}

			$path = ABSPATH . $file;

			if ( ! file_exists( $path ) ) {
				$modified[] = $file . ' (missing)';
			} elseif ( md5_file( $path ) !== $md5 ) {
				$modified[] = $file . ' (modified ' . gmdate( 'Y-m-d', (int) filemtime( $path ) ) . ')';
			}
		}

		$unknown = array();
		$root    = untrailingslashit( ABSPATH );

		$candidates = array_merge(
			self::php_files_in( $root . '/wp-admin' ),
			self::php_files_in( $root . '/' . WPINC )
		);

		foreach ( (array) scandir( $root ) as $entry ) {
			if ( self::is_php( $entry ) && is_file( $root . '/' . $entry ) ) {
				$candidates[] = $root . '/' . $entry;
			}
		}

		foreach ( $candidates as $path ) {
			$relative = ltrim( substr( $path, strlen( $root ) ), '/' );

			if ( 'wp-config.php' === $relative || isset( $checksums[ $relative ] ) ) {
				continue;
			}

			$unknown[] = $relative;
		}

		sort( $modified );
		sort( $unknown );

		return array(
			array_slice( $modified, 0, self::LIST_CAP ),
			array_slice( $unknown, 0, self::LIST_CAP ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Weekly "all quiet"
	 * ------------------------------------------------------------------- */

	/**
	 * Optional weekly email so that silence means something: if these stop
	 * arriving, the site is down, mail is broken, or monitoring was removed.
	 */
	public static function heartbeat() {
		$stored = get_option( self::OPTION );
		$data   = is_array( $stored ) && isset( $stored['data'] ) ? $stored['data'] : array();
		$since  = time() - WEEK_IN_SECONDS;
		$recent = 0;

		foreach ( CHIPS_SC_Notifier::get_log() as $entry ) {
			if ( $entry['time'] >= $since ) {
				++$recent;
			}
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		$body  = sprintf( "Site: %s\n", home_url() );
		$body .= sprintf( "WordPress: %s\n", get_bloginfo( 'version' ) );
		$body .= sprintf( "Administrators: %d\n", isset( $data['admins'] ) ? count( $data['admins'] ) : 0 );
		$body .= sprintf( "Active plugins: %d\n", isset( $data['active_plugins'] ) ? count( $data['active_plugins'] ) : 0 );
		$body .= sprintf( "Last checkup: %s\n", is_array( $stored ) && ! empty( $stored['time'] ) ? gmdate( 'Y-m-d H:i', $stored['time'] ) . ' UTC' : 'never' );
		$body .= sprintf( "Alerts in the last 7 days: %d\n", $recent );
		$body .= "\nMonitoring is running. If these weekly emails stop, check the site.\n";

		CHIPS_SC_Notifier::deliver(
			sprintf( '[CHIPS Security] %s: weekly check-in, %s', $host, $recent ? $recent . ' alert(s) this week' : 'all quiet' ),
			$body
		);
	}
}

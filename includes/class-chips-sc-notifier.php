<?php
/**
 * Collects events during a request and sends them as one alert at shutdown.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHIPS_SC_Notifier {

	const LOG_OPTION = 'chips_sc_log';
	const LOG_LIMIT  = 50;

	/** @var array[] */
	private static $queue = array();

	public static function init() {
		// Late, so events raised by other shutdown callbacks are still caught.
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 9999 );
	}

	/**
	 * Queue an event for the alert sent at the end of this request.
	 *
	 * @param string   $event Event key from CHIPS_SC_Settings::events(), or an internal one with 'force'.
	 * @param string   $title One line, shown in the subject.
	 * @param string[] $lines Extra detail lines.
	 * @param array    $args  {
	 *     @type bool     $force      Send even though there's no checkbox for it.
	 *     @type string   $ref        Identifier used by drop() to withdraw the event.
	 *     @type string[] $recipients Extra addresses for this alert only.
	 *     @type string   $review     wp-admin URL where this can be looked at.
	 * }
	 * @return bool Whether it was queued.
	 */
	public static function add( $event, $title, $lines = array(), $args = array() ) {
		if ( empty( $args['force'] ) && ! CHIPS_SC_Settings::is_enabled( $event ) ) {
			return false;
		}

		self::$queue[] = array(
			'event'      => $event,
			'title'      => $title,
			'lines'      => array_values( array_filter( (array) $lines, 'strlen' ) ),
			'ref'        => isset( $args['ref'] ) ? (string) $args['ref'] : '',
			'recipients' => isset( $args['recipients'] ) ? (array) $args['recipients'] : array(),
			'review'     => isset( $args['review'] ) ? (string) $args['review'] : '',
		);

		return true;
	}

	/**
	 * Withdraw a queued event, e.g. "made an administrator" once we learn the
	 * same request is reporting that user as newly created.
	 */
	public static function drop( $event, $ref ) {
		foreach ( self::$queue as $i => $item ) {
			if ( $item['event'] === $event && $item['ref'] === (string) $ref ) {
				unset( self::$queue[ $i ] );
			}
		}
		self::$queue = array_values( self::$queue );
	}

	public static function flush() {
		if ( ! self::$queue ) {
			return;
		}

		$queue       = self::$queue;
		self::$queue = array();

		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$actor = self::actor();
		$count = count( $queue );

		$headline = 1 === $count
			? $queue[0]['title']
			: sprintf( '%d changes, starting with: %s', $count, $queue[0]['title'] );

		$subject = sprintf( '[CHIPS Security] %s: %s', $host, $headline );

		$body  = sprintf( "Site: %s (%s)\n", wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), home_url() );
		$body .= sprintf( "When: %s UTC\n", gmdate( 'Y-m-d H:i' ) );
		$body .= sprintf( "By:   %s\n", $actor );

		$extra   = array();
		$reviews = array();

		foreach ( $queue as $item ) {
			$body .= "\n* " . $item['title'] . "\n";
			foreach ( $item['lines'] as $line ) {
				$body .= '    ' . $line . "\n";
			}
			$extra = array_merge( $extra, $item['recipients'] );
			if ( $item['review'] ) {
				$reviews[ $item['review'] ] = true;
			}
		}

		if ( $reviews ) {
			$body .= "\nReview: " . implode( "\n        ", array_keys( $reviews ) ) . "\n";
		}

		$body .= "\nIf this was you or the client, nothing to do. If nobody recognises it, treat the site as compromised.\n";
		$body .= "\n-- \nCHIPS Security Checkup " . CHIPS_SC_VERSION . "\n";

		self::deliver( $subject, $body, $extra );

		$now = time();
		foreach ( $queue as $item ) {
			self::log( $now, $item['title'], $actor );
		}
	}

	/**
	 * Send to every channel. Each is independent so a broken mail setup
	 * doesn't stop the webhook, and the other way round.
	 *
	 * @param string   $subject Subject line.
	 * @param string   $body    Plain-text body.
	 * @param string[] $extra   Addresses to add for this message.
	 * @return bool Whether wp_mail() accepted the message.
	 */
	public static function deliver( $subject, $body, $extra = array() ) {
		$to = array_values(
			array_unique(
				array_merge(
					CHIPS_SC_Settings::recipients(),
					CHIPS_SC_Settings::parse_emails( implode( ',', (array) $extra ) )
				)
			)
		);

		$mailed = $to ? (bool) wp_mail( $to, $subject, $body ) : false;

		$webhook = CHIPS_SC_Settings::webhook_url();
		if ( $webhook ) {
			// `text` is what Slack incoming webhooks read; most others accept it too.
			wp_remote_post(
				$webhook,
				array(
					'timeout'  => 5,
					'blocking' => false,
					'headers'  => array( 'Content-Type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'text' => $subject . "\n\n" . $body ) ),
				)
			);
		}

		return $mailed;
	}

	/**
	 * Who or what made the change, as far as this request can tell.
	 */
	public static function actor() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'WP-CLI (command line)';
		}

		if ( wp_doing_cron() ) {
			return 'WP-Cron (scheduled task, no user)';
		}

		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown IP';
		$path = isset( $_SERVER['REQUEST_URI'] ) ? strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?' ) : '';
		$via  = $path ? sprintf( ' via %s', substr( $path, 0, 120 ) ) : '';

		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;

		if ( $user && $user->exists() ) {
			return sprintf( '%s (user ID %d, %s) from %s%s', $user->user_login, $user->ID, $user->user_email, $ip, $via );
		}

		return sprintf( 'nobody logged in, from %s%s', $ip, $via );
	}

	private static function log( $time, $title, $actor ) {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'time'  => $time,
				'title' => $title,
				'actor' => $actor,
			)
		);

		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_LIMIT ), false );
	}

	/**
	 * @return array[] Most recent first.
	 */
	public static function get_log() {
		$log = get_option( self::LOG_OPTION, array() );

		return is_array( $log ) ? $log : array();
	}
}

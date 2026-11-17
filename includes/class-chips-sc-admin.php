<?php
/**
 * Settings → CHIPS Security Checkup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHIPS_SC_Admin {

	const GROUP  = 'chips_sc';
	const ACTION = 'chips_sc_action';

	public static function init() {
		// Registered outside is_admin() so options.php can find the sanitizer.
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_action' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CHIPS_SC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function register() {
		register_setting(
			self::GROUP,
			CHIPS_SC_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'CHIPS_SC_Settings', 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	public static function menu() {
		add_options_page(
			'CHIPS Security Checkup',
			'CHIPS Security Checkup',
			'manage_options',
			CHIPS_SC_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">Settings</a>', esc_url( admin_url( 'options-general.php?page=' . CHIPS_SC_SLUG ) ) )
		);

		if ( defined( 'CHIPS_SC_MU_LOADED' ) ) {
			$links[] = '<span style="color:#646970">Kept running by its must-use loader</span>';
		}

		return $links;
	}

	private static function action_url( $task ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'task'   => $task,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $task
		);
	}

	public static function handle_action() {
		$task = isset( $_GET['task'] ) ? sanitize_key( wp_unslash( $_GET['task'] ) ) : '';

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to do that.' );
		}
		check_admin_referer( self::ACTION . '_' . $task );

		$result = '';

		if ( 'test' === $task ) {
			$host   = wp_parse_url( home_url(), PHP_URL_HOST );
			$mailed = CHIPS_SC_Notifier::deliver(
				sprintf( '[CHIPS Security] %s: test alert', $host ),
				sprintf(
					"Site: %s\n\nThis is a test from CHIPS Security Checkup, sent by %s.\n\nIf you're reading it, alerts from this site can reach you.\n",
					home_url(),
					CHIPS_SC_Notifier::actor()
				)
			);
			$result = $mailed ? 'test_sent' : 'test_failed';
		} elseif ( 'checkup' === $task ) {
			$report = CHIPS_SC_Snapshot::run();
			$result = $report ? 'checkup_changes' : 'checkup_clean';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => CHIPS_SC_SLUG,
					'chips_sc' => $result,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	private static function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$code = isset( $_GET['chips_sc'] ) ? sanitize_key( wp_unslash( $_GET['chips_sc'] ) ) : '';

		$messages = array(
			'test_sent'       => array( 'success', 'Test alert sent to: ' . implode( ', ', CHIPS_SC_Settings::recipients() ) . '. If it doesn\'t arrive, mail from this site isn\'t being delivered.' ),
			'test_failed'     => array( 'error', 'WordPress could not hand the test email to the mail server. Set up SMTP (CHIPS SMTP Configuration) before relying on these alerts.' ),
			'checkup_clean'   => array( 'success', 'Checkup finished. Nothing has changed since the last one.' ),
			'checkup_changes' => array( 'warning', 'Checkup finished and found changes. The details have been emailed.' ),
		);

		if ( isset( $messages[ $code ] ) ) {
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				esc_attr( $messages[ $code ][0] ),
				esc_html( $messages[ $code ][1] )
			);
		}
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to view this page.' );
		}

		$settings       = CHIPS_SC_Settings::get();
		$name           = CHIPS_SC_Settings::OPTION;
		$locked_email   = chips_sc_config( 'alert_email' );
		$locked_webhook = chips_sc_config( 'webhook_url' );
		$snapshot       = get_option( CHIPS_SC_Snapshot::OPTION );
		$next           = wp_next_scheduled( CHIPS_SC_Snapshot::CRON_DAILY );
		$loader         = CHIPS_SC_Loader::status();
		$log            = array_slice( CHIPS_SC_Notifier::get_log(), 0, 20 );
		?>
		<div class="wrap">
			<h1>CHIPS Security Checkup</h1>

			<?php self::notice(); ?>

			<p class="description" style="max-width:46em">
				Sends an alert when something changes that could mean this site has been broken into.
				Most alerts are routine, for example someone on the team installing a plugin. The ones nobody recognises are the ones that matter.
			</p>

			<?php if ( CHIPS_SC_Settings::has_no_recipients() ) : ?>
				<div class="notice notice-warning inline">
					<p>
						No alert address is set, so alerts are going to the site admin email
						(<?php echo esc_html( get_option( 'admin_email' ) ); ?>). Add a studio email below, or set
						<code>CHIPS_SC_ALERT_EMAIL</code> in <code>wp-config.php</code>.
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2>Who gets the alerts</h2>
				<table class="form-table" role="presentation">
					<?php if ( $locked_email ) : ?>
						<tr>
							<th scope="row">Locked recipient</th>
							<td>
								<code><?php echo esc_html( $locked_email ); ?></code>
								<p class="description">Set in <code>wp-config.php</code>. Always receives alerts and can't be changed here.</p>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="chips_sc_studio_email">Studio email</label></th>
						<td>
							<input type="email" class="regular-text" id="chips_sc_studio_email"
								name="<?php echo esc_attr( $name ); ?>[studio_email]"
								value="<?php echo esc_attr( $settings['studio_email'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="chips_sc_extra_emails">Also send to</label></th>
						<td>
							<input type="text" class="regular-text" id="chips_sc_extra_emails"
								name="<?php echo esc_attr( $name ); ?>[extra_emails]"
								value="<?php echo esc_attr( $settings['extra_emails'] ); ?>" />
							<p class="description">Optional. One or more addresses, separated by commas.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="chips_sc_webhook_url">Webhook URL</label></th>
						<td>
							<?php if ( $locked_webhook ) : ?>
								<em>Set in <code>wp-config.php</code>.</em>
							<?php else : ?>
								<input type="url" class="regular-text code" id="chips_sc_webhook_url" placeholder="https://hooks.slack.com/services/…"
									name="<?php echo esc_attr( $name ); ?>[webhook_url]"
									value="<?php echo esc_attr( $settings['webhook_url'] ); ?>" />
							<?php endif; ?>
							<p class="description">Optional. A Slack incoming webhook (or anything that accepts the same format) gets every alert too, so they still arrive if email from this site fails.</p>
						</td>
					</tr>
				</table>

				<h2>Send an alert when</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Right away</th>
						<td>
							<fieldset>
								<?php foreach ( CHIPS_SC_Settings::events() as $key => $event ) : ?>
									<label style="display:block;margin-bottom:.5em">
										<input type="checkbox" value="1"
											name="<?php echo esc_attr( $name ); ?>[events][<?php echo esc_attr( $key ); ?>]"
											<?php checked( ! empty( $settings['events'][ $key ] ) ); ?> />
										<?php echo esc_html( $event['label'] ); ?>
										<?php if ( ! empty( $event['note'] ) ) : ?>
											<span class="description">&nbsp;<?php echo esc_html( $event['note'] ); ?></span>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row">Once a day</th>
						<td>
							<label>
								<input type="checkbox" value="1" name="<?php echo esc_attr( $name ); ?>[daily_checkup]" <?php checked( $settings['daily_checkup'] ); ?> />
								Run the daily checkup
							</label>
							<p class="description" style="max-width:46em">
								Looks for changes that didn't go through WordPress: administrator accounts added straight to the database or hidden from the Users screen,
								plugin and theme folders that appeared on their own, PHP files in uploads, edits to <code>wp-config.php</code> and <code>.htaccess</code>,
								and WordPress core files that don't match the official release.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Once a week</th>
						<td>
							<label>
								<input type="checkbox" value="1" name="<?php echo esc_attr( $name ); ?>[heartbeat]" <?php checked( $settings['heartbeat'] ); ?> />
								Send an "all quiet" email
							</label>
							<p class="description">If it stops arriving, the site is down, its email is broken, or this plugin has been removed.</p>
						</td>
					</tr>
				</table>

				<p class="description">Changing anything on this page sends an alert to everyone who was on the list before the change.</p>

				<?php submit_button(); ?>
			</form>

			<hr />

			<h2>Status</h2>
			<table class="widefat striped" style="max-width:46em">
				<tbody>
					<tr>
						<td style="width:14em">Must-use loader</td>
						<td>
							<?php if ( 'ok' === $loader ) : ?>
								Installed. Deactivating this plugin on the Plugins screen won't stop it.
							<?php elseif ( 'disabled' === $loader ) : ?>
								Switched off by <code>CHIPS_SC_NO_MU_LOADER</code>. Deactivating the plugin stops the alerts.
							<?php else : ?>
								<strong style="color:#b32d2e">Not installed.</strong>
								WordPress couldn't write to <code>wp-content/mu-plugins</code>. Copy
								<code>mu-loader/<?php echo esc_html( CHIPS_SC_Loader::FILE ); ?></code> there by hand, otherwise deactivating the plugin stops the alerts.
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td>Alerts go to</td>
						<td><?php echo esc_html( implode( ', ', CHIPS_SC_Settings::recipients() ) ); ?><?php echo CHIPS_SC_Settings::webhook_url() ? ' and the webhook' : ''; ?></td>
					</tr>
					<tr>
						<td>Last checkup</td>
						<td>
							<?php
							if ( is_array( $snapshot ) && ! empty( $snapshot['time'] ) ) {
								echo esc_html( wp_date( 'j M Y, H:i', $snapshot['time'] ) );
								echo esc_html( empty( $snapshot['changes'] ) ? '. Nothing to report.' : '. Changes found and emailed.' );
							} else {
								echo 'Not run yet.';
							}
							?>
						</td>
					</tr>
					<tr>
						<td>Next checkup</td>
						<td><?php echo $next ? esc_html( wp_date( 'j M Y, H:i', $next ) ) : 'Not scheduled.'; ?></td>
					</tr>
				</tbody>
			</table>

			<p>
				<a class="button" href="<?php echo esc_url( self::action_url( 'test' ) ); ?>">Send a test alert</a>
				<a class="button" href="<?php echo esc_url( self::action_url( 'checkup' ) ); ?>">Run the checkup now</a>
			</p>

			<h2>Recent alerts</h2>
			<?php if ( ! $log ) : ?>
				<p>None yet.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col" style="width:12em">When</th>
							<th scope="col">What</th>
							<th scope="col">By</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( 'j M Y, H:i', (int) $entry['time'] ) ); ?></td>
								<td><?php echo esc_html( $entry['title'] ); ?></td>
								<td><?php echo esc_html( $entry['actor'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}

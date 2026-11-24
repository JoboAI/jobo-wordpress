<?php
/**
 * Settings screen — branded render-style page.
 *
 * Styling lives in assets/tokens.css + assets/admin.css (scoped under
 * .jobo-admin); behaviour in assets/admin.js. The markup keeps the .wrap
 * class and a .wp-header-end marker so core relocates its notices below the
 * header band, and every form field keeps its original name so the settings
 * sanitiser and the no-JS path are untouched.
 *
 * @package Jobo_Jobs
 *
 * @var array<string,mixed> $settings     Current settings.
 * @var array<string,mixed> $state        Persisted sync state.
 * @var Jobo_Target         $target       Target that will actually be written.
 * @var bool                $has_key      Whether an API key is configured.
 * @var bool                $sync_on      Whether scheduled sync is on.
 * @var array<string,mixed> $connection   Jobo_OAuth::status(): available, signed_in, email.
 * @var string              $signin_error Why a sign-in just failed, or ''.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// Read-only display of the post-redirect result; no state change, so no nonce.
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$jobo_result  = isset( $_GET['jobo_result'] ) ? sanitize_key( wp_unslash( $_GET['jobo_result'] ) ) : '';
$jobo_created = isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0;
$jobo_updated = isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0;
$jobo_expired = isset( $_GET['expired'] ) ? absint( $_GET['expired'] ) : 0;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

// "12 480"-style figures: plain grouping with a non-breaking space, rendered
// with tabular-nums by the stylesheet.
$jobo_fmt = static function ( $jobo_n ): string {
	return number_format( (float) $jobo_n, 0, '.', "\u{00a0}" );
};

$jobo_has_error = ! empty( $state['last_error'] );

if ( ! $has_key ) {
	$jobo_badge_class = 'jobo-badge--neutral';
	$jobo_badge_text  = __( 'Not connected', 'career-site-jobs' );
} elseif ( $jobo_has_error ) {
	$jobo_badge_class = 'jobo-badge--critical';
	$jobo_badge_text  = __( 'Error', 'career-site-jobs' );
} else {
	$jobo_badge_class = 'jobo-badge--success';
	$jobo_badge_text  = __( 'Connected', 'career-site-jobs' );
}

$jobo_signed_in     = ! empty( $connection['signed_in'] );
$jobo_can_sign_in   = ! empty( $connection['available'] );
$jobo_account_email = (string) ( $connection['email'] ?? '' );
$jobo_api_keys_url  = 'https://enterprise.jobo.world/api-keys?src=wordpress';
$jobo_allowed_link  = array(
	'a' => array(
		'href'   => array(),
		'target' => array(),
		'rel'    => array(),
	),
);

// The key field folds away behind "Use an API key instead" while signing in is
// the way to connect and no key is saved. It stays open after a rejected paste,
// so the field the admin just used is still in front of them.
$jobo_key_folded = $jobo_can_sign_in && ! $has_key;
$jobo_key_open   = false;
foreach ( get_settings_errors( Jobo_Settings::OPTION_NAME ) as $jobo_settings_error ) {
	if ( 'jobo_invalid_key' === $jobo_settings_error['code'] ) {
		$jobo_key_open = true;
	}
}
?>
<div class="wrap jobo-admin">

	<div class="jobo-header">
		<div>
			<p class="jobo-eyebrow"><?php esc_html_e( 'WordPress connector', 'career-site-jobs' ); ?></p>
			<h1 class="jobo-title">
				<?php esc_html_e( 'Jobo jobs', 'career-site-jobs' ); ?>
				<span class="jobo-badge <?php echo esc_attr( $jobo_badge_class ); ?>"><?php echo esc_html( $jobo_badge_text ); ?></span>
			</h1>
		</div>

		<?php if ( $has_key ) : ?>
			<div class="jobo-header-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( Jobo_Plugin::ACTION_SYNC_NOW ); ?>" />
					<?php wp_nonce_field( Jobo_Plugin::ACTION_SYNC_NOW ); ?>
					<button type="submit" class="jobo-btn jobo-btn--secondary"><?php esc_html_e( 'Sync now', 'career-site-jobs' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( Jobo_Plugin::ACTION_RESYNC ); ?>" />
					<?php wp_nonce_field( Jobo_Plugin::ACTION_RESYNC ); ?>
					<button type="submit" class="jobo-btn jobo-btn--subtle"><?php esc_html_e( 'Resync everything', 'career-site-jobs' ); ?></button>
				</form>
			</div>
		<?php endif; ?>
	</div>

	<hr class="wp-header-end" />
	<hr class="jobo-hairline" />

	<?php settings_errors( Jobo_Settings::OPTION_NAME ); ?>

	<?php if ( 'synced' === $jobo_result || 'resynced' === $jobo_result ) : ?>
		<div class="jobo-banner jobo-banner--success">
			<p class="jobo-num">
				<?php
				printf(
					/* translators: 1: jobs created, 2: jobs updated, 3: jobs expired. */
					esc_html__( 'Sync complete. %1$s added, %2$s updated, %3$s expired.', 'career-site-jobs' ),
					esc_html( $jobo_fmt( $jobo_created ) ),
					esc_html( $jobo_fmt( $jobo_updated ) ),
					esc_html( $jobo_fmt( $jobo_expired ) )
				);
				?>
			</p>
		</div>
	<?php elseif ( 'failed' === $jobo_result ) : ?>
		<div class="jobo-banner jobo-banner--critical">
			<p><?php esc_html_e( 'The sync did not complete. See the details below.', 'career-site-jobs' ); ?></p>
		</div>
	<?php elseif ( 'busy' === $jobo_result ) : ?>
		<div class="jobo-banner jobo-banner--warning">
			<p><?php esc_html_e( 'Another sync is already running, so this one did not start. Try again in a few minutes.', 'career-site-jobs' ); ?></p>
		</div>
	<?php elseif ( 'connected' === $jobo_result && $jobo_signed_in ) : ?>
		<div class="jobo-banner jobo-banner--success">
			<p>
				<?php
				if ( '' !== $jobo_account_email ) {
					printf(
						/* translators: %s: email address of the Jobo account. */
						esc_html__( 'Signed in. This site is connected to Jobo as %s.', 'career-site-jobs' ),
						esc_html( $jobo_account_email )
					);
				} else {
					esc_html_e( 'Signed in. This site is connected to Jobo.', 'career-site-jobs' );
				}
				?>
			</p>
		</div>
	<?php elseif ( 'disconnected' === $jobo_result && ! $has_key ) : ?>
		<div class="jobo-banner jobo-banner--neutral">
			<p><?php esc_html_e( 'Disconnected. The key this site held has been revoked, and the app is removed from your Jobo dashboard.', 'career-site-jobs' ); ?></p>
		</div>
	<?php elseif ( 'disconnected_here' === $jobo_result && ! $has_key ) : ?>
		<div class="jobo-banner jobo-banner--warning">
			<p>
				<?php
				printf(
					wp_kses(
						/* translators: %s: Jobo API keys URL. */
						__( 'Disconnected. This site no longer holds the key, but Jobo could not be reached to revoke it. To finish, disconnect the app under <a href="%s" target="_blank" rel="noopener">Developers → API Keys</a> in your Jobo dashboard.', 'career-site-jobs' ),
						$jobo_allowed_link
					),
					esc_url( $jobo_api_keys_url )
				);
				?>
			</p>
		</div>
	<?php elseif ( 'signin_failed' === $jobo_result ) : ?>
		<div class="jobo-banner jobo-banner--critical">
			<p>
				<?php
				if ( '' !== $signin_error ) {
					echo esc_html( $signin_error );
				} else {
					esc_html_e( 'The sign-in did not complete. Start again, or use an API key instead.', 'career-site-jobs' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! $has_key && '' === $jobo_result ) : ?>
		<div class="jobo-banner jobo-banner--neutral">
			<p>
				<?php
				if ( $jobo_can_sign_in ) {
					esc_html_e( 'Sign in with Jobo below to start importing jobs. You can paste an API key instead.', 'career-site-jobs' );
				} else {
					printf(
						wp_kses(
							/* translators: %s: Jobo API keys URL. */
							__( 'Add your Jobo API key below to start importing jobs. <a href="%s" target="_blank" rel="noopener">Create a key</a> — it takes about a minute.', 'career-site-jobs' ),
							$jobo_allowed_link
						),
						esc_url( $jobo_api_keys_url )
					);
				}
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( $has_key && ! $sync_on ) : ?>
		<div class="jobo-banner jobo-banner--warning">
			<p>
				<?php
				printf(
					wp_kses(
						/* translators: %s: label of the scheduled sync checkbox. */
						__( 'Connected to Jobo. Next, choose which jobs to import under <strong>Which jobs to import</strong>, then tick <strong>%s</strong> and save. Until then nothing is imported on a schedule; <strong>Sync now</strong> runs one sync straight away.', 'career-site-jobs' ),
						array( 'strong' => array() )
					),
					esc_html__( 'Sync automatically', 'career-site-jobs' )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<section class="jobo-section">
		<h2 class="jobo-section-title"><?php esc_html_e( 'Status', 'career-site-jobs' ); ?></h2>

		<div class="jobo-defgrid">
			<div class="jobo-defgrid-row">
				<div class="jobo-defgrid-label"><?php esc_html_e( 'Importing into', 'career-site-jobs' ); ?></div>
				<div class="jobo-defgrid-value"><?php echo esc_html( $target->get_label() ); ?></div>
			</div>
			<div class="jobo-defgrid-row">
				<div class="jobo-defgrid-label"><?php esc_html_e( 'Last sync', 'career-site-jobs' ); ?></div>
				<div class="jobo-defgrid-value">
					<?php
					if ( empty( $state['last_sync_at'] ) ) {
						esc_html_e( 'Never', 'career-site-jobs' );
					} else {
						echo esc_html(
							sprintf(
								/* translators: %s: human-readable time difference. */
								__( '%s ago', 'career-site-jobs' ),
								human_time_diff( (int) $state['last_sync_at'], time() )
							)
						);
					}
					?>
				</div>
			</div>
			<div class="jobo-defgrid-row">
				<div class="jobo-defgrid-label"><?php esc_html_e( 'Scan position', 'career-site-jobs' ); ?></div>
				<div class="jobo-defgrid-value">
					<?php
					$jobo_filters_same = Jobo_Sync::filters_fingerprint( Jobo_Settings::to_feed_params() ) === (string) $state['feed_filters'];
					if ( '' !== (string) $state['feed_cursor'] && ( $jobo_filters_same || '' === (string) $state['feed_filters'] ) ) {
						esc_html_e( 'Part-way through a scan; the next run resumes where it stopped.', 'career-site-jobs' );
					} elseif ( '' !== (string) $state['feed_cursor'] ) {
						esc_html_e( 'Your filters changed during a scan. The next run starts a new scan that imports every job matching the new filters.', 'career-site-jobs' );
					} elseif ( '' !== (string) $state['feed_watermark'] && $jobo_filters_same ) {
						esc_html_e( 'Up to date — the next run fetches only jobs that are new or changed since the last scan.', 'career-site-jobs' );
					} else {
						esc_html_e( 'The next run imports every job that matches your filters.', 'career-site-jobs' );
					}
					?>
				</div>
			</div>
			<?php if ( ! empty( $state['last_sync_at'] ) ) : ?>
				<div class="jobo-defgrid-row">
					<div class="jobo-defgrid-label"><?php esc_html_e( 'Billed in the last sync', 'career-site-jobs' ); ?></div>
					<div class="jobo-defgrid-value jobo-num">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number of jobs billed, 2: number of credits deducted. */
								__( '%1$s jobs, %2$s credits', 'career-site-jobs' ),
								$jobo_fmt( (int) $state['last_run_billed'] ),
								$jobo_fmt( (int) $state['last_run_credits'] )
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
			<div class="jobo-defgrid-row">
				<div class="jobo-defgrid-label"><?php esc_html_e( 'Jobs added', 'career-site-jobs' ); ?></div>
				<div class="jobo-defgrid-value jobo-num"><?php echo esc_html( $jobo_fmt( (int) $state['imported_total'] ) ); ?></div>
			</div>
			<div class="jobo-defgrid-row">
				<div class="jobo-defgrid-label"><?php esc_html_e( 'Jobs updated', 'career-site-jobs' ); ?></div>
				<div class="jobo-defgrid-value jobo-num"><?php echo esc_html( $jobo_fmt( (int) $state['updated_total'] ) ); ?></div>
			</div>
			<div class="jobo-defgrid-row">
				<div class="jobo-defgrid-label"><?php esc_html_e( 'Jobs expired', 'career-site-jobs' ); ?></div>
				<div class="jobo-defgrid-value jobo-num"><?php echo esc_html( $jobo_fmt( (int) $state['expired_total'] ) ); ?></div>
			</div>
			<?php if ( null !== $state['quota_remaining'] ) : ?>
				<div class="jobo-defgrid-row">
					<div class="jobo-defgrid-label"><?php esc_html_e( 'Included jobs', 'career-site-jobs' ); ?></div>
					<div class="jobo-defgrid-value jobo-num">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: remaining jobs, 2: total included jobs. */
								__( '%1$s of %2$s jobs remaining', 'career-site-jobs' ),
								$jobo_fmt( (int) $state['quota_remaining'] ),
								$jobo_fmt( (int) $state['quota_limit'] )
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
			<?php if ( null !== $state['credits_balance'] ) : ?>
				<div class="jobo-defgrid-row">
					<div class="jobo-defgrid-label"><?php esc_html_e( 'Wallet balance', 'career-site-jobs' ); ?></div>
					<div class="jobo-defgrid-value jobo-num">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: dollar value, 2: credit count. */
								__( '$%1$s (%2$s credits)', 'career-site-jobs' ),
								number_format_i18n( (int) $state['credits_balance'] / 1000, 2 ),
								$jobo_fmt( (int) $state['credits_balance'] )
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $jobo_has_error ) : ?>
			<div class="jobo-banner jobo-banner--critical">
				<p><?php echo esc_html( (string) $state['last_error'] ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $has_key ) : ?>
			<p class="jobo-section-desc">
				<?php esc_html_e( 'Resyncing imports every job that matches your filters again. Jobs your Jobo account received in the last 30 days are not billed again; the rest are. Regular syncs already fetch new and changed jobs, so you rarely need it. Existing listings are matched on their Jobo job ID and updated in place, so nothing is duplicated.', 'career-site-jobs' ); ?>
			</p>
		<?php endif; ?>
	</section>

	<?php
	// The sign-in and disconnect buttons sit inside the settings form below,
	// which cannot hold a second form. Each button submits one of these by its
	// `form` attribute instead, so it posts its own action and nonce and none
	// of the settings fields.
	?>
	<?php if ( $jobo_signed_in ) : ?>
		<form id="jobo-oauth-disconnect" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Jobo_OAuth::ACTION_DISCONNECT ); ?>" />
			<?php wp_nonce_field( Jobo_OAuth::ACTION_DISCONNECT ); ?>
		</form>
	<?php elseif ( $jobo_can_sign_in ) : ?>
		<form id="jobo-oauth-start" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Jobo_OAuth::ACTION_START ); ?>" />
			<?php wp_nonce_field( Jobo_OAuth::ACTION_START ); ?>
		</form>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php settings_fields( Jobo_Settings::OPTION_GROUP ); ?>

		<section class="jobo-section">
			<h2 class="jobo-section-title"><?php esc_html_e( 'Connection', 'career-site-jobs' ); ?></h2>

			<?php
			/** The API key field with its connection test, as a pasted key has always used it. */
			$jobo_key_row = static function () use ( $has_key ): void {
				?>
				<div class="jobo-field-row">
					<div class="jobo-field-label">
						<label for="jobo_api_key"><?php esc_html_e( 'API key', 'career-site-jobs' ); ?></label>
						<p class="jobo-field-desc">
							<?php
							printf(
								wp_kses(
									/* translators: %s: Jobo API keys URL. */
									__( 'Starts with <code>jbe_live_</code>. <a href="%s" target="_blank" rel="noopener">Get one from the API keys page on enterprise.jobo.world</a>. Leave the masked value untouched to keep the current key.', 'career-site-jobs' ),
									array(
										'code' => array(),
										'a'    => array(
											'href'   => array(),
											'target' => array(),
											'rel'    => array(),
										),
									)
								),
								'https://enterprise.jobo.world/api-keys?src=wordpress'
							);
							?>
						</p>
					</div>
					<div class="jobo-field-control">
						<div class="jobo-key-row">
							<input
								type="password"
								id="jobo_api_key"
								class="jobo-key-input jobo-input--mono"
								name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME ); ?>[api_key]"
								value="<?php echo $has_key ? esc_attr( str_repeat( '*', 24 ) ) : ''; ?>"
								autocomplete="off"
							/>
							<button type="button" id="jobo-test-connection" class="jobo-btn jobo-btn--secondary"><?php esc_html_e( 'Test connection', 'career-site-jobs' ); ?></button>
							<span id="jobo-test-spinner" class="jobo-spinner" hidden aria-hidden="true"></span>
						</div>
						<p id="jobo-key-format-help" class="jobo-help jobo-help--critical" hidden>
							<?php esc_html_e( 'That does not look like a Jobo API key. Keys begin with jbe_live_ or jbe_test_ and are 74 characters long.', 'career-site-jobs' ); ?>
						</p>
						<p id="jobo-test-result" class="jobo-test-result" hidden aria-live="polite"></p>
					</div>
				</div>
				<?php
			};

			/** The "Sign in with Jobo" row. Primary while nothing is connected, secondary beside a saved key. */
			$jobo_sign_in_row = static function () use ( $has_key ): void {
				?>
				<div class="jobo-field-row">
					<div class="jobo-field-label">
						<span class="jobo-field-name"><?php esc_html_e( 'Jobo account', 'career-site-jobs' ); ?></span>
						<p class="jobo-field-desc">
							<?php
							esc_html_e( 'Sign in and approve the connection, and Jobo issues this site its own API key. The approval screen lists this site as an unverified app with your site address, which is expected for a WordPress site.', 'career-site-jobs' );
							if ( $has_key ) {
								echo ' ';
								esc_html_e( 'Signing in replaces the API key saved here.', 'career-site-jobs' );
							}
							?>
						</p>
					</div>
					<div class="jobo-field-control">
						<button type="submit" form="jobo-oauth-start" class="jobo-btn <?php echo $has_key ? 'jobo-btn--secondary' : 'jobo-btn--primary'; ?>"><?php esc_html_e( 'Sign in with Jobo', 'career-site-jobs' ); ?></button>
					</div>
				</div>
				<?php
			};
			?>

			<?php if ( $jobo_signed_in ) : ?>
				<div class="jobo-field-row">
					<div class="jobo-field-label">
						<span class="jobo-field-name"><?php esc_html_e( 'Jobo account', 'career-site-jobs' ); ?></span>
						<p class="jobo-field-desc"><?php esc_html_e( 'This site imports jobs with the account you signed in with. To switch accounts or use an API key, disconnect first.', 'career-site-jobs' ); ?></p>
					</div>
					<div class="jobo-field-control">
						<p class="jobo-account">
							<?php
							if ( '' !== $jobo_account_email ) {
								printf(
									/* translators: %s: email address of the connected Jobo account. */
									esc_html__( 'Connected as %s', 'career-site-jobs' ),
									'<strong>' . esc_html( $jobo_account_email ) . '</strong>'
								);
							} else {
								esc_html_e( 'Connected', 'career-site-jobs' );
							}
							?>
						</p>
						<div class="jobo-key-row">
							<button type="button" id="jobo-test-connection" class="jobo-btn jobo-btn--secondary"><?php esc_html_e( 'Test connection', 'career-site-jobs' ); ?></button>
							<button type="submit" form="jobo-oauth-disconnect" class="jobo-btn jobo-btn--subtle"><?php esc_html_e( 'Disconnect', 'career-site-jobs' ); ?></button>
							<span id="jobo-test-spinner" class="jobo-spinner" hidden aria-hidden="true"></span>
						</div>
						<p id="jobo-test-result" class="jobo-test-result" hidden aria-live="polite"></p>
					</div>
				</div>
			<?php elseif ( $jobo_key_folded ) : ?>
				<?php $jobo_sign_in_row(); ?>
				<details class="jobo-details" <?php echo $jobo_key_open ? 'open' : ''; ?>>
					<summary><?php esc_html_e( 'Use an API key instead', 'career-site-jobs' ); ?></summary>
					<?php $jobo_key_row(); ?>
				</details>
			<?php else : ?>
				<?php if ( ! $jobo_can_sign_in ) : ?>
					<p class="jobo-section-desc"><?php esc_html_e( 'Sign in with Jobo needs a site served over HTTPS, so this site connects with an API key.', 'career-site-jobs' ); ?></p>
				<?php endif; ?>
				<?php $jobo_key_row(); ?>
				<?php
				if ( $jobo_can_sign_in ) {
					$jobo_sign_in_row();
				}
				?>
			<?php endif; ?>
		</section>

		<section class="jobo-section">
			<h2 class="jobo-section-title"><?php esc_html_e( 'Import behaviour', 'career-site-jobs' ); ?></h2>

			<div class="jobo-field-row">
				<div class="jobo-field-label">
					<label for="jobo_target"><?php esc_html_e( 'Import into', 'career-site-jobs' ); ?></label>
					<p class="jobo-field-desc"><?php esc_html_e( 'Automatic uses WP Job Manager when it is active, and the built-in post type otherwise.', 'career-site-jobs' ); ?></p>
				</div>
				<div class="jobo-field-control">
					<select id="jobo_target" name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME ); ?>[target]">
						<?php
						$jobo_targets = array(
							'auto' => __( 'Detect automatically', 'career-site-jobs' ),
							'wpjm' => __( 'WP Job Manager', 'career-site-jobs' ),
							'cpt'  => __( 'Jobo Jobs (built-in post type)', 'career-site-jobs' ),
						);
						foreach ( $jobo_targets as $jobo_value => $jobo_label ) :
							?>
							<option value="<?php echo esc_attr( $jobo_value ); ?>" <?php selected( $settings['target'], $jobo_value ); ?>>
								<?php echo esc_html( $jobo_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="jobo-field-row">
				<div class="jobo-field-label">
					<label for="jobo_post_status"><?php esc_html_e( 'Status for new listings', 'career-site-jobs' ); ?></label>
					<p class="jobo-field-desc"><?php esc_html_e( 'Only applies to new listings. Updates never change the status of a listing you have edited.', 'career-site-jobs' ); ?></p>
				</div>
				<div class="jobo-field-control">
					<select id="jobo_post_status" name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME ); ?>[post_status]">
						<?php
						foreach ( array(
							'publish' => __( 'Published', 'career-site-jobs' ),
							'draft'   => __( 'Draft (review before publishing)', 'career-site-jobs' ),
							'pending' => __( 'Pending review', 'career-site-jobs' ),
						) as $jobo_value => $jobo_label ) :
							?>
							<option value="<?php echo esc_attr( $jobo_value ); ?>" <?php selected( $settings['post_status'], $jobo_value ); ?>>
								<?php echo esc_html( $jobo_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="jobo-field-row">
				<div class="jobo-field-label">
					<label for="jobo_description_format"><?php esc_html_e( 'Description layout', 'career-site-jobs' ); ?></label>
					<p class="jobo-field-desc"><?php esc_html_e( 'Enhanced gives every description the same layout: headings, lists and key facts, without the repeated title, buttons and tracking tags. It is automatic and in beta, and a job without an enhanced description uses its standard one. Applies to jobs imported or updated from now on.', 'career-site-jobs' ); ?></p>
				</div>
				<div class="jobo-field-control">
					<select id="jobo_description_format" name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME ); ?>[description_format]">
						<?php
						foreach ( array(
							'html'          => __( 'Standard', 'career-site-jobs' ),
							'enhanced_html' => __( 'Enhanced (beta)', 'career-site-jobs' ),
						) as $jobo_value => $jobo_label ) :
							?>
							<option value="<?php echo esc_attr( $jobo_value ); ?>" <?php selected( $settings['description_format'], $jobo_value ); ?>>
								<?php echo esc_html( $jobo_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="jobo-field-row">
				<div class="jobo-field-label">
					<label for="jobo_expire_action"><?php esc_html_e( 'When a job closes', 'career-site-jobs' ); ?></label>
					<p class="jobo-field-desc"><?php esc_html_e( 'Checking for closed jobs is always free.', 'career-site-jobs' ); ?></p>
				</div>
				<div class="jobo-field-control">
					<select id="jobo_expire_action" name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME ); ?>[expire_action]">
						<?php
						foreach ( array(
							'draft' => __( 'Move to draft', 'career-site-jobs' ),
							'trash' => __( 'Move to trash', 'career-site-jobs' ),
							'keep'  => __( 'Leave it published', 'career-site-jobs' ),
						) as $jobo_value => $jobo_label ) :
							?>
							<option value="<?php echo esc_attr( $jobo_value ); ?>" <?php selected( $settings['expire_action'], $jobo_value ); ?>>
								<?php echo esc_html( $jobo_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="jobo-field-row">
				<div class="jobo-field-label">
					<label for="jobo_sync_enabled"><?php esc_html_e( 'Scheduled sync', 'career-site-jobs' ); ?></label>
					<p class="jobo-field-desc"><?php esc_html_e( 'Off until you turn it on, so nothing is imported before you have chosen which jobs you want. Sync now and Resync everything work either way.', 'career-site-jobs' ); ?></p>
				</div>
				<div class="jobo-field-control">
					<label class="jobo-inline-check">
						<input type="checkbox" id="jobo_sync_enabled" name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME ); ?>[sync_enabled]" value="1" <?php checked( $sync_on ); ?> />
						<?php esc_html_e( 'Sync automatically', 'career-site-jobs' ); ?>
					</label>
				</div>
			</div>

			<div class="jobo-field-row">
				<div class="jobo-field-label">
					<label for="jobo_sync_interval"><?php esc_html_e( 'Sync frequency', 'career-site-jobs' ); ?></label>
					<p class="jobo-field-desc"><?php esc_html_e( 'After the first import, each sync fetches only jobs that are new or changed since the last one. A job is billed once per 30 days however often you sync.', 'career-site-jobs' ); ?></p>
				</div>
				<div class="jobo-field-control">
					<select id="jobo_sync_interval" name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME ); ?>[sync_interval]">
						<?php
						foreach ( array(
							'hourly'     => __( 'Hourly', 'career-site-jobs' ),
							'twicedaily' => __( 'Twice daily', 'career-site-jobs' ),
							'daily'      => __( 'Daily', 'career-site-jobs' ),
						) as $jobo_value => $jobo_label ) :
							?>
							<option value="<?php echo esc_attr( $jobo_value ); ?>" <?php selected( $settings['sync_interval'], $jobo_value ); ?>>
								<?php echo esc_html( $jobo_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="jobo-field-row">
				<div class="jobo-field-label">
					<label for="jobo_batch_size"><?php esc_html_e( 'Batch limits', 'career-site-jobs' ); ?></label>
					<p class="jobo-field-desc"><?php esc_html_e( 'These multiply into the most jobs one run will import. Imports use your Job Search plan\'s included jobs first, then the pay-as-you-go rate ($3.00 per 1,000 jobs). With Unlimited, Feed imports have no per-job charge.', 'career-site-jobs' ); ?></p>
				</div>
				<div class="jobo-field-control">
					<div class="jobo-inline-controls">
						<label class="jobo-sub-label" for="jobo_batch_size"><?php esc_html_e( 'Jobs per request', 'career-site-jobs' ); ?></label>
						<input type="number" id="jobo_batch_size" min="1" max="1000"
							name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME ); ?>[batch_size]"
							value="<?php echo esc_attr( (string) $settings['batch_size'] ); ?>" />
						<label class="jobo-sub-label" for="jobo_max_batches"><?php esc_html_e( 'Requests per run', 'career-site-jobs' ); ?></label>
						<input type="number" id="jobo_max_batches" min="1" max="50"
							name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME ); ?>[max_batches]"
							value="<?php echo esc_attr( (string) $settings['max_batches'] ); ?>" />
					</div>
				</div>
			</div>
		</section>

		<section class="jobo-section">
			<h2 class="jobo-section-title"><?php esc_html_e( 'Which jobs to import', 'career-site-jobs' ); ?></h2>
			<p class="jobo-section-desc">
				<?php esc_html_e( 'Leave everything blank to import all jobs. Narrowing keeps your board relevant and usage predictable; imports use your plan\'s included jobs first, and with Unlimited, Feed imports have no per-job charge. After you change these filters, the next sync imports every matching job again, even part-way through a scan, and then retires listings that only matched the old filters (as set under When a job closes).', 'career-site-jobs' ); ?>
			</p>
			<?php
			// Every field here maps onto a key in feed_filter_keys
			// (JOB_FEED_FILTER_KEYS) in Jobo.Connectors/contracts/filters.json.
			// Deliberately no skills field: skills is a search-only filter.

			/**
			 * One picker row: a combobox mount that admin.js fills
			 * (data-jobo-picker names the option source), the hidden input
			 * that actually submits (a JSON list, so values may hold commas),
			 * and a comma-separated text field for the no-JS path.
			 */
			$jobo_picker_row = static function ( string $key, string $label, string $desc, string $kind, string $placeholder, bool $labelled = false ) use ( $settings ): void {
				$jobo_saved   = (array) ( $settings[ $key ] ?? array() );
				$jobo_name    = Jobo_Settings::OPTION_NAME . '[' . $key . ']';
				$jobo_plain   = $labelled ? Jobo_Settings::labelled_values( $jobo_saved ) : $jobo_saved;
				$jobo_id      = 'jobo_' . $key;
				$jobo_labelid = $jobo_id . '_label';
				?>
				<div class="jobo-field-row">
					<div class="jobo-field-label">
						<span class="jobo-field-name" id="<?php echo esc_attr( $jobo_labelid ); ?>"><?php echo esc_html( $label ); ?></span>
						<?php if ( '' !== $desc ) : ?>
							<p class="jobo-field-desc"><?php echo esc_html( $desc ); ?></p>
						<?php endif; ?>
					</div>
					<div class="jobo-field-control">
						<div class="jobo-cb-mount"
							data-jobo-picker="<?php echo esc_attr( $kind ); ?>"
							data-jobo-input="<?php echo esc_attr( $jobo_id ); ?>"
							data-jobo-label="<?php echo esc_attr( $label ); ?>"
							data-jobo-placeholder="<?php echo esc_attr( $placeholder ); ?>"></div>
						<input type="hidden" id="<?php echo esc_attr( $jobo_id ); ?>"
							name="<?php echo esc_attr( $jobo_name ); ?>"
							value="<?php echo esc_attr( (string) wp_json_encode( array_values( $jobo_saved ) ) ); ?>" />
						<noscript>
							<input type="text" class="jobo-input--mono" style="width:100%;max-width:640px"
								name="<?php echo esc_attr( $jobo_name ); ?>"
								value="<?php echo esc_attr( implode( ', ', $jobo_plain ) ); ?>" />
							<p class="jobo-field-desc"><?php esc_html_e( 'Comma-separated values.', 'career-site-jobs' ); ?></p>
						</noscript>
					</div>
				</div>
				<?php
			};

			/** One row of checkboxes over a closed vocabulary. */
			$jobo_checkbox_row = static function ( string $key, string $label, array $options, string $desc = '' ) use ( $settings ): void {
				$jobo_selected = (array) ( $settings[ $key ] ?? array() );
				?>
				<div class="jobo-field-row">
					<div class="jobo-field-label">
						<span class="jobo-field-name"><?php echo esc_html( $label ); ?></span>
						<?php if ( '' !== $desc ) : ?>
							<p class="jobo-field-desc"><?php echo esc_html( $desc ); ?></p>
						<?php endif; ?>
					</div>
					<div class="jobo-field-control">
						<fieldset class="jobo-checkbox-group">
							<legend class="screen-reader-text"><?php echo esc_html( $label ); ?></legend>
							<?php foreach ( $options as $jobo_option ) : ?>
								<label>
									<input type="checkbox"
										name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME . '[' . $key . '][]' ); ?>"
										value="<?php echo esc_attr( $jobo_option ); ?>"
										<?php checked( in_array( $jobo_option, $jobo_selected, true ) ); ?> />
									<?php echo esc_html( $jobo_option ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</div>
				</div>
				<?php
			};
			?>

			<div class="jobo-field-row">
				<div class="jobo-field-label">
					<label for="jobo_locations"><?php esc_html_e( 'Locations', 'career-site-jobs' ); ?></label>
					<p class="jobo-field-desc">
						<?php esc_html_e( 'Use "Country", "Region, Country", or "City, Region, Country" — for example: Germany / Bavaria, Germany / Berlin, Berlin, Germany. For a city with no region, leave the middle empty: Singapore, , Singapore.', 'career-site-jobs' ); ?>
					</p>
				</div>
				<div class="jobo-field-control">
					<div id="jobo-locations-cb" class="jobo-cb-mount"></div>
					<?php
					// admin.js hides this textarea (class jobo-visually-hidden,
					// clip technique — never display:none) and mirrors one
					// location line per chip back into it, so it remains the
					// submitted field and Jobo_Settings::parse_locations() is
					// untouched. Without JavaScript it stays visible.
					?>
					<textarea id="jobo_locations" rows="4" class="jobo-locations-textarea"
						name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME ); ?>[locations]"><?php echo esc_textarea( (string) $settings['locations'] ); ?></textarea>
					<noscript>
						<p class="jobo-field-desc"><?php esc_html_e( 'Enter one location per line.', 'career-site-jobs' ); ?></p>
					</noscript>
				</div>
			</div>

			<div class="jobo-field-row">
				<div class="jobo-field-label">
					<span class="jobo-field-name" id="jobo-sources-label"><?php esc_html_e( 'Sources', 'career-site-jobs' ); ?></span>
					<p class="jobo-field-desc"><?php esc_html_e( 'ATS sources, for example: greenhouse, lever_co, ashby. Leave blank for all.', 'career-site-jobs' ); ?></p>
				</div>
				<div class="jobo-field-control">
					<div id="jobo-sources-cb" class="jobo-cb-mount"></div>
					<?php
					// The hidden input is what submits when JavaScript is on;
					// the <noscript> text input renders after it, so on a no-JS
					// submit PHP keeps the later (visible) field's value.
					?>
					<input type="hidden" id="jobo_sources"
						name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME ); ?>[sources]"
						value="<?php echo esc_attr( implode( ',', (array) $settings['sources'] ) ); ?>" />
					<noscript>
						<input type="text" id="jobo_sources_noscript" class="jobo-input--mono" style="width:100%;max-width:640px"
							name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME ); ?>[sources]"
							value="<?php echo esc_attr( implode( ', ', (array) $settings['sources'] ) ); ?>" />
						<p class="jobo-field-desc"><?php esc_html_e( 'Comma-separated source keys.', 'career-site-jobs' ); ?></p>
					</noscript>
				</div>
			</div>

			<?php
			$jobo_picker_row( 'companies', __( 'Companies', 'career-site-jobs' ), __( 'Only jobs at these companies. Start typing a name or website domain; a picked company covers all of its careers boards.', 'career-site-jobs' ), 'companies', __( 'Type a company name or domain, for example stripe', 'career-site-jobs' ), true );
			$jobo_picker_row( 'occupation_ids', __( 'Occupations', 'career-site-jobs' ), __( 'Only jobs classified as these roles, for example Registered Nurse.', 'career-site-jobs' ), 'occupations', __( 'Type a role, for example nurse', 'career-site-jobs' ), true );
			$jobo_picker_row( 'job_categories', __( 'Job categories', 'career-site-jobs' ), __( 'Broad occupation groups, for example Healthcare.', 'career-site-jobs' ), 'facet:job_categories', __( 'Type to filter categories', 'career-site-jobs' ) );
			$jobo_picker_row( 'job_subcategories', __( 'Job subcategories', 'career-site-jobs' ), __( 'Narrower groups, for example Nursing.', 'career-site-jobs' ), 'facet:job_subcategories', __( 'Type to filter subcategories', 'career-site-jobs' ) );
			$jobo_picker_row( 'languages', __( 'Languages', 'career-site-jobs' ), __( 'The language a job is written in. Leave blank for all.', 'career-site-jobs' ), 'static:languages', __( 'Type a language, for example German', 'career-site-jobs' ) );

			$jobo_checkbox_row( 'work_models', __( 'Work model', 'career-site-jobs' ), Jobo_Settings::WORK_MODELS );
			$jobo_checkbox_row( 'employment_types', __( 'Employment type', 'career-site-jobs' ), Jobo_Settings::EMPLOYMENT_TYPES );
			$jobo_checkbox_row( 'experience_levels', __( 'Experience level', 'career-site-jobs' ), Jobo_Settings::EXPERIENCE_LEVELS );
			?>

			<div class="jobo-field-row">
				<div class="jobo-field-label">
					<label for="jobo_posted_after"><?php esc_html_e( 'Posted on or after', 'career-site-jobs' ); ?></label>
					<p class="jobo-field-desc"><?php esc_html_e( 'Leave out jobs posted before this date. Leave blank for all.', 'career-site-jobs' ); ?></p>
				</div>
				<div class="jobo-field-control">
					<input type="date" id="jobo_posted_after"
						name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME ); ?>[posted_after]"
						value="<?php echo esc_attr( (string) $settings['posted_after'] ); ?>" />
					<br />
					<label class="jobo-inline-check">
						<input type="checkbox" name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME ); ?>[include_reactivated]" value="1" <?php checked( ! empty( $settings['include_reactivated'] ) ); ?> />
						<?php esc_html_e( 'Also import older jobs that reopened after this date', 'career-site-jobs' ); ?>
					</label>
				</div>
			</div>

			<details class="jobo-details" <?php echo ( ! empty( $settings['industries'] ) || ! empty( $settings['company_primary_industries'] ) || ! empty( $settings['company_categories'] ) || ! empty( $settings['company_sizes'] ) || ! empty( $settings['company_revenues'] ) || '' !== $settings['company_is_agency'] ) ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Company filters', 'career-site-jobs' ); ?></summary>
				<?php
				$jobo_picker_row( 'industries', __( 'Industries', 'career-site-jobs' ), __( 'Jobs at companies in any of these industries.', 'career-site-jobs' ), 'facet:industries', __( 'Type to filter industries', 'career-site-jobs' ) );
				$jobo_picker_row( 'company_primary_industries', __( 'Primary industry', 'career-site-jobs' ), __( 'Jobs at companies whose main industry is one of these.', 'career-site-jobs' ), 'facet:industries', __( 'Type to filter industries', 'career-site-jobs' ) );
				$jobo_checkbox_row( 'company_categories', __( 'Business model', 'career-site-jobs' ), Jobo_Settings::COMPANY_CATEGORIES );
				$jobo_checkbox_row( 'company_sizes', __( 'Company size (employees)', 'career-site-jobs' ), Jobo_Settings::COMPANY_SIZES, __( 'Companies of unknown size are left out.', 'career-site-jobs' ) );
				$jobo_checkbox_row( 'company_revenues', __( 'Annual revenue (USD)', 'career-site-jobs' ), Jobo_Settings::COMPANY_REVENUES, __( 'Companies of unknown revenue are left out.', 'career-site-jobs' ) );
				?>
				<div class="jobo-field-row">
					<div class="jobo-field-label">
						<label for="jobo_company_is_agency"><?php esc_html_e( 'Staffing agencies', 'career-site-jobs' ); ?></label>
						<p class="jobo-field-desc"><?php esc_html_e( 'Jobs that recruitment and staffing agencies post for other employers.', 'career-site-jobs' ); ?></p>
					</div>
					<div class="jobo-field-control">
						<select id="jobo_company_is_agency" name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME ); ?>[company_is_agency]">
							<?php
							foreach ( array(
								''     => __( 'Include them', 'career-site-jobs' ),
								'none' => __( 'Leave them out', 'career-site-jobs' ),
								'only' => __( 'Only agency jobs', 'career-site-jobs' ),
							) as $jobo_value => $jobo_label ) :
								?>
								<option value="<?php echo esc_attr( $jobo_value ); ?>" <?php selected( $settings['company_is_agency'], $jobo_value ); ?>>
									<?php echo esc_html( $jobo_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			</details>

			<?php
			$jobo_has_excludes = false;
			foreach ( array_merge( Jobo_Settings::TEXT_LISTS, Jobo_Settings::LABELLED_LISTS, array_keys( Jobo_Settings::choice_lists() ) ) as $jobo_key ) {
				if ( 0 === strpos( $jobo_key, 'exclude_' ) && ! empty( $settings[ $jobo_key ] ) ) {
					$jobo_has_excludes = true;
				}
			}
			?>
			<details class="jobo-details" <?php echo $jobo_has_excludes ? 'open' : ''; ?>>
				<summary>
					<?php esc_html_e( 'Exclude', 'career-site-jobs' ); ?>
					<span class="jobo-field-desc"><?php esc_html_e( 'Leave out jobs that match any of these, whatever the filters above include.', 'career-site-jobs' ); ?></span>
				</summary>
				<?php
				$jobo_picker_row( 'exclude_companies', __( 'Companies', 'career-site-jobs' ), '', 'companies', __( 'Type a company name or domain', 'career-site-jobs' ), true );
				$jobo_picker_row( 'exclude_occupation_ids', __( 'Occupations', 'career-site-jobs' ), '', 'occupations', __( 'Type a role', 'career-site-jobs' ), true );
				$jobo_picker_row( 'exclude_job_categories', __( 'Job categories', 'career-site-jobs' ), '', 'facet:job_categories', __( 'Type to filter categories', 'career-site-jobs' ) );
				$jobo_picker_row( 'exclude_job_subcategories', __( 'Job subcategories', 'career-site-jobs' ), '', 'facet:job_subcategories', __( 'Type to filter subcategories', 'career-site-jobs' ) );
				$jobo_picker_row( 'exclude_industries', __( 'Industries', 'career-site-jobs' ), '', 'facet:industries', __( 'Type to filter industries', 'career-site-jobs' ) );
				$jobo_picker_row( 'exclude_company_primary_industries', __( 'Primary industry', 'career-site-jobs' ), '', 'facet:industries', __( 'Type to filter industries', 'career-site-jobs' ) );
				$jobo_picker_row( 'exclude_sources', __( 'Sources', 'career-site-jobs' ), '', 'sources', __( 'Type to filter sources', 'career-site-jobs' ) );
				$jobo_picker_row( 'exclude_languages', __( 'Languages', 'career-site-jobs' ), '', 'static:languages', __( 'Type a language', 'career-site-jobs' ) );
				$jobo_checkbox_row( 'exclude_work_models', __( 'Work model', 'career-site-jobs' ), Jobo_Settings::WORK_MODELS );
				$jobo_checkbox_row( 'exclude_employment_types', __( 'Employment type', 'career-site-jobs' ), Jobo_Settings::EMPLOYMENT_TYPES );
				$jobo_checkbox_row( 'exclude_experience_levels', __( 'Experience level', 'career-site-jobs' ), Jobo_Settings::EXPERIENCE_LEVELS );
				$jobo_checkbox_row( 'exclude_company_categories', __( 'Business model', 'career-site-jobs' ), Jobo_Settings::COMPANY_CATEGORIES );
				$jobo_checkbox_row( 'exclude_company_sizes', __( 'Company size (employees)', 'career-site-jobs' ), Jobo_Settings::COMPANY_SIZES );
				$jobo_checkbox_row( 'exclude_company_revenues', __( 'Annual revenue (USD)', 'career-site-jobs' ), Jobo_Settings::COMPANY_REVENUES );
				?>
			</details>
		</section>

		<p>
			<button type="submit" class="jobo-btn jobo-btn--primary"><?php esc_html_e( 'Save changes', 'career-site-jobs' ); ?></button>
		</p>
	</form>
</div>

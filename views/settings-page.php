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
 * @var array<string,mixed> $settings Current settings.
 * @var array<string,mixed> $state    Persisted sync state.
 * @var Jobo_Target         $target   Target that will actually be written.
 * @var bool                $has_key  Whether an API key is configured.
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
	<?php endif; ?>

	<?php if ( ! $has_key ) : ?>
		<div class="jobo-banner jobo-banner--neutral">
			<p>
				<?php
				printf(
					wp_kses(
						/* translators: %s: Jobo API keys URL. */
						__( 'Add your Jobo API key below to start importing jobs. <a href="%s" target="_blank" rel="noopener">Create a key</a> — it takes about a minute.', 'career-site-jobs' ),
						array(
							'a' => array(
								'href'   => array(),
								'target' => array(),
								'rel'    => array(),
							),
						)
					),
					'https://enterprise.jobo.world/api-keys'
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
					echo '' === (string) $state['feed_cursor']
						? esc_html__( 'Idle — the next run starts a fresh scan.', 'career-site-jobs' )
						: esc_html__( 'Part-way through a scan; the next run resumes where it stopped.', 'career-site-jobs' );
					?>
				</div>
			</div>
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
					<div class="jobo-defgrid-label"><?php esc_html_e( 'Shared job allowance', 'career-site-jobs' ); ?></div>
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
					<div class="jobo-defgrid-label"><?php esc_html_e( 'Credit balance', 'career-site-jobs' ); ?></div>
					<div class="jobo-defgrid-value jobo-num">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: credit count, 2: approximate dollar value. */
								__( '%1$s credits (about $%2$s)', 'career-site-jobs' ),
								$jobo_fmt( (int) $state['credits_balance'] ),
								number_format_i18n( (int) $state['credits_balance'] / 1000, 2 )
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
				<?php esc_html_e( 'Resyncing walks the whole feed again from the start. Existing listings are matched on their Jobo job ID and updated in place, so nothing is duplicated.', 'career-site-jobs' ); ?>
			</p>
		<?php endif; ?>
	</section>

	<form method="post" action="options.php">
		<?php settings_fields( Jobo_Settings::OPTION_GROUP ); ?>

		<section class="jobo-section">
			<h2 class="jobo-section-title"><?php esc_html_e( 'Connection', 'career-site-jobs' ); ?></h2>

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
							'https://enterprise.jobo.world/api-keys'
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
					<label for="jobo_expire_action"><?php esc_html_e( 'When a job closes', 'career-site-jobs' ); ?></label>
					<p class="jobo-field-desc"><?php esc_html_e( 'Checking for closed jobs never costs credits.', 'career-site-jobs' ); ?></p>
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
					<label for="jobo_sync_interval"><?php esc_html_e( 'Sync frequency', 'career-site-jobs' ); ?></label>
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
					<p class="jobo-field-desc"><?php esc_html_e( 'These multiply into the most jobs one run will import. Jobs use your shared Job Search allowance first, then its tier rate; direct access is $3 per 1,000 at public list price, and Jobs Feed makes Feed imports unlimited.', 'career-site-jobs' ); ?></p>
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
				<?php esc_html_e( 'Leave everything blank to import all jobs. Narrowing keeps your board relevant and usage predictable; shared allowance applies first, and Jobs Feed imports are unlimited.', 'career-site-jobs' ); ?>
			</p>
			<?php
			// Deliberately no skills or industries fields here: the feed
			// endpoint only accepts the keys listed in feed_filter_keys
			// (JOB_FEED_FILTER_KEYS) in Jobo.Connectors/contracts/filters.json
			// — skills and industries are search-only filters.
			?>

			<div class="jobo-field-row">
				<div class="jobo-field-label">
					<label for="jobo_locations"><?php esc_html_e( 'Locations', 'career-site-jobs' ); ?></label>
					<p class="jobo-field-desc">
						<?php esc_html_e( 'Use "Country", "Region, Country", or "City, Region, Country" — for example: Germany / Bavaria, Germany / Berlin, Berlin, Germany', 'career-site-jobs' ); ?>
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
			$jobo_checkbox_groups = array(
				'work_models'       => array( __( 'Work model', 'career-site-jobs' ), Jobo_Settings::WORK_MODELS ),
				'employment_types'  => array( __( 'Employment type', 'career-site-jobs' ), Jobo_Settings::EMPLOYMENT_TYPES ),
				'experience_levels' => array( __( 'Experience level', 'career-site-jobs' ), Jobo_Settings::EXPERIENCE_LEVELS ),
			);
			foreach ( $jobo_checkbox_groups as $jobo_key => $jobo_group ) :
				list( $jobo_label, $jobo_options ) = $jobo_group;
				$jobo_selected                     = (array) $settings[ $jobo_key ];
				?>
				<div class="jobo-field-row">
					<div class="jobo-field-label">
						<span class="jobo-field-name"><?php echo esc_html( $jobo_label ); ?></span>
					</div>
					<div class="jobo-field-control">
						<fieldset class="jobo-checkbox-group">
							<legend class="screen-reader-text"><?php echo esc_html( $jobo_label ); ?></legend>
							<?php foreach ( $jobo_options as $jobo_option ) : ?>
								<label>
									<input type="checkbox"
										name="<?php echo esc_attr( Jobo_Settings::OPTION_NAME . '[' . $jobo_key . '][]' ); ?>"
										value="<?php echo esc_attr( $jobo_option ); ?>"
										<?php checked( in_array( $jobo_option, $jobo_selected, true ) ); ?> />
									<?php echo esc_html( $jobo_option ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</div>
				</div>
			<?php endforeach; ?>
		</section>

		<p>
			<button type="submit" class="jobo-btn jobo-btn--primary"><?php esc_html_e( 'Save changes', 'career-site-jobs' ); ?></button>
		</p>
	</form>
</div>

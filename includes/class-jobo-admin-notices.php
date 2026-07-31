<?php
/**
 * Admin notices for sync failures.
 *
 * Sync runs unattended under WP-Cron, so a failure has to surface somewhere the
 * site owner will actually see it. Billing and auth failures get their own
 * wording and a direct link, because "sync failed" is not actionable and
 * "you're out of credits, top up here" is.
 *
 * @package Jobo_Jobs
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

class Jobo_Admin_Notices {

	/** @var Jobo_State */
	private $state;

	public function __construct( Jobo_State $state ) {
		$this->state = $state;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status  = (string) $this->state->get( 'last_sync_status', '' );
		$message = (string) $this->state->get( 'last_error', '' );

		if ( 'error' !== $status || '' === $message ) {
			return;
		}

		$settings_url = admin_url( 'options-general.php?page=jobo-jobs' );

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p>%s</p></div>',
			esc_html__( 'Jobo Jobs:', 'jobo-jobs' ),
			esc_html( $message ),
			wp_kses_post(
				sprintf(
					/* translators: 1: settings page URL, 2: Jobo dashboard URL. */
					__( '<a href="%1$s">Review your Jobo settings</a> or <a href="%2$s" target="_blank" rel="noopener">check your balance and API key</a>.', 'jobo-jobs' ),
					esc_url( $settings_url ),
					'https://enterprise.jobo.world/api-keys'
				)
			)
		);
	}
}

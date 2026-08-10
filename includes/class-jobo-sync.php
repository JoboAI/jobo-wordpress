<?php
/**
 * Incremental sync.
 *
 * Two passes per run:
 *
 *  1. `POST /api/jobs/feed` walked with a persisted cursor, so each run resumes
 *     where the last one stopped rather than re-scanning from the top. Jobs are
 *     upserted by Jobo job id, which makes the feed's deliberate 5-minute
 *     watermark overlap harmless.
 *  2. `GET /api/jobs/expired`, which never costs credits on any tier, to retire
 *     listings whose jobs have closed.
 *
 * @package Jobo_Jobs
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

class Jobo_Sync {

	const CRON_HOOK = 'jobo_jobs_sync';

	/**
	 * The API rejects `expired_since` older than 7 days, so the expiry pass is
	 * clamped just inside that.
	 */
	const MAX_EXPIRED_LOOKBACK = 6 * DAY_IN_SECONDS;

	/** Leave headroom under max_execution_time rather than dying mid-batch. */
	const TIME_BUDGET_SECONDS = 45;

	/** @var Jobo_State */
	private $state;

	public function __construct( Jobo_State $state ) {
		$this->state = $state;
	}

	/**
	 * Resolve the configured target, falling back when unavailable.
	 */
	public function resolve_target(): Jobo_Target {
		$preference = (string) Jobo_Settings::value( 'target', 'auto' );
		$wpjm       = new Jobo_WPJM_Target();

		if ( 'wpjm' === $preference || 'auto' === $preference ) {
			if ( $wpjm->is_available() ) {
				return $wpjm;
			}
		}

		return new Jobo_CPT_Target();
	}

	/**
	 * Run one sync pass.
	 *
	 * Never throws — every failure is recorded in state and surfaced as an
	 * admin notice, because this usually runs unattended under WP-Cron.
	 *
	 * @return array<string,mixed> Summary of the run.
	 */
	public function run(): array {
		$settings = Jobo_Settings::get();
		$summary  = array(
			'created' => 0,
			'updated' => 0,
			'expired' => 0,
			'batches' => 0,
			'status'  => 'ok',
			'message' => '',
		);

		if ( '' === $settings['api_key'] ) {
			$summary['status']  = 'skipped';
			$summary['message'] = __( 'No API key configured.', 'career-site-jobs' );
			return $summary;
		}

		$client = new Jobo_Client( $settings['api_key'] );
		$target = $this->resolve_target();
		$target->prepare();

		try {
			$this->import( $client, $target, $settings, $summary );
			$this->retire( $client, $target, $settings, $summary );
			$this->state->clear_error();
		} catch ( Jobo_Cursor_Restart_Exception $e ) {
			// The held cursor is void. Drop it so the next run starts a clean
			// scan; upserts are idempotent so nothing is duplicated.
			$this->state->reset_cursor();
			$summary['status']  = 'restart';
			$summary['message'] = __( 'The feed cursor expired. A full resync will run on the next scheduled sync.', 'career-site-jobs' );
			$this->state->record_error( $summary['message'] );
		} catch ( Jobo_Insufficient_Credits_Exception $e ) {
			$summary['status']  = 'payment_required';
			$summary['message'] = $e->getMessage();
			$this->state->record_error( $e->getMessage() );
		} catch ( Jobo_Auth_Exception $e ) {
			$summary['status']  = 'auth';
			$summary['message'] = $e->getMessage();
			$this->state->record_error( $e->getMessage() );
		} catch ( Jobo_Api_Exception $e ) {
			$summary['status']  = 'error';
			$summary['message'] = $e->getMessage();
			$this->state->record_error( $e->getMessage() );
		}

		$usage = $client->get_last_usage();

		$this->state->update(
			array(
				'last_sync_at'      => time(),
				'credits_balance'   => $usage['credits_balance'] ?? $this->state->get( 'credits_balance' ),
				'quota_limit'       => $usage['quota_limit'] ?? $this->state->get( 'quota_limit' ),
				'quota_remaining'   => $usage['quota_remaining'] ?? $this->state->get( 'quota_remaining' ),
				'last_run_imported' => $summary['created'],
				'last_run_updated'  => $summary['updated'],
				'last_run_expired'  => $summary['expired'],
				'imported_total'    => (int) $this->state->get( 'imported_total', 0 ) + $summary['created'],
				'updated_total'     => (int) $this->state->get( 'updated_total', 0 ) + $summary['updated'],
				'expired_total'     => (int) $this->state->get( 'expired_total', 0 ) + $summary['expired'],
			)
		);

		return $summary;
	}

	/**
	 * Walk the feed from the persisted cursor.
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @param array<string,mixed> $summary  Run summary, mutated in place.
	 * @throws Jobo_Api_Exception On an unrecoverable API failure.
	 */
	private function import( Jobo_Client $client, Jobo_Target $target, array $settings, array &$summary ): void {
		$params      = Jobo_Settings::to_feed_params();
		$cursor      = (string) $this->state->get( 'feed_cursor', '' );
		$max_batches = (int) $settings['max_batches'];
		$started     = time();

		for ( $batch = 0; $batch < $max_batches; $batch++ ) {
			$request = $params;
			if ( '' !== $cursor ) {
				$request['cursor'] = $cursor;
			}

			$response = $client->feed( $request );
			$summary['batches']++;

			$jobs = isset( $response['jobs'] ) && is_array( $response['jobs'] ) ? $response['jobs'] : array();

			// One lookup query for the whole batch instead of one per job.
			$target->prime_lookup(
				array_values(
					array_filter(
						array_map(
							static function ( $job ) {
								return is_array( $job ) && isset( $job['id'] ) ? (string) $job['id'] : '';
							},
							$jobs
						),
						'strlen'
					)
				)
			);

			foreach ( $jobs as $job ) {
				if ( ! is_array( $job ) ) {
					continue;
				}

				$action = $target->upsert( $job, (string) $settings['post_status'] );
				if ( 'created' === $action ) {
					$summary['created']++;
				} elseif ( 'updated' === $action ) {
					$summary['updated']++;
				}
			}

			$next     = isset( $response['next_cursor'] ) ? (string) $response['next_cursor'] : '';
			$has_more = ! empty( $response['has_more'] );

			if ( ! $has_more || '' === $next ) {
				// Scan complete. Clearing the cursor makes the next run start a
				// fresh pass, which is how new jobs get picked up.
				$cursor = '';
				break;
			}

			$cursor = $next;

			if ( ( time() - $started ) >= self::TIME_BUDGET_SECONDS ) {
				// Out of budget: keep the cursor so the next run resumes here.
				break;
			}
		}

		$this->state->update( array( 'feed_cursor' => $cursor ) );
	}

	/**
	 * Retire listings whose jobs have closed. Free on every tier.
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @param array<string,mixed> $summary  Run summary, mutated in place.
	 * @throws Jobo_Api_Exception On an unrecoverable API failure.
	 */
	private function retire( Jobo_Client $client, Jobo_Target $target, array $settings, array &$summary ): void {
		if ( 'keep' === $settings['expire_action'] ) {
			return;
		}

		$since = $this->expired_since();
		$cursor = '';
		$pages  = 0;

		do {
			$request = array(
				'expired_since' => $since,
				'batch_size'    => 1000,
			);
			if ( '' !== $cursor ) {
				$request['cursor'] = $cursor;
			}

			$response = $client->expired_job_ids( $request );
			$ids      = isset( $response['job_ids'] ) && is_array( $response['job_ids'] ) ? $response['job_ids'] : array();

			// The expired feed returns ids for the whole index, most of which
			// this site never imported. Priming resolves the batch in one query
			// so the misses cost nothing.
			$target->prime_lookup( array_map( 'strval', $ids ) );

			foreach ( $ids as $job_id ) {
				if ( $target->expire( sanitize_text_field( (string) $job_id ), (string) $settings['expire_action'] ) ) {
					$summary['expired']++;
				}
			}

			$cursor = isset( $response['next_cursor'] ) ? (string) $response['next_cursor'] : '';
			$pages++;
		} while ( ! empty( $response['has_more'] ) && '' !== $cursor && $pages < 10 );

		$this->state->update( array( 'expired_since' => gmdate( 'c' ) ) );
	}

	/**
	 * Timestamp for the expiry pass, clamped inside the API's 7-day window.
	 *
	 * A site whose cron has been dormant for over a week will have missed some
	 * expirations; a manual full resync is the recovery path.
	 */
	private function expired_since(): string {
		$stored = (string) $this->state->get( 'expired_since', '' );
		$oldest = time() - self::MAX_EXPIRED_LOOKBACK;

		if ( '' === $stored ) {
			return gmdate( 'c', time() - DAY_IN_SECONDS );
		}

		$parsed = strtotime( $stored );
		if ( false === $parsed || $parsed < $oldest ) {
			return gmdate( 'c', $oldest );
		}

		return gmdate( 'c', $parsed );
	}
}

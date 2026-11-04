<?php
/**
 * Incremental sync.
 *
 * Each run:
 *
 *  1. `GET /api/jobs/expired`, which never costs credits on any tier, retires
 *     listings whose jobs have closed. It runs first so a failed import (an
 *     empty wallet, say) never holds it up.
 *  2. `POST /api/jobs/feed`, walked as a pass with a persisted cursor. The
 *     first request of a pass sends `updated_after` from the watermark, the
 *     time the last completed pass began, so once the first import is done a
 *     pass delivers only new or changed jobs. Every delivered job is billed,
 *     so a pass that re-read the whole feed would bill the whole board again.
 *  3. After a recovery pass (see recover_expiry_gap()), retire the listings
 *     that pass did not deliver.
 *
 * Jobs are upserted by Jobo job id, which makes the watermark overlap
 * harmless.
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

	/**
	 * How far a pass's `updated_after` reaches back before the previous pass
	 * began. An update can reach the feed a few minutes after its timestamp,
	 * and a job missed here would not arrive until it changed again.
	 */
	const WATERMARK_OVERLAP = 15 * MINUTE_IN_SECONDS;

	/** Listings a recovery reconcile retires per run, to stay inside the time budget. */
	const RECONCILE_LIMIT = 500;

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
			$this->recover_expiry_gap( $settings );
			$this->retire( $client, $target, $settings, $summary );
			$this->import( $client, $target, $settings, $summary );
			$this->reconcile( $target, $settings, $summary );
			$this->state->clear_error();
		} catch ( Jobo_Cursor_Restart_Exception $e ) {
			// The held cursor is void. Drop it so the next run starts the pass
			// over. The watermark is kept, so that is not a full rescan, and
			// upserts are idempotent so nothing is duplicated.
			$this->state->reset_cursor();
			$summary['status']  = 'restart';
			$summary['message'] = __( 'The feed cursor expired. The next scheduled sync starts the scan again.', 'career-site-jobs' );
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
	 * Walk the feed from the persisted cursor, starting a new pass when none is
	 * held.
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

		// The cursor carries the pass's filters and `updated_after`, so they
		// are only sent on a pass's first request.
		$since = '' === $cursor ? $this->start_pass( $params, $started ) : '';

		for ( $batch = 0; $batch < $max_batches; $batch++ ) {
			$request = $params;
			if ( '' !== $cursor ) {
				$request['cursor'] = $cursor;
			} elseif ( '' !== $since ) {
				$request['updated_after'] = $since;
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
				$this->finish_pass();
				return;
			}

			// Checkpoint after every batch: these jobs are already billed, and
			// a failure later in this run must not deliver them again.
			$cursor = $next;
			$this->state->update( array( 'feed_cursor' => $cursor ) );

			if ( ( time() - $started ) >= self::TIME_BUDGET_SECONDS ) {
				// Out of budget: the next run resumes from the cursor.
				break;
			}
		}
	}

	/**
	 * Record a new pass and decide whether it is incremental.
	 *
	 * A watermark only covers the filters it was earned under: after a filter
	 * change, jobs that match the new filters but have not changed lately would
	 * never arrive, so the pass is a full one. The watermark is dropped here,
	 * not when the pass ends, so a restarted pass stays full too.
	 *
	 * @param array<string,mixed> $params Feed request body.
	 * @return string `updated_after` for the first request, or '' for a full pass.
	 */
	private function start_pass( array $params, int $now ): string {
		$filters   = self::filters_fingerprint( $params );
		$watermark = (string) $this->state->get( 'feed_watermark', '' );

		if ( $filters !== (string) $this->state->get( 'feed_filters', '' ) ) {
			$watermark = '';
		}

		$this->state->update(
			array(
				'feed_watermark'  => $watermark,
				'feed_filters'    => $filters,
				'pass_started_at' => $now,
			)
		);

		return self::updated_after( $watermark );
	}

	/**
	 * The scan is complete: the next pass asks only for jobs changed since this
	 * one began.
	 */
	private function finish_pass(): void {
		$started = (int) $this->state->get( 'pass_started_at', 0 );
		$changes = array(
			'feed_cursor'    => '',
			// 0 means a pass carried over from before the watermark existed.
			// Its start is unknown, so the next pass is a full one.
			'feed_watermark' => $started > 0 ? gmdate( 'c', $started ) : '',
		);

		if ( $this->state->get( 'reconcile_pending' ) ) {
			$changes['reconcile_pending'] = false;
			$changes['reconcile_before']  = $started;
		}

		$this->state->update( $changes );
	}

	/**
	 * Stable fingerprint of the filters a pass runs under. `batch_size` is
	 * left out: it changes how a pass is paged, not which jobs it delivers.
	 *
	 * @param array<string,mixed> $params Feed request body.
	 */
	public static function filters_fingerprint( array $params ): string {
		unset( $params['batch_size'] );
		ksort( $params );

		return md5( (string) wp_json_encode( $params ) );
	}

	/**
	 * `updated_after` for a pass, given the watermark the last completed pass
	 * left. Empty when there is none, which makes the pass a full one.
	 */
	public static function updated_after( string $watermark ): string {
		$parsed = '' === $watermark ? false : strtotime( $watermark );

		return false === $parsed ? '' : gmdate( 'c', $parsed - self::WATERMARK_OVERLAP );
	}

	/**
	 * Recover a site whose sync stalled for longer than the expiry lookback.
	 *
	 * `GET /api/jobs/expired` reaches back 7 days at most, so closures older
	 * than that are gone for good. One full pass, followed by reconcile(),
	 * retires the listings it did not deliver instead. Skipped while a recovery
	 * is already under way, so a run that keeps failing cannot restart the
	 * pass (and re-bill it) every time.
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 */
	private function recover_expiry_gap( array $settings ): void {
		if ( 'keep' === $settings['expire_action'] || $this->state->get( 'reconcile_pending' ) ) {
			return;
		}

		$stored = strtotime( (string) $this->state->get( 'expired_since', '' ) );
		if ( false === $stored || $stored >= time() - self::MAX_EXPIRED_LOOKBACK ) {
			return;
		}

		$this->state->update(
			array(
				'feed_cursor'       => '',
				'feed_watermark'    => '',
				'reconcile_pending' => true,
			)
		);
	}

	/**
	 * After a recovery pass, retire the listings it did not deliver: their jobs
	 * closed while the sync was stalled, or no longer match the filters.
	 *
	 * Later passes only move a listing's sync time forward, so "last synced
	 * before the recovery pass began" stays true of exactly those listings
	 * while this works through them over several runs.
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @param array<string,mixed> $summary  Run summary, mutated in place.
	 */
	private function reconcile( Jobo_Target $target, array $settings, array &$summary ): void {
		$before = (int) $this->state->get( 'reconcile_before', 0 );
		if ( $before <= 0 || 'keep' === $settings['expire_action'] ) {
			return;
		}

		$ids = $target->stale_job_ids( $before, self::RECONCILE_LIMIT );
		$target->prime_lookup( $ids );

		foreach ( $ids as $job_id ) {
			if ( $target->expire( $job_id, (string) $settings['expire_action'] ) ) {
				$summary['expired']++;
			}
		}

		if ( count( $ids ) < self::RECONCILE_LIMIT ) {
			$this->state->update( array( 'reconcile_before' => 0 ) );
		}
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
	 * A site whose cron has been dormant for longer will have missed some
	 * expirations; recover_expiry_gap() catches those up.
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

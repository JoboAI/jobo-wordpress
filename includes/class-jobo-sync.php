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
 *     pass delivers only new or changed jobs. The feed bills a job only when
 *     this account has not received it in the last 30 days; delivering it
 *     again inside that window is free. A pass that re-read the whole feed
 *     would still bill every matching job older than that window, and is
 *     slow, so passes stay incremental.
 *  3. After a full pass that follows a long stall or a filter change (see
 *     recover_expiry_gap() and start_pass()), retire the listings that pass
 *     did not deliver.
 *
 * Jobs are upserted by Jobo job id, which makes the watermark overlap
 * harmless. One run at a time holds a lock, so a manual sync cannot overlap a
 * scheduled one.
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

	/**
	 * The oldest `expired_since` an expiry pass in progress may continue
	 * under: the API's 7 days, less an hour of headroom. A pass begins at
	 * most 6 days back, so it has about a day to finish.
	 */
	const EXPIRED_CURSOR_MAX_AGE = 7 * DAY_IN_SECONDS - 60 * MINUTE_IN_SECONDS;

	/** The largest page `GET /api/jobs/expired` serves. */
	const EXPIRED_BATCH_SIZE = 10000;

	/** Expiry pages one run reads at most; the rest wait for the next run. */
	const EXPIRED_PAGES_PER_RUN = 10;

	/** Seconds an expiry pass may take before it yields to the import. */
	const EXPIRED_TIME_BUDGET_SECONDS = 20;

	/** Ids resolved (and retired) per lookup query. */
	const EXPIRE_CHUNK = 1000;

	/** Leave headroom under max_execution_time rather than dying mid-batch. */
	const TIME_BUDGET_SECONDS = 45;

	/**
	 * Feed requests. The server gives a page up to 90 seconds and asks
	 * clients to wait at least 120; a page that slow is not helped by a
	 * third attempt.
	 */
	const FEED_TIMEOUT_SECONDS = 120;
	const FEED_MAX_ATTEMPTS    = 2;

	/**
	 * How far a pass's `updated_after` reaches back before the previous pass
	 * began. An update can reach the feed a few minutes after its timestamp,
	 * and a job missed here would not arrive until it changed again.
	 */
	const WATERMARK_OVERLAP = 15 * MINUTE_IN_SECONDS;

	/** Listings a recovery reconcile retires per run, to stay inside the time budget. */
	const RECONCILE_LIMIT = 500;

	/**
	 * Run lock: an option row holding the time the run began, inserted only
	 * when absent, so only one run gets it. A lock older than the TTL belongs
	 * to a run that died without releasing it, and is taken over.
	 */
	const LOCK_OPTION = 'jobo_jobs_sync_lock';
	const LOCK_TTL    = 15 * MINUTE_IN_SECONDS;

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
	 * A scheduled run does nothing until scheduled syncing is turned on. A
	 * manual run ("Sync now", "Resync everything") is the owner's explicit
	 * request and runs either way.
	 *
	 * @param bool $manual Started by the owner rather than by WP-Cron.
	 * @param bool $full   Drop the cursor and the watermark first, so every
	 *                     matching job is delivered again.
	 * @return array<string,mixed> Summary of the run. `status` is 'ok',
	 *                             'skipped' (see `reason`: no_key, disabled,
	 *                             locked), 'restart', 'payment_required',
	 *                             'auth' or 'error'.
	 */
	public function run( bool $manual = false, bool $full = false ): array {
		$settings = Jobo_Settings::get();
		$summary  = array(
			'created' => 0,
			'updated' => 0,
			'expired' => 0,
			'batches' => 0,
			'billed'  => 0,
			'credits' => 0,
			'status'  => 'ok',
			'reason'  => '',
			'message' => '',
		);

		if ( '' === $settings['api_key'] ) {
			$summary['status']  = 'skipped';
			$summary['reason']  = 'no_key';
			$summary['message'] = __( 'No API key configured.', 'career-site-jobs' );
			return $summary;
		}

		if ( ! $manual && empty( $settings['sync_enabled'] ) ) {
			$summary['status']  = 'skipped';
			$summary['reason']  = 'disabled';
			$summary['message'] = __( 'Scheduled sync is off. Choose which jobs to import, then turn on scheduled sync in Settings → Jobo Jobs.', 'career-site-jobs' );
			return $summary;
		}

		$lock = $this->acquire_lock();
		if ( 0 === $lock ) {
			$summary['status']  = 'skipped';
			$summary['reason']  = 'locked';
			$summary['message'] = __( 'Another sync is already running. Try again in a few minutes.', 'career-site-jobs' );
			return $summary;
		}

		try {
			// Another run may have moved the cursor since this request loaded
			// the state.
			$this->state->reload();

			if ( $full ) {
				$this->state->reset_scan();
			}

			$this->execute( $settings, $summary );
		} finally {
			$this->release_lock( $lock );
		}

		return $summary;
	}

	/**
	 * The run itself, under the lock.
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @param array<string,mixed> $summary  Run summary, mutated in place.
	 */
	private function execute( array $settings, array &$summary ): void {
		$client = new Jobo_Client( $settings['api_key'], Jobo_Client::DEFAULT_BASE_URL, self::FEED_TIMEOUT_SECONDS, self::FEED_MAX_ATTEMPTS );
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
				'last_run_billed'   => $summary['billed'],
				'last_run_credits'  => $summary['credits'],
				'imported_total'    => (int) $this->state->get( 'imported_total', 0 ) + $summary['created'],
				'updated_total'     => (int) $this->state->get( 'updated_total', 0 ) + $summary['updated'],
				'expired_total'     => (int) $this->state->get( 'expired_total', 0 ) + $summary['expired'],
			)
		);
	}

	/**
	 * Take the run lock.
	 *
	 * @return int The lock's timestamp, which releases it, or 0 when another
	 *             run holds it.
	 */
	private function acquire_lock(): int {
		global $wpdb;

		$now = time();

		// An insert that does nothing when the row exists, so of two runs
		// starting together exactly one gets the lock. Not add_option(): it
		// writes with ON DUPLICATE KEY UPDATE, so both runs would "add" it.
		// Autoload off: the lock is read only here, and always from the table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::LOCK_OPTION,
				(string) $now
			)
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );

		if ( 1 === $inserted ) {
			return $now;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$held = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::LOCK_OPTION
			)
		);
		if ( null === $held ) {
			// Released between the insert and this read: let the next run have it.
			return 0;
		}

		if ( $now - (int) $held < self::LOCK_TTL ) {
			return 0;
		}

		// A stale lock. Swap the stored value only if it is still the one
		// read above, so of two runs taking it over at once only one wins.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$swapped = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => (string) $now ),
			array(
				'option_name'  => self::LOCK_OPTION,
				'option_value' => (string) $held,
			)
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );

		return 1 === $swapped ? $now : 0;
	}

	/**
	 * Release the run lock, unless a later run has taken it over meanwhile.
	 *
	 * @param int $lock The timestamp acquire_lock() returned.
	 */
	private function release_lock( int $lock ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::LOCK_OPTION,
				'option_value' => (string) $lock,
			)
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );
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
		$filters     = self::filters_fingerprint( $params );
		$cursor      = (string) $this->state->get( 'feed_cursor', '' );
		$max_batches = (int) $settings['max_batches'];
		$started     = time();

		// The cursor carries the filters its pass began with, and the API
		// ignores filters sent beside a cursor. After a filter change the old
		// pass is abandoned, so the new filters apply from this run on rather
		// than once the old pass ends; start_pass() makes the new pass a full
		// one. A state saved before fingerprints existed has none to compare.
		$held_filters = (string) $this->state->get( 'feed_filters', '' );
		if ( '' !== $cursor && '' !== $held_filters && $filters !== $held_filters ) {
			$cursor = '';
			$this->state->reset_cursor();
		}

		// The cursor carries the pass's filters and `updated_after`, so they
		// are only sent on a pass's first request.
		$since = '' === $cursor ? $this->start_pass( $filters, $settings, $started ) : '';

		for ( $batch = 0; $batch < $max_batches; $batch++ ) {
			$request = $params;
			if ( '' !== $cursor ) {
				$request['cursor'] = $cursor;
			} elseif ( '' !== $since ) {
				$request['updated_after'] = $since;
			}

			$response = $this->feed_page( $client, $request );
			$summary['batches']++;

			$usage               = $client->get_last_usage();
			$summary['billed']  += (int) ( $usage['jobs_billed'] ?? 0 );
			$summary['credits'] += (int) ( $usage['credits_deducted'] ?? 0 );

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

			// Checkpoint after every batch, so a failure later in this run
			// resumes after these jobs instead of walking the pass again.
			$cursor = $next;
			$this->state->update( array( 'feed_cursor' => $cursor ) );

			if ( ( time() - $started ) >= self::TIME_BUDGET_SECONDS ) {
				// Out of budget: the next run resumes from the cursor.
				break;
			}
		}
	}

	/**
	 * One feed request. A 400 on a request that carried a cursor means the
	 * API cannot read the cursor (malformed, or minted by a format it no
	 * longer accepts), and the only way on is a new scan, as with a 409
	 * `feed_cursor_restart_required`.
	 *
	 * @param array<string,mixed> $request Feed request body.
	 * @return array<string,mixed>
	 * @throws Jobo_Api_Exception On an API failure.
	 */
	private function feed_page( Jobo_Client $client, array $request ): array {
		try {
			return $client->feed( $request );
		} catch ( Jobo_Api_Exception $e ) {
			if ( 400 === $e->get_status() && isset( $request['cursor'] ) ) {
				// The message was escaped when the client built the exception.
				throw new Jobo_Cursor_Restart_Exception( $e->getMessage(), 400, $e->get_error_code() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			throw $e;
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
	 * After a filter change the full pass is also followed by a reconcile
	 * (unless closed jobs are left published), which retires the listings it
	 * did not deliver: the ones that matched only the old filters.
	 *
	 * @param string              $filters  filters_fingerprint() of the request.
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return string `updated_after` for the first request, or '' for a full pass.
	 */
	private function start_pass( string $filters, array $settings, int $now ): string {
		$previous  = (string) $this->state->get( 'feed_filters', '' );
		$watermark = (string) $this->state->get( 'feed_watermark', '' );
		$changes   = array(
			'feed_filters'    => $filters,
			'pass_started_at' => $now,
		);

		if ( $filters !== $previous ) {
			$watermark = '';
			// No previous fingerprint means a first pass, or a state saved
			// before fingerprints existed: nothing to retire.
			if ( '' !== $previous && 'keep' !== $settings['expire_action'] ) {
				$changes['reconcile_pending'] = true;
			}
		}

		$changes['feed_watermark'] = $watermark;
		$this->state->update( $changes );

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
	 * Stable fingerprint of the filters a pass runs under. `batch_size` and
	 * `description_format` are left out: they change how a pass is paged and
	 * rendered, not which jobs it delivers — and a change here resets the
	 * watermark, which re-imports every matching job (billing the ones this
	 * account was not delivered in the last 30 days).
	 *
	 * @param array<string,mixed> $params Feed request body.
	 */
	public static function filters_fingerprint( array $params ): string {
		unset( $params['batch_size'], $params['description_format'] );
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
	 * pass every time.
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
	 * After a full pass that follows a long stall or a filter change, retire
	 * the listings it did not deliver: their jobs closed while the sync was
	 * stalled, or no longer match the filters.
	 *
	 * Later passes only move a listing's sync time forward, so "last synced
	 * before the full pass began" stays true of exactly those listings while
	 * this works through them over several runs.
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
	 * A pass walks every closure since the last completed pass began. It can
	 * span several runs: the cursor and the `expired_since` it was minted
	 * under are kept between them, and the watermark moves to the time the
	 * pass began only once the API reports no more pages, so no closure is
	 * skipped however many there are.
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @param array<string,mixed> $summary  Run summary, mutated in place.
	 * @throws Jobo_Api_Exception On an unrecoverable API failure.
	 */
	private function retire( Jobo_Client $client, Jobo_Target $target, array $settings, array &$summary ): void {
		if ( 'keep' === $settings['expire_action'] ) {
			return;
		}

		$now    = time();
		$cursor = (string) $this->state->get( 'expired_cursor', '' );
		$since  = (string) $this->state->get( 'expired_cursor_since', '' );
		$began  = (int) $this->state->get( 'expired_pass_started_at', 0 );

		// A held cursor continues its pass under the same expired_since. Once
		// that has slid outside the API's window the pass cannot go on, so it
		// starts over (and recover_expiry_gap() covers what the window lost).
		$since_time = '' === $since ? false : strtotime( $since );
		if ( '' !== $cursor && ( false === $since_time || $since_time < $now - self::EXPIRED_CURSOR_MAX_AGE || $began <= 0 ) ) {
			$cursor = '';
		}

		if ( '' === $cursor ) {
			$since = $this->expired_since();
			$began = $now;
		}

		for ( $page = 0; $page < self::EXPIRED_PAGES_PER_RUN; $page++ ) {
			$request = array(
				'expired_since' => $since,
				'batch_size'    => self::EXPIRED_BATCH_SIZE,
			);
			if ( '' !== $cursor ) {
				$request['cursor'] = $cursor;
			}

			try {
				$response = $client->expired_job_ids( $request );
			} catch ( Jobo_Api_Exception $e ) {
				if ( 400 === $e->get_status() && '' !== $cursor ) {
					// The API cannot read the held cursor: the next run starts
					// this pass over from the watermark.
					$this->state->update(
						array(
							'expired_cursor'          => '',
							'expired_cursor_since'    => '',
							'expired_pass_started_at' => 0,
						)
					);
					return;
				}
				throw $e;
			}

			$ids = isset( $response['job_ids'] ) && is_array( $response['job_ids'] ) ? $response['job_ids'] : array();

			// The expired feed returns ids for the whole index, most of which
			// this site never imported. Each chunk is resolved in one query,
			// so the misses cost nothing.
			foreach ( array_chunk( $ids, self::EXPIRE_CHUNK ) as $chunk ) {
				$chunk = array_map(
					static function ( $job_id ) {
						return sanitize_text_field( (string) $job_id );
					},
					$chunk
				);
				$target->prime_lookup( $chunk );

				foreach ( $chunk as $job_id ) {
					if ( $target->expire( $job_id, (string) $settings['expire_action'] ) ) {
						$summary['expired']++;
					}
				}
			}

			$next = isset( $response['next_cursor'] ) ? (string) $response['next_cursor'] : '';

			if ( empty( $response['has_more'] ) || '' === $next ) {
				// The pass is complete: the next one asks for closures since
				// this one began.
				$this->state->update(
					array(
						'expired_since'           => self::query_time( $began ),
						'expired_cursor'          => '',
						'expired_cursor_since'    => '',
						'expired_pass_started_at' => 0,
					)
				);
				return;
			}

			$cursor = $next;
			$this->state->update(
				array(
					'expired_cursor'          => $cursor,
					'expired_cursor_since'    => $since,
					'expired_pass_started_at' => $began,
				)
			);

			if ( ( time() - $now ) >= self::EXPIRED_TIME_BUDGET_SECONDS ) {
				// Leave the rest of the run to the import; the next run
				// resumes from the cursor.
				return;
			}
		}
	}

	/**
	 * Timestamp for a new expiry pass, clamped inside the API's 7-day window.
	 *
	 * A site whose cron has been dormant for longer will have missed some
	 * expirations; recover_expiry_gap() catches those up.
	 */
	private function expired_since(): string {
		$stored = (string) $this->state->get( 'expired_since', '' );
		$oldest = time() - self::MAX_EXPIRED_LOOKBACK;

		if ( '' === $stored ) {
			return self::query_time( time() - DAY_IN_SECONDS );
		}

		$parsed = strtotime( $stored );
		if ( false === $parsed || $parsed < $oldest ) {
			return self::query_time( $oldest );
		}

		return self::query_time( $parsed );
	}

	/**
	 * A UTC timestamp for a query-string parameter. `Z` rather than the
	 * `+00:00` of gmdate( 'c' ): the query string is not percent-encoded, and
	 * a bare `+` there reads as a space.
	 */
	public static function query_time( int $timestamp ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}
}

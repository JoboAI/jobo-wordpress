<?php
/**
 * Persisted sync state.
 *
 * The feed cursor and watermark live here rather than being recomputed from a
 * relative window on each run. That distinction matters: a cursor or watermark
 * that fails to advance re-imports (and re-bills) the same jobs on every cron
 * tick, and it looks correct while testing because the resulting listings are
 * right.
 *
 * @package Jobo_Jobs
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

class Jobo_State {

	const OPTION_STATE = 'jobo_jobs_state';

	/** @var array<string,mixed>|null */
	private $cache = null;

	/**
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION_STATE, array() );
			$this->cache = wp_parse_args(
				is_array( $stored ) ? $stored : array(),
				array(
					'feed_cursor'        => '',
					// When the last completed pass began; '' makes the next pass a full one.
					'feed_watermark'     => '',
					// Fingerprint of the filters the watermark was earned under.
					'feed_filters'       => '',
					'pass_started_at'    => 0,
					// Expiry recovery: a full pass is due, then a reconcile of
					// listings last synced before that pass began.
					'reconcile_pending'  => false,
					'reconcile_before'   => 0,
					'last_sync_at'       => 0,
					'last_sync_status'   => '',
					'last_error'         => '',
					'last_error_at'      => 0,
					'expired_since'      => '',
					'credits_balance'    => null,
					'quota_limit'        => null,
					'quota_remaining'    => null,
					'imported_total'     => 0,
					'updated_total'      => 0,
					'expired_total'      => 0,
					'last_run_imported'  => 0,
					'last_run_updated'   => 0,
					'last_run_expired'   => 0,
					'last_run_credits'   => 0,
				)
			);
		}

		return $this->cache;
	}

	/**
	 * @param string $key     State key.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$state = $this->all();
		return array_key_exists( $key, $state ) ? $state[ $key ] : $default;
	}

	/**
	 * @param array<string,mixed> $changes Partial state to merge.
	 */
	public function update( array $changes ): void {
		$state       = array_merge( $this->all(), $changes );
		$this->cache = $state;
		update_option( self::OPTION_STATE, $state, false );
	}

	/**
	 * Drop the cursor so the next run starts the pass over.
	 *
	 * Called when the API reports `feed_cursor_restart_required`. The watermark
	 * is kept, so the restarted pass still asks only for changed jobs.
	 */
	public function reset_cursor(): void {
		$this->update( array( 'feed_cursor' => '' ) );
	}

	/**
	 * Drop the cursor and the watermark, so the next run delivers (and bills)
	 * every matching job again. Backs the "Resync everything" admin action.
	 */
	public function reset_scan(): void {
		$this->update(
			array(
				'feed_cursor'    => '',
				'feed_watermark' => '',
			)
		);
	}

	public function record_error( string $message ): void {
		$this->update(
			array(
				'last_error'       => $message,
				'last_error_at'    => time(),
				'last_sync_status' => 'error',
			)
		);
	}

	public function clear_error(): void {
		$this->update(
			array(
				'last_error'       => '',
				'last_error_at'    => 0,
				'last_sync_status' => 'ok',
			)
		);
	}

	public function delete(): void {
		delete_option( self::OPTION_STATE );
		$this->cache = null;
	}
}

<?php
/**
 * Persisted sync state.
 *
 * The feed cursor lives here rather than being recomputed from a relative
 * window on each run. That distinction matters: a cursor that fails to advance
 * re-imports (and re-bills) the same window on every cron tick, and it looks
 * correct while testing because the resulting listings are right.
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
					'last_sync_at'       => 0,
					'last_sync_status'   => '',
					'last_error'         => '',
					'last_error_at'      => 0,
					'expired_since'      => '',
					'credits_balance'    => null,
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
	 * Drop the cursor so the next run starts a fresh scan.
	 *
	 * Called when the API reports `feed_cursor_restart_required`, and by the
	 * "Resync from scratch" admin action.
	 */
	public function reset_cursor(): void {
		$this->update( array( 'feed_cursor' => '' ) );
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

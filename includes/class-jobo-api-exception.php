<?php
/**
 * Typed failures from the Jobo API.
 *
 * @package Jobo_Jobs
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Base exception carrying the HTTP status and problem-details fields.
 */
class Jobo_Api_Exception extends Exception {

	/** @var int */
	protected $status;

	/** @var string|null */
	protected $error_code;

	/** @var int|null Seconds the server asked us to wait. */
	protected $retry_after;

	/**
	 * @param string      $message     Human-readable message.
	 * @param int         $status      HTTP status, or 0 for transport failures.
	 * @param string|null $error_code  Problem-details `code` extension.
	 * @param int|null    $retry_after Seconds from Retry-After.
	 */
	public function __construct( string $message, int $status = 0, ?string $error_code = null, ?int $retry_after = null ) {
		parent::__construct( $message );
		$this->status      = $status;
		$this->error_code  = $error_code;
		$this->retry_after = $retry_after;
	}

	public function get_status(): int {
		return $this->status;
	}

	public function get_error_code(): ?string {
		return $this->error_code;
	}

	public function get_retry_after(): ?int {
		return $this->retry_after;
	}

	/**
	 * Whether another attempt could plausibly succeed.
	 *
	 * Deliberately excludes 402 and 409: metered access prechecks the requested
	 * batch size after remaining shared allowance, so a retry fails identically,
	 * and a void feed cursor can never be replayed.
	 */
	public function is_retryable(): bool {
		if ( $this instanceof Jobo_Insufficient_Credits_Exception ) {
			return false;
		}
		if ( $this instanceof Jobo_Cursor_Restart_Exception ) {
			return false;
		}

		return 0 === $this->status || 408 === $this->status || 429 === $this->status || $this->status >= 500;
	}
}

/**
 * HTTP 402 — wallet cannot cover the request. Terminal.
 */
class Jobo_Insufficient_Credits_Exception extends Jobo_Api_Exception {}

/**
 * HTTP 409 `feed_cursor_restart_required` — held cursor is void; the caller
 * must drop it and start a fresh scan.
 */
class Jobo_Cursor_Restart_Exception extends Jobo_Api_Exception {}

/**
 * HTTP 401/403 — the key is wrong, revoked, or lacks access. Terminal.
 */
class Jobo_Auth_Exception extends Jobo_Api_Exception {}

<?php
/**
 * HTTP client for the Jobo public API.
 *
 * Mirrors the retry and error semantics of @jobo-ai/connector-core so the
 * WordPress plugin behaves identically to the JS connectors. Built on
 * wp_remote_* rather than cURL, as the plugin directory requires.
 *
 * @package Jobo_Jobs
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

class Jobo_Client {

	const DEFAULT_BASE_URL = 'https://connect.jobo.world';

	/**
	 * Shape of a Jobo key: jbe_{live|test}_{21}_{43}. The first 12 characters
	 * are the server's indexed lookup prefix.
	 */
	const KEY_PATTERN = '/^jbe_(live|test)_[A-Za-z0-9_-]{21}_[A-Za-z0-9_-]{43}$/';

	/** @var string */
	private $api_key;

	/** @var string */
	private $base_url;

	/** @var int */
	private $timeout;

	/** @var int */
	private $max_attempts;

	/** @var array<string,mixed> Usage figures from the most recent response. */
	private $last_usage = array();

	public function __construct( string $api_key, string $base_url = self::DEFAULT_BASE_URL, int $timeout = 30, int $max_attempts = 3 ) {
		$this->api_key      = trim( $api_key );
		$this->base_url     = untrailingslashit( $base_url );
		$this->timeout      = $timeout;
		$this->max_attempts = $max_attempts;
	}

	/**
	 * Cheap shape check so a typo'd key fails in the settings screen instead of
	 * burning a round-trip. A well-formed key can still be revoked — this is a
	 * typo guard, not authentication.
	 */
	public static function is_valid_key_format( string $api_key ): bool {
		return 1 === preg_match( self::KEY_PATTERN, trim( $api_key ) );
	}

	/**
	 * Usage figures lifted from the last response headers.
	 *
	 * @return array<string,mixed>
	 */
	public function get_last_usage(): array {
		return $this->last_usage;
	}

	/**
	 * Bulk incremental feed. Cursor-paginated; pass back `next_cursor` verbatim.
	 *
	 * @param array<string,mixed> $params Feed request body.
	 * @return array<string,mixed>
	 * @throws Jobo_Api_Exception On a non-2xx response.
	 */
	public function feed( array $params ): array {
		return $this->request( 'POST', '/api/jobs/feed', $params );
	}

	/**
	 * Recently expired job ids. Never deducts credits, on any tier.
	 *
	 * @param array<string,mixed> $params Query parameters.
	 * @return array<string,mixed>
	 * @throws Jobo_Api_Exception On a non-2xx response.
	 */
	public function expired_job_ids( array $params = array() ): array {
		return $this->request( 'GET', '/api/jobs/expired', $params );
	}

	/**
	 * Verify a key as cheaply as the API allows — page_size=1 keeps the
	 * worst-case included-jobs/wallet precheck down to a single job.
	 *
	 * @return array<string,mixed>
	 * @throws Jobo_Api_Exception On a non-2xx response.
	 */
	public function verify(): array {
		return $this->request( 'GET', '/api/jobs', array( 'page_size' => 1 ) );
	}

	/**
	 * Filter metadata for the settings pickers: available source keys (with
	 * job counts) and the canonical enum values. Free on every tier.
	 *
	 * Shape: {enums:{work_models,employment_types,experience_levels},
	 * sources:[{key,count}]}.
	 *
	 * @return array<string,mixed>
	 * @throws Jobo_Api_Exception On a non-2xx response.
	 */
	public function filter_options(): array {
		return $this->request( 'GET', '/api/connectors/filters' );
	}

	/**
	 * Location autocomplete backing the settings screen. Free on every tier.
	 *
	 * Shape: {suggestions:[{display_name,city,region,country,...}]}.
	 *
	 * @param string $q     Partial location text.
	 * @param int    $limit Maximum suggestions to return.
	 * @return array<string,mixed>
	 * @throws Jobo_Api_Exception On a non-2xx response.
	 */
	public function suggest_locations( string $q, int $limit = 5 ): array {
		return $this->request(
			'GET',
			'/api/connectors/locations/suggest',
			array(
				'q'     => $q,
				'limit' => $limit,
			)
		);
	}

	/**
	 * @param string              $method GET or POST.
	 * @param string              $path   Path beginning with a slash.
	 * @param array<string,mixed> $params Query string for GET, JSON body for POST.
	 * @return array<string,mixed>
	 * @throws Jobo_Api_Exception On a non-2xx response or exhausted retries.
	 */
	private function request( string $method, string $path, array $params = array() ): array {
		$attempt = 0;

		while ( true ) {
			$attempt++;

			try {
				return $this->dispatch( $method, $path, $params );
			} catch ( Jobo_Api_Exception $e ) {
				if ( ! $e->is_retryable() || $attempt >= $this->max_attempts ) {
					throw $e;
				}

				// Honour Retry-After literally when present — the feed returns
				// 503 Retry-After: 5 under load and guessing makes it worse.
				$delay = $e->get_retry_after();
				if ( null === $delay ) {
					$delay = min( 2 ** ( $attempt - 1 ), 30 );
				}

				sleep( max( 1, min( (int) $delay, 60 ) ) );
			}
		}
	}

	/**
	 * @param string              $method GET or POST.
	 * @param string              $path   Path beginning with a slash.
	 * @param array<string,mixed> $params Query string for GET, JSON body for POST.
	 * @return array<string,mixed>
	 * @throws Jobo_Api_Exception On a non-2xx response.
	 */
	private function dispatch( string $method, string $path, array $params ): array {
		$url = $this->base_url . $path;

		$args = array(
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => array(
				'X-Api-Key'  => $this->api_key,
				'Accept'     => 'application/json',
				'User-Agent' => 'jobo-wordpress/' . JOBO_JOBS_VERSION . '; ' . home_url( '/' ),
			),
		);

		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $params );
		} elseif ( ! empty( $params ) ) {
			$url = add_query_arg( array_map( array( $this, 'flatten' ), $params ), $url );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			// Status 0 marks a transport failure, which is retryable. Messages
			// are escaped at construction because they surface in admin notices.
			throw new Jobo_Api_Exception( esc_html( $response->get_error_message() ), 0 );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		$this->last_usage = $this->parse_usage( $response );

		if ( $status >= 200 && $status < 300 ) {
			return $decoded;
		}

		// to_exception() escapes the message it builds; the sniff cannot see
		// through the factory call.
		throw $this->to_exception( $status, $decoded, $response ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Arrays go on the wire comma-joined, matching the API's list parameters.
	 *
	 * @param mixed $value Raw parameter value.
	 * @return mixed
	 */
	private function flatten( $value ) {
		if ( is_array( $value ) ) {
			return implode( ',', $value );
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		return $value;
	}

	/**
	 * @param array<string,mixed> $decoded  Decoded JSON body.
	 * @param array|WP_Error      $response Raw wp_remote_* response.
	 */
	private function to_exception( int $status, array $decoded, $response ): Jobo_Api_Exception {
		$code    = isset( $decoded['code'] ) && is_string( $decoded['code'] ) ? $decoded['code'] : null;
		$message = '';

		foreach ( array( 'detail', 'error', 'title' ) as $key ) {
			if ( isset( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) && '' !== $decoded[ $key ] ) {
				$message = $decoded[ $key ];
				break;
			}
		}

		if ( '' === $message ) {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'The Jobo API returned HTTP %d.', 'career-site-jobs' ), $status );
		}

		// Escaped here rather than at each render site: these messages are
		// server-supplied and end up in admin notices and the settings screen.
		$message = esc_html( $message );

		if ( 402 === $status ) {
			return new Jobo_Insufficient_Credits_Exception( $message, $status, $code );
		}

		if ( 409 === $status && 'feed_cursor_restart_required' === $code ) {
			return new Jobo_Cursor_Restart_Exception( $message, $status, $code );
		}

		if ( 401 === $status || 403 === $status ) {
			return new Jobo_Auth_Exception( $message, $status, $code );
		}

		$retry_after = null;
		$header      = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( is_string( $header ) && is_numeric( $header ) ) {
			$retry_after = (int) $header;
		} elseif ( isset( $decoded['retry_after_seconds'] ) && is_numeric( $decoded['retry_after_seconds'] ) ) {
			$retry_after = (int) $decoded['retry_after_seconds'];
		}

		return new Jobo_Api_Exception( $message, $status, $code, $retry_after );
	}

	/**
	 * @param array|WP_Error $response Raw wp_remote_* response.
	 * @return array<string,mixed>
	 */
	private function parse_usage( $response ): array {
		$read = static function ( $name ) use ( $response ) {
			$value = wp_remote_retrieve_header( $response, $name );
			return ( is_string( $value ) && is_numeric( $value ) ) ? (int) $value : null;
		};

		return array(
			'credits_deducted'  => $read( 'x-credits-deducted' ),
			'credits_balance'   => $read( 'x-credits-balance' ),
			'quota_limit'       => $read( 'x-quota-limit' ),
			'quota_remaining'   => $read( 'x-quota-remaining' ),
			'ratelimit_remaining' => $read( 'x-ratelimit-remaining' ),
			'ratelimit_reset'   => $read( 'x-ratelimit-reset' ),
			'checked_at'        => time(),
		);
	}
}

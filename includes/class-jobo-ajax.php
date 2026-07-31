<?php
/**
 * Admin-ajax endpoints backing the settings screen: filter metadata for the
 * sources picker, location autocomplete, and the connection test.
 *
 * All three are admin-only conveniences — the sync path never touches them —
 * and every handler requires manage_options plus the jobo_admin nonce.
 *
 * @package Jobo_Jobs
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

class Jobo_Ajax {

	const NONCE_ACTION = 'jobo_admin';

	/** Filter metadata changes rarely; cache it for a working day-ish. */
	const FILTER_OPTIONS_TRANSIENT = 'jobo_filter_options';

	public static function register(): void {
		add_action( 'wp_ajax_jobo_filter_options', array( __CLASS__, 'handle_filter_options' ) );
		add_action( 'wp_ajax_jobo_suggest_locations', array( __CLASS__, 'handle_suggest_locations' ) );
		add_action( 'wp_ajax_jobo_test_connection', array( __CLASS__, 'handle_test_connection' ) );
	}

	/**
	 * Capability plus nonce gate shared by every handler. Ends the request
	 * on failure, so callers can assume a vetted admin afterwards.
	 */
	private static function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to do that.', 'career-site-jobs' ) ),
				403
			);
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/**
	 * Source keys and canonical enums for the settings pickers.
	 *
	 * GET /api/connectors/filters is free (no credits) but still keyed, so the
	 * result is cached in a transient; pass refresh=1 to bypass the cache.
	 */
	public static function handle_filter_options(): void {
		self::guard();

		// Nonce is verified in guard(); the sniff cannot see across the call.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$refresh = ! empty( $_POST['refresh'] );

		if ( ! $refresh ) {
			$cached = get_transient( self::FILTER_OPTIONS_TRANSIENT );
			if ( is_array( $cached ) ) {
				wp_send_json_success( $cached );
			}
		}

		$api_key = (string) Jobo_Settings::value( 'api_key', '' );
		if ( '' === $api_key ) {
			wp_send_json_error(
				array( 'message' => __( 'Save an API key first, then the source list can load.', 'career-site-jobs' ) ),
				400
			);
		}

		try {
			$client   = new Jobo_Client( $api_key );
			$response = $client->filter_options();
		} catch ( Jobo_Api_Exception $e ) {
			// Message is esc_html()'d at construction inside the client.
			wp_send_json_error( array( 'message' => $e->getMessage() ), 502 );
			return; // Unreachable; keeps static analysis honest.
		}

		$sources = array();
		if ( isset( $response['sources'] ) && is_array( $response['sources'] ) ) {
			foreach ( $response['sources'] as $source ) {
				if ( is_array( $source ) && isset( $source['key'] ) && '' !== (string) $source['key'] ) {
					$sources[] = sanitize_text_field( (string) $source['key'] );
				}
			}
		}

		$enums = array();
		if ( isset( $response['enums'] ) && is_array( $response['enums'] ) ) {
			$enums = map_deep( $response['enums'], 'sanitize_text_field' );
		}

		$payload = array(
			'sources' => array_values( array_unique( $sources ) ),
			'enums'   => $enums,
		);

		set_transient( self::FILTER_OPTIONS_TRANSIENT, $payload, 6 * HOUR_IN_SECONDS );

		wp_send_json_success( $payload );
	}

	/**
	 * Location autocomplete. Keystroke path, so no transient — and failures
	 * come back as an empty success so the field degrades to free text
	 * instead of surfacing an error the admin cannot act on.
	 */
	public static function handle_suggest_locations(): void {
		self::guard();

		// Nonce is verified in guard(); the sniff cannot see across the call.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$q = isset( $_POST['q'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['q'] ) ) ) : '';

		if ( strlen( $q ) < 2 ) {
			wp_send_json_success( array( 'suggestions' => array() ) );
		}

		$api_key = (string) Jobo_Settings::value( 'api_key', '' );
		if ( '' === $api_key ) {
			wp_send_json_success( array( 'suggestions' => array() ) );
		}

		try {
			$client   = new Jobo_Client( $api_key );
			$response = $client->suggest_locations( $q );
		} catch ( Jobo_Api_Exception $e ) {
			wp_send_json_success( array( 'suggestions' => array() ) );
			return; // Unreachable; keeps static analysis honest.
		}

		$suggestions = array();
		if ( isset( $response['suggestions'] ) && is_array( $response['suggestions'] ) ) {
			foreach ( $response['suggestions'] as $suggestion ) {
				if ( ! is_array( $suggestion ) ) {
					continue;
				}

				$row = array();
				foreach ( array( 'display_name', 'city', 'region', 'country' ) as $field ) {
					$row[ $field ] = isset( $suggestion[ $field ] ) ? sanitize_text_field( (string) $suggestion[ $field ] ) : '';
				}
				$suggestions[] = $row;
			}
		}

		wp_send_json_success( array( 'suggestions' => $suggestions ) );
	}

	/**
	 * Verify a key against the live API. Uses the cheapest call the API
	 * offers (page_size=1) and never echoes the key back in any response.
	 */
	public static function handle_test_connection(): void {
		self::guard();

		// Nonce is verified in guard(); the sniff cannot see across the call.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$submitted = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['key'] ) ) : '';
		$saved     = (string) Jobo_Settings::value( 'api_key', '' );
		$key       = self::resolve_test_key( $submitted, $saved );

		if ( '' === $key ) {
			wp_send_json_error(
				array( 'message' => __( 'Enter an API key first.', 'career-site-jobs' ) ),
				400
			);
		}

		if ( ! Jobo_Client::is_valid_key_format( $key ) ) {
			wp_send_json_error(
				array( 'message' => __( 'That does not look like a Jobo API key. Keys begin with jbe_live_ or jbe_test_ and are 74 characters long.', 'career-site-jobs' ) ),
				400
			);
		}

		// Throwaway client: short timeout, single attempt — an interactive
		// button should fail fast rather than retry with backoff.
		$client = new Jobo_Client( $key, Jobo_Client::DEFAULT_BASE_URL, 15, 1 );

		try {
			$client->verify();
		} catch ( Jobo_Api_Exception $e ) {
			// Message is esc_html()'d at construction inside the client.
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => $e->getMessage(),
				)
			);
			return; // Unreachable; keeps static analysis honest.
		}

		$usage = $client->get_last_usage();

		wp_send_json_success(
			array(
				'ok'              => true,
				'message'         => __( 'Connected.', 'career-site-jobs' ),
				'credits_balance' => isset( $usage['credits_balance'] ) ? $usage['credits_balance'] : null,
			)
		);
	}

	/**
	 * Decide which key a connection test should exercise.
	 *
	 * The settings field renders an all-asterisk placeholder when a key is
	 * already saved, so a submitted value that is empty or entirely asterisks
	 * means "test the saved key"; anything else is a candidate the admin just
	 * pasted. Pure by design so the no-WordPress test harness covers it.
	 */
	public static function resolve_test_key( string $submitted, string $saved ): string {
		$submitted = trim( $submitted );

		if ( '' === $submitted || 1 === preg_match( '/^\*+$/', $submitted ) ) {
			return $saved;
		}

		return $submitted;
	}
}

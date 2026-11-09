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

	/**
	 * Filter metadata changes rarely; cache it for a working day-ish. v2
	 * added the industry and job category/subcategory options.
	 */
	const FILTER_OPTIONS_TRANSIENT = 'jobo_filter_options_v2';

	/** The occupation taxonomy, flattened for the pickers. */
	const OCCUPATIONS_TRANSIENT = 'jobo_occupations';

	public static function register(): void {
		add_action( 'wp_ajax_jobo_filter_options', array( __CLASS__, 'handle_filter_options' ) );
		add_action( 'wp_ajax_jobo_suggest_locations', array( __CLASS__, 'handle_suggest_locations' ) );
		add_action( 'wp_ajax_jobo_suggest_companies', array( __CLASS__, 'handle_suggest_companies' ) );
		add_action( 'wp_ajax_jobo_occupations', array( __CLASS__, 'handle_occupations' ) );
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

		// The facet lists are extras: one failing (the occupation facets 404
		// until occupations are published) leaves its picker on free text
		// rather than failing the sources list too.
		$facet = static function ( string $name, bool $with_parent ) use ( $client ): array {
			try {
				return self::facet_picker_options( $client->filter_option_values( $name ), $with_parent );
			} catch ( Jobo_Api_Exception $e ) {
				return array();
			}
		};

		$payload = array(
			'sources'           => array_values( array_unique( $sources ) ),
			'enums'             => $enums,
			'industries'        => $facet( 'industries', false ),
			'job_categories'    => $facet( 'job_categories', false ),
			'job_subcategories' => $facet( 'job_subcategories', true ),
		);

		set_transient( self::FILTER_OPTIONS_TRANSIENT, $payload, 6 * HOUR_IN_SECONDS );

		wp_send_json_success( $payload );
	}

	/**
	 * Picker options from a filter-options response: the value is the display
	 * name where the facet has one (job categories: "Healthcare", which the
	 * case-insensitive filter takes), and a subcategory's label carries its
	 * category. Pure, for the no-WordPress tests.
	 *
	 * @param array<string,mixed> $response    Decoded filter-options response.
	 * @param bool                $with_parent Append the parent to the label.
	 * @return array<int,array{label:string,value:string,count:int}>
	 */
	public static function facet_picker_options( array $response, bool $with_parent ): array {
		$out = array();
		foreach ( isset( $response['options'] ) && is_array( $response['options'] ) ? $response['options'] : array() as $option ) {
			if ( ! is_array( $option ) || ! isset( $option['key'] ) || '' === (string) $option['key'] ) {
				continue;
			}
			$name  = sanitize_text_field( (string) ( isset( $option['name'] ) && '' !== (string) $option['name'] ? $option['name'] : $option['key'] ) );
			$label = $name;
			if ( $with_parent && ! empty( $option['parent'] ) ) {
				$label .= ' · ' . ucwords( sanitize_text_field( (string) $option['parent'] ) );
			}
			$out[] = array(
				'label' => $label,
				'value' => $name,
				'count' => (int) ( $option['count'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * Company typeahead. Keystroke path like locations: failures come back as
	 * an empty success so the field still takes a typed domain or name.
	 */
	public static function handle_suggest_companies(): void {
		self::guard();

		// Nonce is verified in guard(); the sniff cannot see across the call.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$q = isset( $_POST['q'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['q'] ) ) ) : '';

		$api_key = (string) Jobo_Settings::value( 'api_key', '' );
		if ( strlen( $q ) < 2 || '' === $api_key ) {
			wp_send_json_success( array( 'suggestions' => array() ) );
		}

		try {
			$response = ( new Jobo_Client( $api_key ) )->suggest_companies( $q );
		} catch ( Jobo_Api_Exception $e ) {
			wp_send_json_success( array( 'suggestions' => array() ) );
			return; // Unreachable; keeps static analysis honest.
		}

		wp_send_json_success( array( 'suggestions' => self::company_picker_items( $response ) ) );
	}

	/**
	 * Picker items from a companies/suggest response: one per company,
	 * carrying every board id (`values`) — the name alone would also match
	 * every other company called that. Pure, for the no-WordPress tests.
	 *
	 * @param array<string,mixed> $response Decoded suggest response.
	 * @return array<int,array<string,mixed>>
	 */
	public static function company_picker_items( array $response ): array {
		$out = array();
		foreach ( isset( $response['suggestions'] ) && is_array( $response['suggestions'] ) ? $response['suggestions'] : array() as $company ) {
			if ( ! is_array( $company ) || empty( $company['name'] ) ) {
				continue;
			}
			$ids = array();
			foreach ( isset( $company['ids'] ) && is_array( $company['ids'] ) ? $company['ids'] : array() as $id ) {
				$ids[] = sanitize_text_field( (string) $id );
			}
			if ( empty( $ids ) && ! empty( $company['id'] ) ) {
				$ids[] = sanitize_text_field( (string) $company['id'] );
			}
			if ( empty( $ids ) ) {
				continue;
			}

			$details = array_filter(
				array(
					sanitize_text_field( (string) ( $company['domain'] ?? '' ) ),
					sanitize_text_field( (string) ( $company['country'] ?? '' ) ),
				),
				'strlen'
			);
			$name    = sanitize_text_field( (string) $company['name'] );
			$item    = array(
				'label'  => empty( $details ) ? $name : $name . ' (' . implode( ', ', $details ) . ')',
				'values' => $ids,
			);
			if ( isset( $company['job_count'] ) && is_numeric( $company['job_count'] ) ) {
				$item['count'] = (int) $company['job_count'];
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * The occupation taxonomy for the occupation pickers, flattened and
	 * cached. An empty list (occupations not published, or the call failed)
	 * leaves the pickers showing what is already saved.
	 */
	public static function handle_occupations(): void {
		self::guard();

		$cached = get_transient( self::OCCUPATIONS_TRANSIENT );
		if ( is_array( $cached ) ) {
			wp_send_json_success( array( 'occupations' => $cached ) );
		}

		$api_key = (string) Jobo_Settings::value( 'api_key', '' );
		if ( '' === $api_key ) {
			wp_send_json_success( array( 'occupations' => array() ) );
		}

		try {
			$occupations = self::flatten_occupations( ( new Jobo_Client( $api_key ) )->occupations() );
		} catch ( Jobo_Api_Exception $e ) {
			wp_send_json_success( array( 'occupations' => array() ) );
			return; // Unreachable; keeps static analysis honest.
		}

		set_transient( self::OCCUPATIONS_TRANSIENT, $occupations, 12 * HOUR_IN_SECONDS );
		wp_send_json_success( array( 'occupations' => $occupations ) );
	}

	/**
	 * Every occupation as {label: "Registered Nurse (Healthcare › Nursing)",
	 * value: id}, sorted by label. Pure, for the no-WordPress tests.
	 *
	 * @param array<string,mixed> $taxonomy Decoded GET /api/occupations response.
	 * @return array<int,array{label:string,value:string}>
	 */
	public static function flatten_occupations( array $taxonomy ): array {
		$out = array();
		foreach ( isset( $taxonomy['categories'] ) && is_array( $taxonomy['categories'] ) ? $taxonomy['categories'] : array() as $category ) {
			foreach ( is_array( $category ) && isset( $category['subcategories'] ) && is_array( $category['subcategories'] ) ? $category['subcategories'] : array() as $sub ) {
				foreach ( is_array( $sub ) && isset( $sub['occupations'] ) && is_array( $sub['occupations'] ) ? $sub['occupations'] : array() as $occupation ) {
					if ( ! is_array( $occupation ) || empty( $occupation['id'] ) || empty( $occupation['name'] ) ) {
						continue;
					}
					$out[] = array(
						'label' => sprintf(
							'%s (%s › %s)',
							sanitize_text_field( (string) $occupation['name'] ),
							sanitize_text_field( (string) ( $category['name'] ?? '' ) ),
							sanitize_text_field( (string) ( $sub['name'] ?? '' ) )
						),
						'value' => sanitize_text_field( (string) $occupation['id'] ),
					);
				}
			}
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);
		return $out;
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
				'quota_limit'     => isset( $usage['quota_limit'] ) ? $usage['quota_limit'] : null,
				'quota_remaining' => isset( $usage['quota_remaining'] ) ? $usage['quota_remaining'] : null,
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

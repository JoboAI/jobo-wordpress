<?php
/**
 * "Sign in with Jobo": OAuth 2.0 authorization code with PKCE.
 *
 * The site registers itself once as a public OAuth client, sends the admin to
 * Jobo to sign in and approve, and is answered with an ordinary Jobo API key
 * as the access token. That key is saved through
 * Jobo_Settings::save_api_key(), the option and validation a pasted key goes
 * through, so nothing about how the plugin calls the API changes. There is no
 * client secret, no expiry and no refresh. Disconnecting hands the key back to
 * Jobo, which revokes it.
 *
 * begin(), complete() and disconnect() hold the flow and return their result,
 * so they run without a browser; the handle_*() methods are the admin-post
 * endpoints around them (capability, nonce, redirect).
 *
 * @package Jobo_Jobs
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

class Jobo_OAuth {

	const ACTION_START      = 'jobo_jobs_oauth_start';
	const ACTION_CALLBACK   = 'jobo_jobs_oauth_callback';
	const ACTION_DISCONNECT = 'jobo_jobs_oauth_disconnect';

	const AUTHORIZE_URL = 'https://enterprise.jobo.world/connect/authorize';
	const TOKEN_URL     = 'https://enterprise.jobo.world/connect/token';
	const REGISTER_URL  = 'https://enterprise.jobo.world/connect/register';
	const REVOKE_URL    = 'https://enterprise.jobo.world/connect/revoke';

	/** The one host the sign-in redirect may leave this site for. */
	const AUTHORIZE_HOST = 'enterprise.jobo.world';

	/** `api` asks for the key; `email` adds the account email to label the connection. */
	const SCOPE = 'api email';

	/**
	 * This site's registration: {client_id, redirect_uri}. Kept when the admin
	 * disconnects, so signing in again is the same app in the Jobo dashboard
	 * and not a second one.
	 */
	const OPTION_CLIENT = 'jobo_jobs_oauth_client';

	/** The account a sign-in connected: {email, key_hash}. */
	const OPTION_ACCOUNT = 'jobo_jobs_oauth_account';

	/** Per-user transients: the sign-in in progress, and the message of a failed one. */
	const PENDING_TRANSIENT = 'jobo_jobs_oauth_pending_';
	const NOTICE_TRANSIENT  = 'jobo_jobs_oauth_notice_';

	/** How long an admin has to sign in and approve before starting over. */
	const PENDING_TTL = 10 * MINUTE_IN_SECONDS;

	/** Both calls happen while an admin waits on a redirect, so fail fast. */
	const HTTP_TIMEOUT = 15;

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_START, array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_post_' . self::ACTION_CALLBACK, array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( __CLASS__, 'handle_disconnect' ) );
	}

	/**
	 * Where Jobo sends the browser back to. This exact string is what the site
	 * registers, what the authorization request carries and what the token
	 * request repeats; the server compares them character for character.
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=' . self::ACTION_CALLBACK );
	}

	/**
	 * Jobo only accepts https redirect URIs, so a site whose admin is served
	 * over plain http cannot sign in and connects with an API key instead.
	 */
	public static function is_available(): bool {
		return 'https' === strtolower( (string) wp_parse_url( self::redirect_uri(), PHP_URL_SCHEME ) );
	}

	/**
	 * How the site is connected, for the settings screen.
	 *
	 * `signed_in` is true only while the saved key is the one a sign-in stored:
	 * a key pasted over it afterwards is a pasted key again.
	 *
	 * @return array{available:bool,signed_in:bool,email:string}
	 */
	public static function status(): array {
		$account = get_option( self::OPTION_ACCOUNT, array() );
		$api_key = (string) Jobo_Settings::value( 'api_key', '' );

		$signed_in = '' !== $api_key
			&& is_array( $account )
			&& isset( $account['key_hash'] )
			&& hash_equals( (string) $account['key_hash'], hash( 'sha256', $api_key ) );

		return array(
			'available' => self::is_available(),
			'signed_in' => $signed_in,
			'email'     => $signed_in && isset( $account['email'] ) ? (string) $account['email'] : '',
		);
	}

	/**
	 * Start a sign-in for one admin: make sure the site is registered, remember
	 * a fresh `state` and PKCE verifier for that user, and build the address to
	 * send their browser to.
	 *
	 * @param int $user_id The admin signing in.
	 * @return string|WP_Error The authorization URL, or why the sign-in cannot start.
	 */
	public static function begin( int $user_id ) {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'jobo_oauth_unavailable',
				__( 'Sign in with Jobo needs a site served over HTTPS. Use an API key instead.', 'career-site-jobs' )
			);
		}

		$redirect_uri = self::redirect_uri();
		$client_id    = self::client_id( $redirect_uri );
		if ( is_wp_error( $client_id ) ) {
			return $client_id;
		}

		try {
			$state    = self::random_token( 32 );
			$verifier = self::random_token( 48 );
		} catch ( Exception $e ) {
			return new WP_Error(
				'jobo_oauth_random',
				__( 'This server could not create a secure sign-in request. Use an API key instead.', 'career-site-jobs' )
			);
		}

		set_transient(
			self::PENDING_TRANSIENT . $user_id,
			array(
				'state'        => $state,
				'verifier'     => $verifier,
				'client_id'    => $client_id,
				'redirect_uri' => $redirect_uri,
			),
			self::PENDING_TTL
		);

		// Built here rather than with add_query_arg(), which leaves encoding
		// the values to the caller.
		return self::AUTHORIZE_URL . '?' . http_build_query(
			array(
				'response_type'         => 'code',
				'client_id'             => $client_id,
				'redirect_uri'          => $redirect_uri,
				'scope'                 => self::SCOPE,
				'state'                 => $state,
				'code_challenge'        => self::code_challenge( $verifier ),
				'code_challenge_method' => 'S256',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Finish a sign-in from what Jobo sent back: check `state`, exchange the
	 * code for the key, and save it.
	 *
	 * @param int                  $user_id The admin the browser returned as.
	 * @param array<string,string> $params  Sanitized `state`, `code`, `error` and `error_description`.
	 * @return true|WP_Error
	 */
	public static function complete( int $user_id, array $params ) {
		$pending = get_transient( self::PENDING_TRANSIENT . $user_id );

		if ( ! is_array( $pending ) || empty( $pending['state'] ) || empty( $pending['verifier'] ) || empty( $pending['client_id'] ) || empty( $pending['redirect_uri'] ) ) {
			return new WP_Error(
				'jobo_oauth_expired',
				__( 'The sign-in expired. Start again from this page.', 'career-site-jobs' )
			);
		}

		$state = (string) ( $params['state'] ?? '' );
		if ( '' === $state || ! hash_equals( (string) $pending['state'], $state ) ) {
			// The pending sign-in is left alone: a forged return must not be
			// able to cancel the real one.
			return new WP_Error(
				'jobo_oauth_state',
				__( 'The sign-in could not be verified. Start again from this page.', 'career-site-jobs' )
			);
		}

		// One return per sign-in: from here on this state and verifier are spent.
		delete_transient( self::PENDING_TRANSIENT . $user_id );

		if ( '' !== (string) ( $params['error'] ?? '' ) ) {
			$description = self::clean_message( (string) ( $params['error_description'] ?? '' ) );
			return new WP_Error(
				'jobo_oauth_denied',
				'' !== $description ? $description : __( 'The sign-in was not approved.', 'career-site-jobs' )
			);
		}

		$code = (string) ( $params['code'] ?? '' );
		if ( '' === $code ) {
			return new WP_Error(
				'jobo_oauth_no_code',
				__( 'Jobo did not return an authorization code. Start again from this page.', 'career-site-jobs' )
			);
		}

		// A public client: the PKCE verifier proves this is the site that
		// started the sign-in. There is no client secret to send, and Jobo
		// refuses a request that carries one.
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => self::request_headers( 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'client_id'     => (string) $pending['client_id'],
					'redirect_uri'  => (string) $pending['redirect_uri'],
					'code'          => $code,
					'code_verifier' => (string) $pending['verifier'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'jobo_oauth_token',
				sprintf(
					/* translators: %s: the connection error reported by WordPress. */
					__( 'Could not reach Jobo to finish the sign-in: %s', 'career-site-jobs' ),
					self::clean_message( $response->get_error_message() )
				)
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( 200 !== $status ) {
			$description = self::error_description( $decoded );
			if ( '' === $description ) {
				/* translators: %d: HTTP status code. */
				$description = sprintf( __( 'Jobo did not finish the sign-in (HTTP %d).', 'career-site-jobs' ), $status );
			}
			return new WP_Error( 'jobo_oauth_token', $description );
		}

		// The access token is the API key. It goes from the response into the
		// settings option and nowhere else: not into a message, not into a log.
		$api_key = isset( $decoded['access_token'] ) && is_string( $decoded['access_token'] ) ? trim( $decoded['access_token'] ) : '';

		if ( ! Jobo_Client::is_valid_key_format( $api_key ) || ! Jobo_Settings::save_api_key( $api_key ) ) {
			return new WP_Error(
				'jobo_oauth_token',
				__( 'Jobo did not return an API key. Start again from this page.', 'career-site-jobs' )
			);
		}

		// Present only when the email scope was granted.
		$email = isset( $decoded['account_email'] ) && is_string( $decoded['account_email'] ) ? sanitize_email( $decoded['account_email'] ) : '';

		update_option(
			self::OPTION_ACCOUNT,
			array(
				'email'    => $email,
				// Ties the account to the key it was issued with; see status().
				'key_hash' => hash( 'sha256', $api_key ),
			),
			false
		);

		return true;
	}

	/**
	 * Disconnect this site: hand the key back to Jobo, which revokes it and
	 * removes the app from the dashboard, then forget the key and the account
	 * here. The site is disconnected either way; the registration is kept.
	 *
	 * @return bool False when Jobo could not be reached, so the key is still
	 *              live there and the app has to be disconnected in the dashboard.
	 */
	public static function disconnect(): bool {
		$revoked = self::revoke();

		Jobo_Settings::clear_api_key();
		delete_option( self::OPTION_ACCOUNT );

		return $revoked;
	}

	/**
	 * Ask Jobo to revoke the key a sign-in stored (RFC 7009). A key the admin
	 * pasted is their own and is never sent here.
	 *
	 * @return bool True when there was nothing to revoke or Jobo accepted the request.
	 */
	private static function revoke(): bool {
		$status = self::status();
		if ( ! $status['signed_in'] ) {
			return true;
		}

		$client = get_option( self::OPTION_CLIENT, array() );
		if ( ! is_array( $client ) || empty( $client['client_id'] ) ) {
			return false;
		}

		// Jobo only revokes a key for the app it was issued to, and answers 200
		// whether or not the key was still live.
		$response = wp_remote_post(
			self::REVOKE_URL,
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => self::request_headers( 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'token'     => (string) Jobo_Settings::value( 'api_key', '' ),
					'client_id' => (string) $client['client_id'],
				),
			)
		);

		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * The message of this user's failed sign-in, shown once.
	 */
	public static function take_notice( int $user_id ): string {
		$message = get_transient( self::NOTICE_TRANSIENT . $user_id );
		if ( ! is_string( $message ) || '' === $message ) {
			return '';
		}

		delete_transient( self::NOTICE_TRANSIENT . $user_id );

		return self::clean_message( $message );
	}

	/**
	 * `allowed_redirect_hosts` callback: lets wp_safe_redirect() follow the
	 * Jobo sign-in host. Hooked by handle_start() for its one redirect only.
	 *
	 * @param string[] $hosts Hosts WordPress already allows.
	 * @return string[]
	 */
	public static function allow_authorize_host( $hosts ): array {
		$hosts   = is_array( $hosts ) ? $hosts : array();
		$hosts[] = self::AUTHORIZE_HOST;

		return $hosts;
	}

	public static function handle_start(): void {
		self::guard( self::ACTION_START );

		$url = self::begin( get_current_user_id() );
		if ( is_wp_error( $url ) ) {
			self::fail( $url );
			return; // Unreachable; fail() redirects and exits.
		}

		// wp_safe_redirect() falls back to the dashboard for a host it does not
		// know. The filter names the Jobo sign-in host for this request, which
		// ends on the next line.
		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_authorize_host' ) );
		wp_safe_redirect( $url );
		exit;
	}

	public static function handle_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'career-site-jobs' ) );
		}

		// Jobo sends the browser here, so the request cannot carry a WordPress
		// nonce. `state` does that job: complete() only accepts the value this
		// user was given when they started, compared with hash_equals().
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$params = array(
			'state'             => isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '',
			'code'              => isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '',
			'error'             => isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '',
			'error_description' => isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = self::complete( get_current_user_id(), $params );
		if ( is_wp_error( $result ) ) {
			self::fail( $result );
			return; // Unreachable; fail() redirects and exits.
		}

		self::redirect_to_settings( 'connected' );
	}

	public static function handle_disconnect(): void {
		self::guard( self::ACTION_DISCONNECT );

		// 'disconnected_here': the key is gone from this site but Jobo could not
		// be told, so the settings screen says where to finish.
		self::redirect_to_settings( self::disconnect() ? 'disconnected' : 'disconnected_here' );
	}

	/**
	 * Capability plus nonce check for the actions an admin starts from the
	 * settings screen.
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'career-site-jobs' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to the settings screen with the reason a sign-in did not complete.
	 * The message waits in a per-user transient, not in the address, so the
	 * screen never displays text taken from a link.
	 */
	private static function fail( WP_Error $error ): void {
		set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), self::clean_message( $error->get_error_message() ), MINUTE_IN_SECONDS );

		self::redirect_to_settings( 'signin_failed' );
	}

	private static function redirect_to_settings( string $result ): void {
		wp_safe_redirect(
			add_query_arg(
				array( 'jobo_result' => $result ),
				admin_url( 'options-general.php?page=career-site-jobs' )
			)
		);
		exit;
	}

	/**
	 * The stored client id when the site is registered for this redirect URI;
	 * otherwise register now. A site whose address changed registers again,
	 * because the old registration only allows the old address.
	 *
	 * @return string|WP_Error
	 */
	private static function client_id( string $redirect_uri ) {
		$client = get_option( self::OPTION_CLIENT, array() );

		if ( is_array( $client ) && ! empty( $client['client_id'] ) && isset( $client['redirect_uri'] ) && $redirect_uri === $client['redirect_uri'] ) {
			return (string) $client['client_id'];
		}

		$response = wp_remote_post(
			self::REGISTER_URL,
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => self::request_headers( 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'client_name'   => self::client_name( $redirect_uri ),
						'redirect_uris' => array( $redirect_uri ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'jobo_oauth_register',
				sprintf(
					/* translators: %s: the connection error reported by WordPress. */
					__( 'Could not reach Jobo to register this site: %s', 'career-site-jobs' ),
					self::clean_message( $response->get_error_message() )
				)
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		$client_id = isset( $decoded['client_id'] ) && is_string( $decoded['client_id'] ) ? sanitize_text_field( $decoded['client_id'] ) : '';

		if ( $status < 200 || $status >= 300 || '' === $client_id ) {
			$description = self::error_description( $decoded );
			if ( '' === $description ) {
				/* translators: %d: HTTP status code. */
				$description = sprintf( __( 'Jobo did not register this site (HTTP %d).', 'career-site-jobs' ), $status );
			}
			return new WP_Error( 'jobo_oauth_register', $description );
		}

		update_option(
			self::OPTION_CLIENT,
			array(
				'client_id'    => $client_id,
				'redirect_uri' => $redirect_uri,
			),
			false
		);

		return $client_id;
	}

	/**
	 * The name the approval screen shows for this site: "WordPress – example.com".
	 */
	private static function client_name( string $redirect_uri ): string {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( '' === $host ) {
			$host = (string) wp_parse_url( $redirect_uri, PHP_URL_HOST );
		}

		return 'WordPress – ' . $host;
	}

	/**
	 * @return array<string,string>
	 */
	private static function request_headers( string $content_type ): array {
		return array(
			'Content-Type'  => $content_type,
			'Accept'        => 'application/json',
			// The same identification the API client sends.
			'User-Agent'    => 'jobo-wordpress/' . JOBO_JOBS_VERSION . '; ' . home_url( '/' ),
			'X-Jobo-Client' => 'wordpress',
		);
	}

	/**
	 * `error_description` from an OAuth error body, made safe to show.
	 *
	 * @param array<string,mixed> $decoded Decoded JSON body.
	 */
	private static function error_description( array $decoded ): string {
		return isset( $decoded['error_description'] ) && is_string( $decoded['error_description'] )
			? self::clean_message( $decoded['error_description'] )
			: '';
	}

	/**
	 * Text from outside the plugin, reduced to one plain line. Still escaped
	 * where it is printed.
	 */
	private static function clean_message( string $message ): string {
		return mb_substr( sanitize_text_field( $message ), 0, 300 );
	}

	/**
	 * A random base64url string: unreserved URL characters only, as PKCE
	 * requires of a verifier. 32 bytes give 43 characters, 48 give 64.
	 *
	 * @throws Exception When the system has no source of randomness.
	 */
	private static function random_token( int $bytes ): string {
		return self::base64url( random_bytes( $bytes ) );
	}

	/**
	 * The S256 challenge for a verifier: BASE64URL( SHA256( verifier ) ), no padding.
	 */
	public static function code_challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	private static function base64url( string $binary ): string {
		// PKCE (RFC 7636) defines its values in this encoding. Nothing is hidden by it.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' );
	}
}

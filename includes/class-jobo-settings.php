<?php
/**
 * Settings storage, sanitisation and the admin screen.
 *
 * @package Jobo_Jobs
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

class Jobo_Settings {

	const OPTION_GROUP = 'jobo_jobs';
	const OPTION_NAME  = 'jobo_jobs_settings';

	/** Filter values are lowercase and canonical on input. */
	const WORK_MODELS      = array( 'remote', 'hybrid', 'onsite' );
	const EMPLOYMENT_TYPES = array( 'full-time', 'part-time', 'contract', 'internship', 'freelance', 'temporary' );
	const EXPERIENCE_LEVELS = array( 'intern', 'entry', 'mid', 'senior', 'lead', 'executive' );

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'api_key'           => '',
			'target'            => 'auto',
			'post_status'       => 'publish',
			'batch_size'        => 250,
			'max_batches'       => 4,
			'sync_interval'     => 'hourly',
			'expire_action'     => 'draft',
			'locations'         => '',
			'sources'           => array(),
			'work_models'       => array(),
			'employment_types'  => array(),
			'experience_levels' => array(),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public static function value( string $key, $default = null ) {
		$settings = self::get();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	public function register(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * @param mixed $input Raw submitted values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$existing = self::get();
		$clean    = self::defaults();

		$submitted_key = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';

		// An all-asterisk value means the masked field was left untouched.
		if ( '' === $submitted_key || preg_match( '/^\*+$/', $submitted_key ) ) {
			$clean['api_key'] = $existing['api_key'];
		} elseif ( Jobo_Client::is_valid_key_format( $submitted_key ) ) {
			$clean['api_key'] = $submitted_key;
		} else {
			$clean['api_key'] = $existing['api_key'];
			add_settings_error(
				self::OPTION_NAME,
				'jobo_invalid_key',
				__( 'That does not look like a Jobo API key. Keys begin with jbe_live_ or jbe_test_ and are 74 characters long. Your previous key has been kept.', 'career-site-jobs' ),
				'error'
			);
		}

		$clean['target']        = in_array( $input['target'] ?? '', array( 'auto', 'wpjm', 'cpt' ), true ) ? $input['target'] : 'auto';
		$clean['post_status']   = in_array( $input['post_status'] ?? '', array( 'publish', 'draft', 'pending' ), true ) ? $input['post_status'] : 'publish';
		$clean['expire_action'] = in_array( $input['expire_action'] ?? '', array( 'draft', 'trash', 'keep' ), true ) ? $input['expire_action'] : 'draft';
		$clean['sync_interval'] = in_array( $input['sync_interval'] ?? '', array( 'hourly', 'twicedaily', 'daily' ), true ) ? $input['sync_interval'] : 'hourly';

		$clean['batch_size']  = min( 1000, max( 1, (int) ( $input['batch_size'] ?? 250 ) ) );
		$clean['max_batches'] = min( 50, max( 1, (int) ( $input['max_batches'] ?? 4 ) ) );

		$clean['locations'] = isset( $input['locations'] ) ? sanitize_textarea_field( $input['locations'] ) : '';

		$clean['sources']           = self::sanitize_list( $input['sources'] ?? array() );
		$clean['work_models']       = self::sanitize_choices( $input['work_models'] ?? array(), self::WORK_MODELS );
		$clean['employment_types']  = self::sanitize_choices( $input['employment_types'] ?? array(), self::EMPLOYMENT_TYPES );
		$clean['experience_levels'] = self::sanitize_choices( $input['experience_levels'] ?? array(), self::EXPERIENCE_LEVELS );

		return $clean;
	}

	/**
	 * @param mixed $value Raw list, either an array or a comma-separated string.
	 * @return string[]
	 */
	private static function sanitize_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $item ) {
			$item = sanitize_text_field( trim( (string) $item ) );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @param mixed    $value   Raw submitted list.
	 * @param string[] $allowed Permitted canonical values.
	 * @return string[]
	 */
	private static function sanitize_choices( $value, array $allowed ): array {
		return array_values( array_intersect( self::sanitize_list( $value ), $allowed ) );
	}

	/**
	 * Feed request body derived from the saved filters.
	 *
	 * @return array<string,mixed>
	 */
	public static function to_feed_params(): array {
		$settings = self::get();
		$params   = array(
			'batch_size'  => (int) $settings['batch_size'],
			'stable_scan' => true,
		);

		foreach ( array( 'sources', 'work_models', 'employment_types', 'experience_levels' ) as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$params[ $key ] = $settings[ $key ];
			}
		}

		$locations = self::parse_locations( (string) $settings['locations'] );
		if ( ! empty( $locations ) ) {
			$params['locations'] = $locations;
		}

		return $params;
	}

	/**
	 * Parse one "City, Region, Country" pair per line into the structured
	 * location objects the feed expects.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function parse_locations( string $raw ): array {
		$out = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$parts = array_values( array_filter( array_map( 'trim', explode( ',', $line ) ), 'strlen' ) );
			if ( empty( $parts ) ) {
				continue;
			}

			// One token is a country; two is region+country; three is city+region+country.
			if ( 1 === count( $parts ) ) {
				$out[] = array( 'country' => $parts[0] );
			} elseif ( 2 === count( $parts ) ) {
				$out[] = array(
					'region'  => $parts[0],
					'country' => $parts[1],
				);
			} else {
				$out[] = array(
					'city'    => $parts[0],
					'region'  => $parts[1],
					'country' => $parts[2],
				);
			}
		}

		return $out;
	}
}

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

	/**
	 * Filter values are lowercase and canonical on input. These mirror the
	 * enums in Jobo.Connectors/contracts/filters.json; the pure-logic tests
	 * fail if they drift.
	 */
	const WORK_MODELS       = array( 'remote', 'hybrid', 'onsite' );
	const EMPLOYMENT_TYPES  = array( 'full-time', 'part-time', 'contract', 'internship', 'freelance', 'temporary' );
	const EXPERIENCE_LEVELS = array( 'intern', 'entry', 'mid', 'senior', 'lead', 'executive' );

	/** Business-model buckets a company's categories hold. */
	const COMPANY_CATEGORIES = array( 'b2b', 'b2c', 'b2b2c', 'b2g', 'saas', 'marketplace', 'e-commerce', 'service-provider', 'hardware', 'agency', 'nonprofit', 'government', 'education' );

	/** Headcount bands, smallest first. */
	const COMPANY_SIZES = array( '1-10', '11-50', '51-200', '201-500', '501-1000', '1001-5000', '5001-10000', '10001+' );

	/** Annual revenue bands, smallest first. */
	const COMPANY_REVENUES = array( 'under-1m', '1m-10m', '10m-50m', '50m-100m', '100m-200m', '200m-1b', 'over-1b' );

	/** Description languages (ISO 639-1) with English names, for the picker. */
	const LANGUAGES = array(
		'en' => 'English',
		'de' => 'German',
		'fr' => 'French',
		'es' => 'Spanish',
		'pt' => 'Portuguese',
		'it' => 'Italian',
		'nl' => 'Dutch',
		'pl' => 'Polish',
		'sv' => 'Swedish',
		'da' => 'Danish',
		'no' => 'Norwegian',
		'fi' => 'Finnish',
		'cs' => 'Czech',
		'sk' => 'Slovak',
		'hu' => 'Hungarian',
		'ro' => 'Romanian',
		'bg' => 'Bulgarian',
		'el' => 'Greek',
		'hr' => 'Croatian',
		'sl' => 'Slovenian',
		'lt' => 'Lithuanian',
		'lv' => 'Latvian',
		'et' => 'Estonian',
		'tr' => 'Turkish',
		'ru' => 'Russian',
		'uk' => 'Ukrainian',
		'he' => 'Hebrew',
		'ar' => 'Arabic',
		'hi' => 'Hindi',
		'id' => 'Indonesian',
		'ms' => 'Malay',
		'th' => 'Thai',
		'vi' => 'Vietnamese',
		'zh' => 'Chinese',
		'ja' => 'Japanese',
		'ko' => 'Korean',
	);

	/** `company_is_agency` setting values: any company, no agencies, agencies only. */
	const AGENCY_CHOICES = array( '', 'none', 'only' );

	/**
	 * Free-text list settings (names the API matches case-insensitively),
	 * stored as string lists. Picked in the admin from live options.
	 */
	const TEXT_LISTS = array(
		'job_categories',
		'exclude_job_categories',
		'job_subcategories',
		'exclude_job_subcategories',
		'industries',
		'exclude_industries',
		'company_primary_industries',
		'exclude_company_primary_industries',
		'exclude_sources',
	);

	/**
	 * Settings whose values are opaque ids, stored with the label they were
	 * picked under so the screen can show it again: lists of
	 * {label, values[]}. A company is every careers-board id it has.
	 */
	const LABELLED_LISTS = array( 'companies', 'exclude_companies', 'occupation_ids', 'exclude_occupation_ids' );

	/**
	 * Closed-vocabulary list settings and the values each allows.
	 *
	 * @return array<string,string[]>
	 */
	public static function choice_lists(): array {
		return array(
			'work_models'                => self::WORK_MODELS,
			'employment_types'           => self::EMPLOYMENT_TYPES,
			'experience_levels'          => self::EXPERIENCE_LEVELS,
			'exclude_work_models'        => self::WORK_MODELS,
			'exclude_employment_types'   => self::EMPLOYMENT_TYPES,
			'exclude_experience_levels'  => self::EXPERIENCE_LEVELS,
			'languages'                  => array_keys( self::LANGUAGES ),
			'exclude_languages'          => array_keys( self::LANGUAGES ),
			'company_categories'         => self::COMPANY_CATEGORIES,
			'exclude_company_categories' => self::COMPANY_CATEGORIES,
			'company_sizes'              => self::COMPANY_SIZES,
			'exclude_company_sizes'      => self::COMPANY_SIZES,
			'company_revenues'           => self::COMPANY_REVENUES,
			'exclude_company_revenues'   => self::COMPANY_REVENUES,
		);
	}

	/**
	 * Every key defaults to "no filter", so settings saved by an earlier
	 * version read back unchanged and send the same feed request.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		$defaults = array(
			'api_key'             => '',
			'target'              => 'auto',
			'post_status'         => 'publish',
			'batch_size'          => 250,
			'max_batches'         => 4,
			'sync_interval'       => 'hourly',
			'expire_action'       => 'draft',
			'locations'           => '',
			'sources'             => array(),
			'company_is_agency'   => '',
			'posted_after'        => '',
			'include_reactivated' => false,
		);

		foreach ( array_merge( array_keys( self::choice_lists() ), self::TEXT_LISTS, self::LABELLED_LISTS ) as $key ) {
			$defaults[ $key ] = array();
		}

		return $defaults;
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

		$clean['sources'] = self::sanitize_list( $input['sources'] ?? array() );

		foreach ( self::choice_lists() as $key => $allowed ) {
			$clean[ $key ] = self::sanitize_choices( $input[ $key ] ?? array(), $allowed );
		}
		foreach ( self::TEXT_LISTS as $key ) {
			$clean[ $key ] = self::sanitize_list( $input[ $key ] ?? array() );
		}
		foreach ( self::LABELLED_LISTS as $key ) {
			$clean[ $key ] = self::sanitize_labelled_list( $input[ $key ] ?? array() );
		}

		$agency                     = (string) ( $input['company_is_agency'] ?? '' );
		$clean['company_is_agency'] = in_array( $agency, self::AGENCY_CHOICES, true ) ? $agency : '';

		$posted_after          = trim( (string) ( $input['posted_after'] ?? '' ) );
		$clean['posted_after'] = self::is_date( $posted_after ) ? $posted_after : '';

		$clean['include_reactivated'] = ! empty( $input['include_reactivated'] );

		return $clean;
	}

	/** A real calendar date in Y-m-d form. */
	public static function is_date( string $value ): bool {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * A submitted list: an array, a JSON array (what the admin pickers post,
	 * so values may contain commas), or a comma-separated string (the no-JS
	 * fallback field).
	 *
	 * @param mixed $value Raw submitted value.
	 * @return array<int,mixed>
	 */
	private static function decode_list( $value ): array {
		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			if ( '' === $trimmed ) {
				return array();
			}
			if ( '[' === $trimmed[0] ) {
				$decoded = json_decode( $trimmed, true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
			return explode( ',', $trimmed );
		}

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param mixed $value Raw list: an array, a JSON array or a comma-separated string.
	 * @return string[]
	 */
	private static function sanitize_list( $value ): array {
		$out = array();
		foreach ( self::decode_list( $value ) as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$item = sanitize_text_field( trim( (string) $item ) );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Picked ids with the label they were picked under. Accepts the pickers'
	 * JSON ([{label, values: [...]}]) and plain values (the no-JS field, or an
	 * id/domain typed in), which label themselves.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return array<int,array{label:string,values:string[]}>
	 */
	public static function sanitize_labelled_list( $value ): array {
		$out  = array();
		$seen = array();

		foreach ( self::decode_list( $value ) as $item ) {
			if ( is_array( $item ) ) {
				$values = self::sanitize_list( isset( $item['values'] ) && is_array( $item['values'] ) ? $item['values'] : array( $item['value'] ?? '' ) );
				$label  = sanitize_text_field( trim( (string) ( $item['label'] ?? '' ) ) );
			} elseif ( is_scalar( $item ) ) {
				$values = self::sanitize_list( array( $item ) );
				$label  = '';
			} else {
				continue;
			}

			if ( empty( $values ) ) {
				continue;
			}

			$identity = implode( ',', $values );
			if ( isset( $seen[ $identity ] ) ) {
				continue;
			}
			$seen[ $identity ] = true;

			$out[] = array(
				'label'  => '' !== $label ? $label : implode( ', ', $values ),
				'values' => $values,
			);
		}

		return $out;
	}

	/**
	 * Every value of a labelled list, flattened.
	 *
	 * @param mixed $items Stored labelled list.
	 * @return string[]
	 */
	public static function labelled_values( $items ): array {
		$out = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			foreach ( is_array( $item ) && isset( $item['values'] ) && is_array( $item['values'] ) ? $item['values'] : array() as $value ) {
				$out[] = (string) $value;
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
	 * Only filters that are set are sent, so a site that never touches the
	 * newer filters sends exactly the request it sent before them — and
	 * keeps its sync watermark (see Jobo_Sync::filters_fingerprint()).
	 *
	 * @param array<string,mixed>|null $settings Settings to use; the saved ones by default.
	 * @return array<string,mixed>
	 */
	public static function to_feed_params( ?array $settings = null ): array {
		$settings = null === $settings ? self::get() : wp_parse_args( $settings, self::defaults() );
		$params   = array(
			'batch_size'         => (int) $settings['batch_size'],
			'stable_scan'        => true,
			// Listings are stored as post content, which WordPress renders as
			// HTML; Markdown would show its syntax. Not a filter, so it is
			// left out of the sync fingerprint.
			'description_format' => 'html',
		);

		$plain_lists = array(
			'sources',
			'work_models',
			'employment_types',
			'experience_levels',
			'languages',
			'exclude_sources',
			'exclude_work_models',
			'exclude_employment_types',
			'exclude_experience_levels',
			'exclude_languages',
		);
		foreach ( $plain_lists as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$params[ $key ] = array_values( (array) $settings[ $key ] );
			}
		}

		// The include/exclude filters are { include, exclude } objects on the
		// wire; a flat exclude_* key for these would be ignored by the API.
		$pairs = array(
			'companies'                  => array( self::labelled_values( $settings['companies'] ), self::labelled_values( $settings['exclude_companies'] ) ),
			'occupation_ids'             => array( self::labelled_values( $settings['occupation_ids'] ), self::labelled_values( $settings['exclude_occupation_ids'] ) ),
			'job_categories'             => array( (array) $settings['job_categories'], (array) $settings['exclude_job_categories'] ),
			'job_subcategories'          => array( (array) $settings['job_subcategories'], (array) $settings['exclude_job_subcategories'] ),
			'industries'                 => array( (array) $settings['industries'], (array) $settings['exclude_industries'] ),
			'company_primary_industries' => array( (array) $settings['company_primary_industries'], (array) $settings['exclude_company_primary_industries'] ),
			'company_categories'         => array( (array) $settings['company_categories'], (array) $settings['exclude_company_categories'] ),
			'company_sizes'              => array( (array) $settings['company_sizes'], (array) $settings['exclude_company_sizes'] ),
			'company_revenues'           => array( (array) $settings['company_revenues'], (array) $settings['exclude_company_revenues'] ),
		);
		foreach ( $pairs as $key => $pair ) {
			list( $include, $exclude ) = $pair;
			$filter                    = array();
			if ( ! empty( $include ) ) {
				$filter['include'] = array_values( $include );
			}
			if ( ! empty( $exclude ) ) {
				// A feed request body, not a WP_Query: the sniff keys on the word.
				$filter['exclude'] = array_values( $exclude ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
			}
			if ( ! empty( $filter ) ) {
				$params[ $key ] = $filter;
			}
		}

		if ( 'none' === $settings['company_is_agency'] ) {
			$params['company_is_agency'] = false;
		} elseif ( 'only' === $settings['company_is_agency'] ) {
			$params['company_is_agency'] = true;
		}

		if ( self::is_date( (string) $settings['posted_after'] ) ) {
			$params['posted_after'] = $settings['posted_after'] . 'T00:00:00Z';
			// Only meaningful with posted_after: a reopened job keeps its
			// original posting date, so the window alone would miss it.
			if ( ! empty( $settings['include_reactivated'] ) ) {
				$params['include_reactivated'] = true;
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

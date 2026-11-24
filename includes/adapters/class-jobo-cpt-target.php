<?php
/**
 * Standalone target.
 *
 * Registers a self-contained `jobo_job` post type so the plugin is useful on
 * sites without WP Job Manager. Meta keys mirror the WP Job Manager names where
 * they overlap, which keeps a later migration to WPJM a matter of changing the
 * post type rather than remapping fields.
 *
 * WP Job Manager renders its own listings; this post type has no templates of
 * its own, so the plugin adds to its single view what a theme cannot know to
 * show: a details block with the apply link, and JobPosting structured data.
 * Each has a filter so a theme can change or drop it.
 *
 * @package Jobo_Jobs
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

class Jobo_CPT_Target extends Jobo_Abstract_Target {

	const POST_TYPE = 'jobo_job';

	/** Feed employment types (lowercased) as schema.org employmentType values. */
	const SCHEMA_EMPLOYMENT_TYPES = array(
		'full-time'  => 'FULL_TIME',
		'part-time'  => 'PART_TIME',
		'contract'   => 'CONTRACTOR',
		'freelance'  => 'CONTRACTOR',
		'temporary'  => 'TEMPORARY',
		'internship' => 'INTERN',
	);

	/** Feed pay periods as schema.org unitText values. */
	const SCHEMA_SALARY_UNITS = array(
		'hourly'  => 'HOUR',
		'daily'   => 'DAY',
		'weekly'  => 'WEEK',
		'monthly' => 'MONTH',
		'yearly'  => 'YEAR',
	);

	public function get_id(): string {
		return 'cpt';
	}

	public function get_label(): string {
		return __( 'Jobo Jobs (built-in)', 'career-site-jobs' );
	}

	public function is_available(): bool {
		return true;
	}

	protected function get_post_type(): string {
		return self::POST_TYPE;
	}

	/**
	 * Registered on every load, not just when this target is selected, so that
	 * previously synced posts remain visible after switching targets.
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Jobs', 'career-site-jobs' ),
					'singular_name' => __( 'Job', 'career-site-jobs' ),
					'menu_name'     => __( 'Jobs', 'career-site-jobs' ),
				),
				'public'       => true,
				'has_archive'  => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-portfolio',
				'rewrite'      => array( 'slug' => 'jobs' ),
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ),
			)
		);
	}

	/**
	 * Front-end hooks for this post type's single view. Registered on every
	 * load like the post type, and inert on any other page.
	 */
	public static function register_front_end(): void {
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_structured_data' ) );
	}

	/**
	 * @param int                 $post_id Post being written.
	 * @param array<string,mixed> $job     A JobDto from the feed.
	 */
	protected function write_meta( int $post_id, array $job ): void {
		$company = isset( $job['company'] ) && is_array( $job['company'] ) ? $job['company'] : array();

		$this->set_meta( $post_id, '_company_name', sanitize_text_field( (string) ( $company['name'] ?? '' ) ) );
		$this->set_meta( $post_id, '_company_website', esc_url_raw( (string) ( $company['website'] ?? '' ) ) );
		$this->set_meta( $post_id, '_company_logo', esc_url_raw( (string) ( $company['logo_url'] ?? '' ) ) );
		$this->set_meta( $post_id, '_job_location', $this->format_location( $job ) );
		$this->set_meta( $post_id, '_application', esc_url_raw( (string) ( $job['apply_url'] ?? $job['listing_url'] ?? '' ) ) );

		// The first location's parts, for the structured data's address.
		$first = isset( $job['locations'][0] ) && is_array( $job['locations'][0] ) ? $job['locations'][0] : array();
		foreach ( array( 'city', 'region', 'country' ) as $part ) {
			$this->set_meta( $post_id, '_jobo_' . $part, sanitize_text_field( (string) ( $first[ $part ] ?? '' ) ) );
		}

		foreach ( array( 'employment_type', 'workplace_type', 'experience_level' ) as $key ) {
			$this->set_meta( $post_id, '_jobo_' . $key, sanitize_text_field( (string) ( $job[ $key ] ?? '' ) ) );
		}

		// A job that no longer states a salary must not keep the old one.
		$compensation = isset( $job['compensation'] ) && is_array( $job['compensation'] ) ? $job['compensation'] : array();
		$this->set_meta( $post_id, '_jobo_salary_min', isset( $compensation['min'] ) ? (float) $compensation['min'] : '' );
		$this->set_meta( $post_id, '_jobo_salary_max', isset( $compensation['max'] ) ? (float) $compensation['max'] : '' );
		$this->set_meta( $post_id, '_jobo_salary_currency', sanitize_text_field( (string) ( $compensation['currency'] ?? '' ) ) );
		$this->set_meta( $post_id, '_jobo_salary_period', sanitize_text_field( (string) ( $compensation['period'] ?? '' ) ) );

		if ( ! empty( $job['valid_through'] ) ) {
			$expires = strtotime( (string) $job['valid_through'] );
			if ( false !== $expires ) {
				$this->set_meta( $post_id, '_job_expires', gmdate( 'Y-m-d', $expires ) );
			}
		}

		delete_post_meta( $post_id, self::META_EXPIRED );
	}

	/**
	 * Append the details block (company, location, employment type, salary
	 * and the apply link) to a listing's content on its own page.
	 *
	 * @param mixed $content Post content.
	 * @return mixed
	 */
	public static function filter_content( $content ) {
		if ( ! is_singular( self::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = (int) get_the_ID();
		if ( $post_id <= 0 || self::POST_TYPE !== get_post_type( $post_id ) ) {
			return $content;
		}

		$fields = self::listing_fields( $post_id );

		/**
		 * Filters the details block appended to a Jobo job listing on its own
		 * page. Return an empty string to leave it out.
		 *
		 * @param string              $html    The block's HTML.
		 * @param int                 $post_id The listing.
		 * @param array<string,mixed> $fields  The values the block was built from.
		 */
		$html = apply_filters( 'jobo_jobs_listing_details', self::details_html( $fields ), $post_id, $fields );

		return (string) $content . ( is_string( $html ) ? $html : '' );
	}

	/**
	 * Print JobPosting structured data on a published listing's own page.
	 */
	public static function print_structured_data(): void {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 || self::POST_TYPE !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return;
		}

		$fields = self::listing_fields( $post_id );

		// A closed job left published must not be advertised as open.
		$data = $fields['closed'] ? array() : self::job_posting_schema( $fields );

		/**
		 * Filters the JobPosting structured data printed on a Jobo job
		 * listing's own page. Return an empty array to print none.
		 *
		 * @param array<string,mixed> $data    schema.org JobPosting, as an array.
		 * @param int                 $post_id The listing.
		 * @param array<string,mixed> $fields  The values it was built from.
		 */
		$data = apply_filters( 'jobo_jobs_job_posting_schema', $data, $post_id, $fields );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return;
		}

		// JSON_HEX_TAG writes < and > as < and >, so nothing in the
		// data can close the script element.
		$json = wp_json_encode( $data, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return;
		}

		wp_print_inline_script_tag( $json, array( 'type' => 'application/ld+json' ) );
	}

	/**
	 * A listing's values, as the sync stored them.
	 *
	 * @return array<string,mixed>
	 */
	public static function listing_fields( int $post_id ): array {
		$meta = static function ( string $key ) use ( $post_id ): string {
			$value = get_post_meta( $post_id, $key, true );
			return is_scalar( $value ) ? (string) $value : '';
		};

		return array(
			'job_id'          => $meta( self::META_JOB_ID ),
			'title'           => (string) get_post_field( 'post_title', $post_id ),
			'description'     => (string) get_post_field( 'post_content', $post_id ),
			'date_posted'     => (string) get_post_time( 'c', true, $post_id ),
			'valid_through'   => $meta( '_job_expires' ),
			'company_name'    => $meta( '_company_name' ),
			'company_website' => $meta( '_company_website' ),
			'company_logo'    => $meta( '_company_logo' ),
			'location'        => $meta( '_job_location' ),
			'city'            => $meta( '_jobo_city' ),
			'region'          => $meta( '_jobo_region' ),
			'country'         => $meta( '_jobo_country' ),
			'employment_type' => $meta( '_jobo_employment_type' ),
			'workplace_type'  => $meta( '_jobo_workplace_type' ),
			'salary_min'      => $meta( '_jobo_salary_min' ),
			'salary_max'      => $meta( '_jobo_salary_max' ),
			'salary_currency' => $meta( '_jobo_salary_currency' ),
			'salary_period'   => $meta( '_jobo_salary_period' ),
			'apply_url'       => $meta( '_application' ),
			'closed'          => '' !== $meta( self::META_EXPIRED ),
		);
	}

	/**
	 * The details block: company, location, employment type and salary when
	 * known, then the apply link, or a closed notice for a job that closed.
	 * Empty when there is nothing to show.
	 *
	 * @param array<string,mixed> $fields listing_fields() values.
	 */
	public static function details_html( array $fields ): string {
		$location = implode(
			' · ',
			array_filter(
				array( (string) ( $fields['location'] ?? '' ), (string) ( $fields['workplace_type'] ?? '' ) ),
				'strlen'
			)
		);

		$rows = array_filter(
			array(
				__( 'Company', 'career-site-jobs' )         => (string) ( $fields['company_name'] ?? '' ),
				__( 'Location', 'career-site-jobs' )        => $location,
				__( 'Employment type', 'career-site-jobs' ) => (string) ( $fields['employment_type'] ?? '' ),
				__( 'Salary', 'career-site-jobs' )          => self::format_salary( $fields ),
			),
			'strlen'
		);

		$apply  = ! empty( $fields['closed'] ) ? '' : (string) ( $fields['apply_url'] ?? '' );
		$closed = ! empty( $fields['closed'] );

		if ( empty( $rows ) && '' === $apply && ! $closed ) {
			return '';
		}

		$html = '<div class="jobo-job-details">';

		if ( ! empty( $rows ) ) {
			$html .= '<dl class="jobo-job-meta">';
			foreach ( $rows as $label => $value ) {
				$html .= '<dt>' . esc_html( (string) $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
			}
			$html .= '</dl>';
		}

		if ( $closed ) {
			$html .= '<p class="jobo-job-closed">' . esc_html__( 'This job is no longer open.', 'career-site-jobs' ) . '</p>';
		} elseif ( '' !== $apply ) {
			$html .= '<p class="jobo-job-apply"><a class="jobo-job-apply-link" href="' . esc_url( $apply ) . '" target="_blank" rel="nofollow noopener">'
				. esc_html__( 'Apply for this job', 'career-site-jobs' )
				. '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'career-site-jobs' ) . '</span></a></p>';
		}

		return $html . '</div>';
	}

	/**
	 * "USD 50,000 – 70,000 per year", or '' when no amount is known.
	 *
	 * @param array<string,mixed> $fields listing_fields() values.
	 */
	public static function format_salary( array $fields ): string {
		$min = self::salary_amount( $fields['salary_min'] ?? '' );
		$max = self::salary_amount( $fields['salary_max'] ?? '' );
		if ( null === $min && null === $max ) {
			return '';
		}

		$format = static function ( float $amount ): string {
			return number_format_i18n( $amount, floor( $amount ) === $amount ? 0 : 2 );
		};

		if ( null !== $min && null !== $max && $min !== $max ) {
			$amount = $format( $min ) . ' – ' . $format( $max );
		} else {
			$amount = $format( null !== $min ? $min : (float) $max );
		}

		$currency = (string) ( $fields['salary_currency'] ?? '' );
		if ( '' !== $currency ) {
			$amount = $currency . ' ' . $amount;
		}

		$periods = array(
			'hourly'  => __( 'per hour', 'career-site-jobs' ),
			'daily'   => __( 'per day', 'career-site-jobs' ),
			'weekly'  => __( 'per week', 'career-site-jobs' ),
			'monthly' => __( 'per month', 'career-site-jobs' ),
			'yearly'  => __( 'per year', 'career-site-jobs' ),
		);
		$period  = strtolower( (string) ( $fields['salary_period'] ?? '' ) );

		return isset( $periods[ $period ] ) ? $amount . ' ' . $periods[ $period ] : $amount;
	}

	/**
	 * schema.org JobPosting for a listing, or an empty array when it has no
	 * title. Optional properties are left out when unknown.
	 *
	 * @param array<string,mixed> $fields listing_fields() values.
	 * @return array<string,mixed>
	 */
	public static function job_posting_schema( array $fields ): array {
		$text = static function ( string $key ) use ( $fields ): string {
			return isset( $fields[ $key ] ) && is_scalar( $fields[ $key ] ) ? trim( (string) $fields[ $key ] ) : '';
		};

		if ( '' === $text( 'title' ) ) {
			return array();
		}

		$data = array(
			'@context'    => 'https://schema.org/',
			'@type'       => 'JobPosting',
			'title'       => $text( 'title' ),
			'description' => '' !== $text( 'description' ) ? $text( 'description' ) : $text( 'title' ),
		);

		if ( '' !== $text( 'date_posted' ) ) {
			$data['datePosted'] = $text( 'date_posted' );
		}
		if ( '' !== $text( 'valid_through' ) ) {
			$data['validThrough'] = $text( 'valid_through' );
		}

		if ( '' !== $text( 'company_name' ) ) {
			$organization = array(
				'@type' => 'Organization',
				'name'  => $text( 'company_name' ),
			);
			if ( '' !== $text( 'company_website' ) ) {
				$organization['sameAs'] = $text( 'company_website' );
			}
			if ( '' !== $text( 'company_logo' ) ) {
				$organization['logo'] = $text( 'company_logo' );
			}
			$data['hiringOrganization'] = $organization;
		}

		if ( 'remote' === strtolower( $text( 'workplace_type' ) ) ) {
			$data['jobLocationType'] = 'TELECOMMUTE';
			if ( '' !== $text( 'country' ) ) {
				$data['applicantLocationRequirements'] = array(
					'@type' => 'Country',
					'name'  => $text( 'country' ),
				);
			}
		} else {
			$address = array_filter(
				array(
					'addressLocality' => $text( 'city' ),
					'addressRegion'   => $text( 'region' ),
					'addressCountry'  => $text( 'country' ),
				),
				'strlen'
			);
			// Listings synced before the parts were stored have only the
			// display string.
			if ( empty( $address ) && '' !== $text( 'location' ) ) {
				$address = array( 'addressLocality' => $text( 'location' ) );
			}
			if ( ! empty( $address ) ) {
				$data['jobLocation'] = array(
					'@type'   => 'Place',
					'address' => array_merge( array( '@type' => 'PostalAddress' ), $address ),
				);
			}
		}

		$employment = strtolower( str_replace( ' ', '-', $text( 'employment_type' ) ) );
		if ( array_key_exists( $employment, self::SCHEMA_EMPLOYMENT_TYPES ) ) {
			$data['employmentType'] = self::SCHEMA_EMPLOYMENT_TYPES[ $employment ];
		}

		$min = self::salary_amount( $fields['salary_min'] ?? '' );
		$max = self::salary_amount( $fields['salary_max'] ?? '' );
		if ( null !== $min || null !== $max ) {
			$value = array( '@type' => 'QuantitativeValue' );
			if ( null !== $min && null !== $max && $min !== $max ) {
				$value['minValue'] = self::schema_number( $min );
				$value['maxValue'] = self::schema_number( $max );
			} else {
				$value['value'] = self::schema_number( null !== $min ? $min : (float) $max );
			}
			$unit = strtolower( $text( 'salary_period' ) );
			if ( array_key_exists( $unit, self::SCHEMA_SALARY_UNITS ) ) {
				$value['unitText'] = self::SCHEMA_SALARY_UNITS[ $unit ];
			}

			$salary = array( '@type' => 'MonetaryAmount' );
			if ( '' !== $text( 'salary_currency' ) ) {
				$salary['currency'] = $text( 'salary_currency' );
			}
			$salary['value']    = $value;
			$data['baseSalary'] = $salary;
		}

		if ( '' !== $text( 'job_id' ) ) {
			$data['identifier'] = array(
				'@type' => 'PropertyValue',
				'name'  => '' !== $text( 'company_name' ) ? $text( 'company_name' ) : 'Jobo',
				'value' => $text( 'job_id' ),
			);
		}

		// Applying happens on the employer's site, not on this one.
		$data['directApply'] = false;

		return $data;
	}

	/**
	 * A stored salary bound as a positive number, or null when unknown.
	 *
	 * @param mixed $value Stored meta value.
	 */
	private static function salary_amount( $value ): ?float {
		if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
			return null;
		}
		return (float) $value;
	}

	/**
	 * A whole amount as an integer, so it prints without a decimal point.
	 *
	 * @return int|float
	 */
	private static function schema_number( float $amount ) {
		return floor( $amount ) === $amount ? (int) $amount : $amount;
	}
}

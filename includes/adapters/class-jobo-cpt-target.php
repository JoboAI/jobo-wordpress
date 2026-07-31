<?php
/**
 * Standalone target.
 *
 * Registers a self-contained `jobo_job` post type so the plugin is useful on
 * sites without WP Job Manager. Meta keys mirror the WP Job Manager names where
 * they overlap, which keeps a later migration to WPJM a matter of changing the
 * post type rather than remapping fields.
 *
 * @package Jobo_Jobs
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

class Jobo_CPT_Target extends Jobo_Abstract_Target {

	const POST_TYPE = 'jobo_job';

	public function get_id(): string {
		return 'cpt';
	}

	public function get_label(): string {
		return __( 'Jobo Jobs (built-in)', 'jobo-jobs' );
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
					'name'          => __( 'Jobs', 'jobo-jobs' ),
					'singular_name' => __( 'Job', 'jobo-jobs' ),
					'menu_name'     => __( 'Jobs', 'jobo-jobs' ),
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
	 * @param int                 $post_id Post being written.
	 * @param array<string,mixed> $job     A JobDto from the feed.
	 */
	protected function write_meta( int $post_id, array $job ): void {
		$company = isset( $job['company'] ) && is_array( $job['company'] ) ? $job['company'] : array();

		update_post_meta( $post_id, '_company_name', sanitize_text_field( (string) ( $company['name'] ?? '' ) ) );
		update_post_meta( $post_id, '_company_website', esc_url_raw( (string) ( $company['website'] ?? '' ) ) );
		update_post_meta( $post_id, '_company_logo', esc_url_raw( (string) ( $company['logo_url'] ?? '' ) ) );
		update_post_meta( $post_id, '_job_location', $this->format_location( $job ) );
		update_post_meta( $post_id, '_application', esc_url_raw( (string) ( $job['apply_url'] ?? $job['listing_url'] ?? '' ) ) );

		foreach ( array( 'employment_type', 'workplace_type', 'experience_level' ) as $key ) {
			update_post_meta( $post_id, '_jobo_' . $key, sanitize_text_field( (string) ( $job[ $key ] ?? '' ) ) );
		}

		if ( isset( $job['compensation'] ) && is_array( $job['compensation'] ) ) {
			$compensation = $job['compensation'];
			update_post_meta( $post_id, '_jobo_salary_min', isset( $compensation['min'] ) ? (float) $compensation['min'] : '' );
			update_post_meta( $post_id, '_jobo_salary_max', isset( $compensation['max'] ) ? (float) $compensation['max'] : '' );
			update_post_meta( $post_id, '_jobo_salary_currency', sanitize_text_field( (string) ( $compensation['currency'] ?? '' ) ) );
			update_post_meta( $post_id, '_jobo_salary_period', sanitize_text_field( (string) ( $compensation['period'] ?? '' ) ) );
		}

		if ( ! empty( $job['valid_through'] ) ) {
			$expires = strtotime( (string) $job['valid_through'] );
			if ( false !== $expires ) {
				update_post_meta( $post_id, '_job_expires', gmdate( 'Y-m-d', $expires ) );
			}
		}

		delete_post_meta( $post_id, '_jobo_expired' );
	}
}

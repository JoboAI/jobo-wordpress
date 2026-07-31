<?php
/**
 * WP Job Manager target.
 *
 * Writes the `job_listing` post type and the meta keys WP Job Manager's
 * templates read, so synced jobs render through the existing theme with no
 * extra configuration.
 *
 * @package Jobo_Jobs
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

class Jobo_WPJM_Target extends Jobo_Abstract_Target {

	public function get_id(): string {
		return 'wpjm';
	}

	public function get_label(): string {
		return __( 'WP Job Manager', 'career-site-jobs' );
	}

	public function is_available(): bool {
		return post_type_exists( 'job_listing' );
	}

	protected function get_post_type(): string {
		return 'job_listing';
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

		// WP Job Manager accepts either an email or a URL in _application.
		$apply = (string) ( $job['apply_url'] ?? $job['listing_url'] ?? '' );
		update_post_meta( $post_id, '_application', esc_url_raw( $apply ) );

		$remote = isset( $job['workplace_type'] ) && 'remote' === strtolower( (string) $job['workplace_type'] );
		update_post_meta( $post_id, '_remote_position', $remote ? 1 : 0 );

		if ( ! empty( $job['valid_through'] ) ) {
			$expires = strtotime( (string) $job['valid_through'] );
			if ( false !== $expires ) {
				update_post_meta( $post_id, '_job_expires', gmdate( 'Y-m-d', $expires ) );
			}
		}

		// A freshly (re)synced job is by definition still open.
		update_post_meta( $post_id, '_filled', 0 );
		delete_post_meta( $post_id, '_jobo_expired' );

		$this->assign_type( $post_id, $job );
	}

	/**
	 * Map employment type onto the job_listing_type taxonomy.
	 *
	 * Matching is by slug first, then name. That order matters: WP Job Manager
	 * ships "Full Time" (a space) while the API returns "Full-time" (a hyphen),
	 * so a name comparison misses, but both reduce to the slug `full-time`.
	 *
	 * Types the site has no term for — `Contract` is not among WP Job Manager's
	 * defaults — are created rather than dropped, otherwise those listings would
	 * silently lose their type and disappear from type-filtered views.
	 *
	 * The taxonomy is optional: WP Job Manager only registers it when job types
	 * are enabled in its settings, so this is a no-op on sites that turned them
	 * off.
	 *
	 * @param int                 $post_id Post being written.
	 * @param array<string,mixed> $job     A JobDto from the feed.
	 */
	private function assign_type( int $post_id, array $job ): void {
		if ( empty( $job['employment_type'] ) || ! taxonomy_exists( 'job_listing_type' ) ) {
			return;
		}

		$label = sanitize_text_field( (string) $job['employment_type'] );
		$slug  = sanitize_title( $label );

		$term = get_term_by( 'slug', $slug, 'job_listing_type' );

		if ( ! $term ) {
			$term = get_term_by( 'name', $label, 'job_listing_type' );
		}

		if ( ! $term ) {
			$created = wp_insert_term( $label, 'job_listing_type', array( 'slug' => $slug ) );
			if ( is_wp_error( $created ) ) {
				return;
			}
			$term_id = (int) $created['term_id'];
		} else {
			$term_id = (int) $term->term_id;
		}

		wp_set_object_terms( $post_id, $term_id, 'job_listing_type', false );
	}

	public function expire( string $job_id, string $action ): bool {
		$post_id = $this->find_post_id( $job_id );
		if ( ! $post_id ) {
			return false;
		}

		// Mark filled as well as applying the configured action — WP Job
		// Manager's templates key off _filled, so a kept-but-closed listing
		// still reads correctly on the front end.
		update_post_meta( $post_id, '_filled', 1 );

		return parent::expire( $job_id, $action );
	}
}

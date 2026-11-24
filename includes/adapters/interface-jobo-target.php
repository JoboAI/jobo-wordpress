<?php
/**
 * Where synced jobs land.
 *
 * WP Job Manager is the primary target; the standalone post type exists so the
 * plugin still works on sites that do not run it.
 *
 * @package Jobo_Jobs
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

interface Jobo_Target {

	/** Machine name, matching the `target` setting. */
	public function get_id(): string;

	/** Human-readable name for the admin screen. */
	public function get_label(): string;

	/** Whether this target can be used on the current site. */
	public function is_available(): bool;

	/** Called once per sync run before any upsert. */
	public function prepare(): void;

	/**
	 * Resolve a whole batch of Jobo job ids to existing post ids in one query.
	 *
	 * @param string[] $job_ids Jobo job ids about to be upserted.
	 */
	public function prime_lookup( array $job_ids ): void;

	/**
	 * Create or update a listing. An update leaves the status alone, except
	 * that a listing expire() retired, and nobody has touched since, gets
	 * back the status it had: the job has reopened.
	 *
	 * @param array<string,mixed> $job         A JobDto from the feed.
	 * @param string              $post_status Desired status for new posts.
	 * @return string 'created', 'updated' or 'skipped'.
	 */
	public function upsert( array $job, string $post_status ): string;

	/**
	 * Retire a listing whose job is no longer active, remembering the status
	 * it had so upsert() can restore it if the job reopens.
	 *
	 * @param string $job_id Jobo job id.
	 * @param string $action 'draft', 'trash' or 'keep'.
	 * @return bool True when a local listing was found and acted on.
	 */
	public function expire( string $job_id, string $action ): bool;

	/**
	 * Jobo job ids of open listings last synced before a time: ones not yet
	 * retired and not in the trash.
	 *
	 * @param int $before Unix time.
	 * @param int $limit  Most ids to return.
	 * @return string[]
	 */
	public function stale_job_ids( int $before, int $limit ): array;
}

/**
 * Shared mapping and lookup logic.
 *
 * Listings are keyed on the Jobo job id held in `_jobo_job_id`, which is what
 * makes repeated syncs idempotent — the sync's watermark overlap re-delivers
 * jobs by design.
 */
abstract class Jobo_Abstract_Target implements Jobo_Target {

	const META_JOB_ID     = '_jobo_job_id';
	const META_SOURCE     = '_jobo_source';
	const META_SYNCED_AT  = '_jobo_synced_at';
	const META_EXPIRED    = '_jobo_expired';

	/**
	 * Set when expire() changed a listing's status: {from, to}, the status it
	 * had and the one expire() left it in. Cleared by the next upsert.
	 */
	const META_RETIRED    = '_jobo_retired';

	abstract protected function get_post_type(): string;

	/**
	 * Write target-specific meta and taxonomies.
	 *
	 * @param int                 $post_id Post being written.
	 * @param array<string,mixed> $job     A JobDto from the feed.
	 */
	abstract protected function write_meta( int $post_id, array $job ): void;

	/** @var array<string,int|null> Job id => post id, primed per batch. */
	protected $lookup = array();

	public function prepare(): void {
		$this->lookup = array();
	}

	/**
	 * Resolve a batch of job ids in a single query.
	 *
	 * `wp_postmeta` indexes meta_key but not meta_value, so a per-job lookup
	 * re-scans every `_jobo_job_id` row — on a board with tens of thousands of
	 * listings that is quadratic across a sync run. One `IN (...)` query per
	 * batch collapses it to a single pass.
	 *
	 * @param string[] $job_ids Jobo job ids about to be upserted.
	 */
	public function prime_lookup( array $job_ids ): void {
		global $wpdb;

		$job_ids = array_values( array_unique( array_filter( array_map( 'strval', $job_ids ), 'strlen' ) ) );
		if ( empty( $job_ids ) ) {
			return;
		}

		$this->lookup = array_fill_keys( $job_ids, null );

		$placeholders = implode( ',', array_fill( 0, count( $job_ids ), '%s' ) );

		// The placeholder run is built from a counted array and every value is
		// passed through prepare(); the sniff cannot count through the
		// interpolated $placeholders string.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS job_id, pm.post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND pm.meta_value IN ($placeholders) AND p.post_type = %s",
				array_merge( array( self::META_JOB_ID ), $job_ids, array( $this->get_post_type() ) )
			)
		);
		// phpcs:enable

		foreach ( (array) $rows as $row ) {
			$this->lookup[ (string) $row->job_id ] = (int) $row->post_id;
		}
	}

	/**
	 * @param array<string,mixed> $job         A JobDto from the feed.
	 * @param string              $post_status Desired status for new posts.
	 */
	public function upsert( array $job, string $post_status ): string {
		$job_id = isset( $job['id'] ) ? sanitize_text_field( (string) $job['id'] ) : '';
		if ( '' === $job_id ) {
			return 'skipped';
		}

		$existing = $this->find_post_id( $job_id );

		$postarr = array(
			'post_type'    => $this->get_post_type(),
			'post_title'   => sanitize_text_field( (string) ( $job['title'] ?? '' ) ),
			'post_content' => wp_kses_post( (string) ( $job['description'] ?? '' ) ),
			'post_excerpt' => sanitize_text_field( (string) ( $job['summary'] ?? '' ) ),
		);

		if ( ! empty( $job['date_posted'] ) ) {
			$timestamp = strtotime( (string) $job['date_posted'] );
			if ( false !== $timestamp ) {
				$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
				$postarr['post_date']     = get_date_from_gmt( $postarr['post_date_gmt'] );
			}
		}

		if ( $existing ) {
			$postarr['ID'] = $existing;

			// Status is deliberately not forced on update, so an editor's
			// manual unpublish is not undone on the next sync. The exception
			// is a listing expire() retired that is still as expire() left
			// it: its job has reopened, so it gets back the status it had.
			$current = (string) get_post_status( $existing );
			$restore = self::restore_status( get_post_meta( $existing, self::META_RETIRED, true ), $current );
			if ( '' !== $restore ) {
				if ( 'trash' === $current ) {
					// Clears the trash bookkeeping and the slug's __trashed suffix.
					wp_untrash_post( $existing );
				}
				$postarr['post_status'] = $restore;
			}

			// wp_update_post() and wp_insert_post() unslash their input, which
			// would strip every backslash from the title and description.
			$result = wp_update_post( wp_slash( $postarr ), true );
			$action = 'updated';
		} else {
			$postarr['post_status'] = $post_status;
			$result                 = wp_insert_post( wp_slash( $postarr ), true );
			$action                 = 'created';
		}

		if ( is_wp_error( $result ) || ! $result ) {
			return 'skipped';
		}

		$post_id = (int) $result;

		// Keep the primed map correct so a duplicate id inside one batch
		// updates the row we just wrote rather than inserting a second post.
		$this->lookup[ $job_id ] = $post_id;

		$this->set_meta( $post_id, self::META_JOB_ID, $job_id );
		$this->set_meta( $post_id, self::META_SOURCE, sanitize_text_field( (string) ( $job['source'] ?? '' ) ) );
		$this->set_meta( $post_id, self::META_SYNCED_AT, time() );

		// Restored or left as an editor set it, the retirement is over.
		delete_post_meta( $post_id, self::META_RETIRED );

		$this->write_meta( $post_id, $job );

		return $action;
	}

	public function expire( string $job_id, string $action ): bool {
		$post_id = $this->find_post_id( $job_id );
		if ( ! $post_id ) {
			return false;
		}

		$current = (string) get_post_status( $post_id );
		$to      = in_array( $action, array( 'draft', 'trash' ), true ) ? $action : '';

		// A listing an editor put in the trash stays there.
		if ( '' !== $to && 'trash' !== $current && $to !== $current ) {
			$marker = get_post_meta( $post_id, self::META_RETIRED, true );

			if ( 'trash' === $to ) {
				wp_trash_post( $post_id );
			} else {
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'draft',
					)
				);
			}

			// Retired again before it reopened (drafted, then trashed): the
			// status to come back to is still the one it had first.
			$from = is_array( $marker ) && isset( $marker['from'], $marker['to'] ) && $current === (string) $marker['to'] ? (string) $marker['from'] : $current;

			$this->set_meta(
				$post_id,
				self::META_RETIRED,
				array(
					'from' => $from,
					'to'   => $to,
				)
			);
		}

		$this->set_meta( $post_id, self::META_EXPIRED, 1 );

		return true;
	}

	/**
	 * The status a reopened job's listing goes back to: the one it had before
	 * expire() retired it, but only while it is still in the status expire()
	 * left it in. Anything else is an editor's choice, which wins. Empty
	 * means leave the status alone.
	 *
	 * @param mixed  $marker  The stored META_RETIRED value.
	 * @param string $current The listing's status now.
	 */
	public static function restore_status( $marker, string $current ): string {
		if ( ! is_array( $marker ) || empty( $marker['from'] ) || empty( $marker['to'] ) ) {
			return '';
		}

		if ( $current !== (string) $marker['to'] || $current === (string) $marker['from'] ) {
			return '';
		}

		return (string) $marker['from'];
	}

	/**
	 * update_post_meta() unslashes the value it is given, which would strip
	 * backslashes from it; slashing first stores the value as it is.
	 *
	 * @param int    $post_id Post being written.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value to store.
	 */
	protected function set_meta( int $post_id, string $key, $value ): void {
		update_post_meta( $post_id, $key, wp_slash( $value ) );
	}

	/**
	 * @param int $before Unix time.
	 * @param int $limit  Most ids to return.
	 * @return string[]
	 */
	public function stale_job_ids( int $before, int $limit ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.meta_value
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 INNER JOIN {$wpdb->postmeta} synced ON synced.post_id = pm.post_id AND synced.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} expired ON expired.post_id = pm.post_id AND expired.meta_key = %s
				 WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status <> 'trash'
				   AND expired.meta_id IS NULL AND CAST( synced.meta_value AS UNSIGNED ) < %d
				 LIMIT %d",
				self::META_SYNCED_AT,
				self::META_EXPIRED,
				self::META_JOB_ID,
				$this->get_post_type(),
				$before,
				$limit
			)
		);
		// phpcs:enable

		return array_map( 'strval', (array) $ids );
	}

	/**
	 * Locate an existing listing by Jobo job id.
	 *
	 * Served from the batch-primed map when available; falls back to a single
	 * query for callers outside a sync run, such as the expiry pass.
	 */
	protected function find_post_id( string $job_id ): ?int {
		if ( array_key_exists( $job_id, $this->lookup ) ) {
			return $this->lookup[ $job_id ];
		}

		$found = get_posts(
			array(
				'post_type'        => $this->get_post_type(),
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_key'         => self::META_JOB_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $job_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$post_id = ! empty( $found ) ? (int) $found[0] : null;

		$this->lookup[ $job_id ] = $post_id;

		return $post_id;
	}

	/**
	 * First location rendered as a display string.
	 *
	 * @param array<string,mixed> $job A JobDto from the feed.
	 */
	protected function format_location( array $job ): string {
		if ( empty( $job['locations'] ) || ! is_array( $job['locations'] ) ) {
			return '';
		}

		$first = $job['locations'][0];
		if ( ! is_array( $first ) ) {
			return '';
		}

		if ( ! empty( $first['location'] ) ) {
			return sanitize_text_field( (string) $first['location'] );
		}

		$parts = array();
		foreach ( array( 'city', 'region', 'country' ) as $key ) {
			if ( ! empty( $first[ $key ] ) ) {
				$parts[] = (string) $first[ $key ];
			}
		}

		return sanitize_text_field( implode( ', ', $parts ) );
	}
}

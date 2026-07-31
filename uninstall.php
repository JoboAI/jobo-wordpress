<?php
/**
 * Uninstall cleanup.
 *
 * Removes plugin settings and sync state. Imported listings are deliberately
 * left in place — they are the site's content, and silently deleting a job
 * board on uninstall would be indefensible.
 *
 * @package Jobo_Jobs
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'jobo_jobs_settings' );
delete_option( 'jobo_jobs_state' );

wp_clear_scheduled_hook( 'jobo_jobs_sync' );

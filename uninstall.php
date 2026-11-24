<?php
/**
 * Uninstall cleanup.
 *
 * Removes plugin settings, sync state, the run lock, the settings pickers'
 * cached options and what "Sign in with Jobo" stored (the site's OAuth client
 * id and the connected account). A key the site was given
 * by signing in is handed back to Jobo first, so deleting the plugin does not
 * leave a live key behind. Imported listings are deliberately left in place —
 * they are the site's content, and silently deleting a job board on uninstall
 * would be indefensible.
 *
 * @package Jobo_Jobs
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The plugin's classes are not loaded during uninstall, so this repeats what
// Jobo_OAuth::disconnect() sends: the key, and the app it was issued to. Only
// a key stored by a sign-in is sent; a pasted key is the owner's own. Jobo
// being unreachable must not stop the uninstall, so the answer is not read.
$jobo_jobs_settings = get_option( 'jobo_jobs_settings', array() );
$jobo_jobs_account  = get_option( 'jobo_jobs_oauth_account', array() );
$jobo_jobs_client   = get_option( 'jobo_jobs_oauth_client', array() );
$jobo_jobs_api_key  = is_array( $jobo_jobs_settings ) && isset( $jobo_jobs_settings['api_key'] ) ? (string) $jobo_jobs_settings['api_key'] : '';

if (
	'' !== $jobo_jobs_api_key
	&& is_array( $jobo_jobs_account ) && isset( $jobo_jobs_account['key_hash'] )
	&& hash_equals( (string) $jobo_jobs_account['key_hash'], hash( 'sha256', $jobo_jobs_api_key ) )
	&& is_array( $jobo_jobs_client ) && ! empty( $jobo_jobs_client['client_id'] )
) {
	wp_remote_post(
		'https://enterprise.jobo.world/connect/revoke',
		array(
			'timeout' => 5,
			'headers' => array( 'X-Jobo-Client' => 'wordpress' ),
			'body'    => array(
				'token'     => $jobo_jobs_api_key,
				'client_id' => (string) $jobo_jobs_client['client_id'],
			),
		)
	);
}

delete_option( 'jobo_jobs_settings' );
delete_option( 'jobo_jobs_state' );
delete_option( 'jobo_jobs_oauth_client' );
delete_option( 'jobo_jobs_oauth_account' );
delete_option( 'jobo_jobs_sync_lock' );

// The cached lookup lists behind the settings screen's filter fields
// (Jobo_Ajax::FILTER_OPTIONS_TRANSIENT and OCCUPATIONS_TRANSIENT), and the
// filter-options cache's first version.
delete_transient( 'jobo_filter_options_v2' );
delete_transient( 'jobo_filter_options' );
delete_transient( 'jobo_occupations' );

wp_clear_scheduled_hook( 'jobo_jobs_sync' );

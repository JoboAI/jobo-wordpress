<?php
/**
 * Plugin Name:       Job Board & Career Site Jobs – Jobo
 * Plugin URI:        https://jobo.world/integrations/wordpress
 * Description:       Fill your job board automatically with career site jobs from 150+ ATS platforms. Incremental sync keeps listings fresh and expires them when they close.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Jobo
 * Author URI:        https://jobo.world
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       career-site-jobs
 *
 * @package Jobo_Jobs
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'JOBO_JOBS_VERSION', '0.1.0' );
define( 'JOBO_JOBS_FILE', __FILE__ );
define( 'JOBO_JOBS_DIR', plugin_dir_path( __FILE__ ) );

require_once JOBO_JOBS_DIR . 'includes/class-jobo-api-exception.php';
require_once JOBO_JOBS_DIR . 'includes/class-jobo-client.php';
require_once JOBO_JOBS_DIR . 'includes/class-jobo-state.php';
require_once JOBO_JOBS_DIR . 'includes/class-jobo-settings.php';
require_once JOBO_JOBS_DIR . 'includes/adapters/interface-jobo-target.php';
require_once JOBO_JOBS_DIR . 'includes/adapters/class-jobo-wpjm-target.php';
require_once JOBO_JOBS_DIR . 'includes/adapters/class-jobo-cpt-target.php';
require_once JOBO_JOBS_DIR . 'includes/class-jobo-sync.php';
require_once JOBO_JOBS_DIR . 'includes/class-jobo-admin-notices.php';
require_once JOBO_JOBS_DIR . 'includes/class-jobo-ajax.php';
require_once JOBO_JOBS_DIR . 'includes/class-jobo-plugin.php';

/**
 * Plugin singleton accessor.
 *
 * @return Jobo_Plugin
 */
function jobo_jobs(): Jobo_Plugin {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Jobo_Plugin();
	}

	return $instance;
}

jobo_jobs()->boot();

register_activation_hook( __FILE__, array( 'Jobo_Plugin', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'Jobo_Plugin', 'on_deactivate' ) );

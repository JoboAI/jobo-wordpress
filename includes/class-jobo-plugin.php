<?php
/**
 * Plugin bootstrap: hooks, cron scheduling and the settings screen.
 *
 * @package Jobo_Jobs
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

class Jobo_Plugin {

	const ACTION_SYNC_NOW = 'jobo_jobs_sync_now';
	const ACTION_RESYNC   = 'jobo_jobs_full_resync';

	/** @var Jobo_State */
	private $state;

	/** @var Jobo_Settings */
	private $settings;

	/** @var Jobo_Sync */
	private $sync;

	/** @var string Hook suffix of the settings page, for scoped enqueueing. */
	private $page_hook = '';

	public function __construct() {
		$this->state    = new Jobo_State();
		$this->settings = new Jobo_Settings();
		$this->sync     = new Jobo_Sync( $this->state );
	}

	public function boot(): void {
		add_action( 'init', array( 'Jobo_CPT_Target', 'register_post_type' ) );
		add_action( 'admin_init', array( $this->settings, 'register' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_notices', array( new Jobo_Admin_Notices( $this->state ), 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		Jobo_Ajax::register();

		add_action( Jobo_Sync::CRON_HOOK, array( $this, 'run_scheduled_sync' ) );
		add_action( 'admin_post_' . self::ACTION_SYNC_NOW, array( $this, 'handle_sync_now' ) );
		add_action( 'admin_post_' . self::ACTION_RESYNC, array( $this, 'handle_full_resync' ) );

		// Keep the cron schedule aligned with the configured interval.
		add_action( 'update_option_' . Jobo_Settings::OPTION_NAME, array( $this, 'reschedule' ) );
	}

	public static function on_activate(): void {
		Jobo_CPT_Target::register_post_type();
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( Jobo_Sync::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, (string) Jobo_Settings::value( 'sync_interval', 'hourly' ), Jobo_Sync::CRON_HOOK );
		}
	}

	public static function on_deactivate(): void {
		wp_clear_scheduled_hook( Jobo_Sync::CRON_HOOK );
		flush_rewrite_rules();
	}

	public function reschedule(): void {
		wp_clear_scheduled_hook( Jobo_Sync::CRON_HOOK );
		wp_schedule_event( time() + MINUTE_IN_SECONDS, (string) Jobo_Settings::value( 'sync_interval', 'hourly' ), Jobo_Sync::CRON_HOOK );
	}

	public function run_scheduled_sync(): void {
		$this->sync->run();
	}

	public function add_settings_page(): void {
		$hook = add_options_page(
			__( 'Jobo Jobs', 'career-site-jobs' ),
			__( 'Jobo Jobs', 'career-site-jobs' ),
			'manage_options',
			'career-site-jobs',
			array( $this, 'render_settings_page' )
		);

		$this->page_hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Styles and behaviour for the settings screen only — every other admin
	 * page is left untouched.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( '' === $this->page_hook || $hook_suffix !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style( 'jobo-jobs-tokens', plugins_url( 'assets/tokens.css', JOBO_JOBS_FILE ), array(), JOBO_JOBS_VERSION );
		wp_enqueue_style( 'jobo-jobs-combobox', plugins_url( 'assets/combobox.css', JOBO_JOBS_FILE ), array( 'jobo-jobs-tokens' ), JOBO_JOBS_VERSION );
		wp_enqueue_style( 'jobo-jobs-admin', plugins_url( 'assets/admin.css', JOBO_JOBS_FILE ), array( 'jobo-jobs-tokens', 'jobo-jobs-combobox' ), JOBO_JOBS_VERSION );

		wp_enqueue_script( 'jobo-jobs-combobox', plugins_url( 'assets/combobox.js', JOBO_JOBS_FILE ), array(), JOBO_JOBS_VERSION, true );
		wp_enqueue_script( 'jobo-jobs-admin', plugins_url( 'assets/admin.js', JOBO_JOBS_FILE ), array( 'jobo-jobs-combobox' ), JOBO_JOBS_VERSION, true );

		$settings = Jobo_Settings::get();

		$location_lines = array_values(
			array_filter(
				array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $settings['locations'] ) ?: array() ),
				'strlen'
			)
		);

		$config = array(
			'nonce'          => wp_create_nonce( Jobo_Ajax::NONCE_ACTION ),
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			// The client's pattern without its delimiters, ready for `new RegExp`.
			'keyPattern'     => trim( Jobo_Client::KEY_PATTERN, '/' ),
			'enums'          => array(
				'work_models'       => Jobo_Settings::WORK_MODELS,
				'employment_types'  => Jobo_Settings::EMPLOYMENT_TYPES,
				'experience_levels' => Jobo_Settings::EXPERIENCE_LEVELS,
			),
			'savedLocations' => $location_lines,
			'savedSources'   => array_values( (array) $settings['sources'] ),
			'i18n'           => array(
				'locationsPlaceholder' => __( 'Search for a city, region or country', 'career-site-jobs' ),
				'locationsAriaLabel'   => __( 'Locations', 'career-site-jobs' ),
				'sourcesPlaceholder'   => __( 'Type to filter sources, for example greenhouse', 'career-site-jobs' ),
				'sourcesAriaLabel'     => __( 'Sources', 'career-site-jobs' ),
				'noMatches'            => __( 'No matches', 'career-site-jobs' ),
				'connected'            => __( 'Connected', 'career-site-jobs' ),
				/* translators: %s: remaining credit count. */
				'connectedCredits'     => __( 'Connected — %s credits remaining', 'career-site-jobs' ),
				'connectionFailed'     => __( 'The connection test failed.', 'career-site-jobs' ),
				/* translators: %d: HTTP status code. */
				'requestFailed'        => __( 'The request failed (HTTP %d).', 'career-site-jobs' ),
			),
		);

		wp_add_inline_script( 'jobo-jobs-admin', 'var joboAdmin = ' . (string) wp_json_encode( $config ) . ';', 'before' );
	}

	public function handle_sync_now(): void {
		$this->guard( self::ACTION_SYNC_NOW );

		$summary = $this->sync->run();

		$this->redirect_back(
			'ok' === $summary['status'] ? 'synced' : 'failed',
			array(
				'created' => (int) $summary['created'],
				'updated' => (int) $summary['updated'],
				'expired' => (int) $summary['expired'],
			)
		);
	}

	public function handle_full_resync(): void {
		$this->guard( self::ACTION_RESYNC );

		$this->state->reset_cursor();
		$summary = $this->sync->run();

		$this->redirect_back(
			'ok' === $summary['status'] ? 'resynced' : 'failed',
			array(
				'created' => (int) $summary['created'],
				'updated' => (int) $summary['updated'],
			)
		);
	}

	/**
	 * Capability plus nonce check for the admin-post actions.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'career-site-jobs' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * @param array<string,int> $args Extra query args for the notice.
	 */
	private function redirect_back( string $result, array $args = array() ): void {
		wp_safe_redirect(
			add_query_arg(
				array_merge( array( 'jobo_result' => $result ), $args ),
				admin_url( 'options-general.php?page=career-site-jobs' )
			)
		);
		exit;
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Jobo_Settings::get();
		$state    = $this->state->all();
		$target   = $this->sync->resolve_target();
		$has_key  = '' !== $settings['api_key'];

		require JOBO_JOBS_DIR . 'views/settings-page.php';
	}
}

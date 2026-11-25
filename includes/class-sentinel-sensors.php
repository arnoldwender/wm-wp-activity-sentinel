<?php
/**
 * WM Suite Activity Sentinel — sensor hooks (the list in init_sensors() is complete)
 *
 * @package WM_Activity_Sentinel
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Sentinel_Sensors {

	/** Event types the client tracker produces; anything else posted to /telemetry is refused. */
	public const TELEMETRY_EVENT_TYPES = [ 'admin_menu_click', 'admin_bar_click', 'tab_switch_click', 'table_action_click', 'button_pressed', 'download_link_clicked', 'link_clicked', 'interactive_element_click', 'ui_click' ];

	/** Categories the client tracker produces. */
	public const TELEMETRY_CATEGORIES = [ 'navigation', 'action', 'ui_interaction', 'export' ];

	/** Metadata keys the client tracker produces. */
	public const TELEMETRY_METADATA_KEYS = [ 'element_tag', 'element_id', 'element_text', 'element_href', 'element_classes', 'action_description', 'dom_path', 'page_url', 'timestamp_js', 'object_id' ];

	/** Telemetry entries accepted per user per minute. */
	public const TELEMETRY_RATE_LIMIT = 60;

	/**
	 * Register all event sensor hooks
	 */
	public function init_sensors(): void {
		// 1. Exports, backups and the file editor
		add_action( 'export_wp', [ $this, 'on_native_export_triggered' ] );
		add_action( 'admin_init', [ $this, 'on_admin_export_page_visited' ] );
		add_action( 'load-plugin-editor.php', [ $this, 'on_plugin_editor_accessed' ] );
		add_action( 'load-theme-editor.php', [ $this, 'on_theme_editor_accessed' ] );
		// The file editor saves through admin-ajax; priority 0 runs before core's handler at 1.
		// Until 2026-09-15 this listened to 'wp_edit_theme_plugin_file', an action WordPress never fires.
		add_action( 'wp_ajax_edit-theme-plugin-file', [ $this, 'on_code_file_save_requested' ], 0 );

		// 2. Database Backup Plugin Surveillance
		add_action( 'updraft_backup_started', [ $this, 'on_backup_triggered' ] );
		add_action( 'duplicator_backup_started', [ $this, 'on_backup_triggered' ] );

		// 3. Plugins, themes, updates and selected options
		add_action( 'activated_plugin', [ $this, 'on_plugin_activated' ], 10, 2 );
		add_action( 'deactivated_plugin', [ $this, 'on_plugin_deactivated' ], 10, 2 );
		add_action( 'delete_plugin', [ $this, 'on_plugin_deleted' ] );
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrader_process' ], 10, 2 );
		add_action( 'after_switch_theme', [ $this, 'on_theme_switched' ], 10, 2 );
		add_action( 'updated_option', [ $this, 'on_critical_option_updated' ], 10, 3 );

		// 4. Authentication & Identity
		add_action( 'wp_login_failed', [ $this, 'on_login_failed' ] );
		add_action( 'wp_login', [ $this, 'on_login_success' ], 10, 2 );
		add_action( 'user_register', [ $this, 'on_user_registered' ] );
		add_action( 'deleted_user', [ $this, 'on_user_deleted' ], 10, 2 );
		add_action( 'set_user_role', [ $this, 'on_user_role_changed' ], 10, 3 );
		// Until 2026-09-15: 'application_password_created', an action WordPress never fires.
		add_action( 'wp_create_application_password', [ $this, 'on_app_password_created' ], 10, 2 );

		// 5. REST API Surveillance
		add_filter( 'rest_pre_dispatch', [ $this, 'on_rest_pre_dispatch' ], 10, 3 );

		// 6. Navigation & Screen View Sensors
		add_action( 'current_screen', [ $this, 'on_admin_screen_viewed' ] );
		add_action( 'template_redirect', [ $this, 'on_frontend_page_viewed' ] );

		// 7. Client-Side Telemetry & Interaction Ingestion
		add_action( 'rest_api_init', [ $this, 'register_telemetry_routes' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_tracker' ] );

		// 8. Inter-Plugin Audit & Security Incident Bridges
		add_action( 'wm_activity_sentinel_log_event', [ $this, 'on_hub_audit_event' ], 10, 1 );
		add_action( 'wm_sentinel_security_incident', [ $this, 'on_security_incident' ], 10, 1 );
	}

	/**
	 * Sensor: Native WordPress Export
	 */
	public function on_native_export_triggered(): void {
		WM_Sentinel_Logger::log(
			get_current_user_id(),
			'export_wp_native_triggered',
			'export',
			null,
			'critical',
			[
				'uri'         => sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ),
				'content_type'=> sanitize_text_field( wp_unslash( $_GET['content'] ?? 'all' ) ),
				'notice'      => 'Native WordPress XML content export initiated.',
			]
		);
	}

	/**
	 * Sensor: Export page visit
	 */
	public function on_admin_export_page_visited(): void {
		global $pagenow;
		if ( 'export.php' === $pagenow && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			WM_Sentinel_Logger::log(
				get_current_user_id(),
				'export_page_inspected',
				'export',
				null,
				'warning',
				[ 'uri' => 'export.php' ]
			);
		}
	}

	/**
	 * Sensor: Plugin Editor Accessed
	 */
	public function on_plugin_editor_accessed(): void {
		WM_Sentinel_Logger::log(
			get_current_user_id(),
			'plugin_theme_file_viewed',
			'file',
			null,
			'warning',
			[
				'editor' => 'plugin-editor.php',
				'file'   => sanitize_text_field( wp_unslash( $_GET['file'] ?? '' ) ),
				'plugin' => sanitize_text_field( wp_unslash( $_GET['plugin'] ?? '' ) ),
			]
		);
	}

	/**
	 * Sensor: Theme Editor Accessed
	 */
	public function on_theme_editor_accessed(): void {
		WM_Sentinel_Logger::log(
			get_current_user_id(),
			'plugin_theme_file_viewed',
			'file',
			null,
			'warning',
			[
				'editor' => 'theme-editor.php',
				'file'   => sanitize_text_field( wp_unslash( $_GET['file'] ?? '' ) ),
				'theme'  => sanitize_text_field( wp_unslash( $_GET['theme'] ?? '' ) ),
			]
		);
	}

	/**
	 * Sensor: a save request from the plugin or theme file editor. Recorded before WordPress
	 * processes it, so the entry says a save was requested, not that it succeeded.
	 */
	public function on_code_file_save_requested(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only record; core's handler verifies the nonce.
		WM_Sentinel_Logger::log(
			get_current_user_id(),
			'plugin_theme_file_save_requested',
			'file',
			null,
			'critical',
			[
				'file'   => sanitize_text_field( wp_unslash( (string) ( $_POST['file'] ?? '' ) ) ),
				'plugin' => sanitize_text_field( wp_unslash( (string) ( $_POST['plugin'] ?? '' ) ) ),
				'theme'  => sanitize_text_field( wp_unslash( (string) ( $_POST['theme'] ?? '' ) ) ),
			]
		);
		// phpcs:enable
	}

	/**
	 * Sensor: Third-party DB Backup
	 */
	public function on_backup_triggered(): void {
		WM_Sentinel_Logger::log(
			get_current_user_id(),
			'database_backup_requested',
			'backup',
			null,
			'critical',
			[
				'trigger' => current_filter(),
			]
		);
	}

	/**
	 * Sensor: Plugin Activated
	 */
	public function on_plugin_activated( string $plugin, bool $network_wide ): void {
		WM_Sentinel_Logger::log(
			get_current_user_id(),
			'plugin_activated',
			'plugin',
			null,
			'notice',
			[
				'plugin'       => $plugin,
				'network_wide' => $network_wide,
			]
		);
	}

	/**
	 * Sensor: Plugin Deactivated
	 */
	public function on_plugin_deactivated( string $plugin, bool $network_wide ): void {
		WM_Sentinel_Logger::log(
			get_current_user_id(),
			'plugin_deactivated',
			'plugin',
			null,
			'warning',
			[
				'plugin'       => $plugin,
				'network_wide' => $network_wide,
			]
		);
	}

	/**
	 * Sensor: Plugin Deleted
	 */
	public function on_plugin_deleted( string $plugin_file ): void {
		WM_Sentinel_Logger::log(
			get_current_user_id(),
			'plugin_deleted',
			'plugin',
			null,
			'warning',
			[ 'plugin' => $plugin_file ]
		);
	}

	/**
	 * Sensor: Upgrader Plugin/Theme Install or Update
	 */
	public function on_upgrader_process( object $upgrader, array $extra ): void {
		WM_Sentinel_Logger::log(
			get_current_user_id(),
			'upgrader_process_executed',
			'system',
			null,
			'notice',
			$extra
		);
	}

	/**
	 * Sensor: Theme Switched. `after_switch_theme` passes the PREVIOUS theme; until 2026-09-15 it was
	 * logged as the new one.
	 */
	public function on_theme_switched( string $old_name, $old_theme = null ): void {
		WM_Sentinel_Logger::log(
			get_current_user_id(),
			'theme_switched',
			'theme',
			null,
			'warning',
			[
				'new_theme'      => (string) wp_get_theme()->get( 'Name' ),
				'previous_theme' => $old_name,
			]
		);
	}

	/**
	 * Sensor: Critical Core Options Updated
	 */
	public function on_critical_option_updated( string $option, mixed $old_value, mixed $value ): void {
		$monitored_options = [
			'siteurl',
			'home',
			'users_can_register',
			'default_role',
			'active_plugins',
			'admin_email',
			'wm_nda_require_login_checkbox',
			'wm_nda_enforce_admin_gate',
			'wm_sentinel_alert_email',
		];
		$monitored_options = (array) apply_filters( 'wm_sentinel_monitored_options', $monitored_options );

		if ( in_array( $option, $monitored_options, true ) ) {
			WM_Sentinel_Logger::log(
				get_current_user_id(),
				'core_options_updated',
				'option',
				null,
				'notice',
				[
					'option_name' => $option,
					'old_value'   => is_scalar( $old_value ) ? (string) $old_value : '[complex]',
					'new_value'   => is_scalar( $value ) ? (string) $value : '[complex]',
				]
			);
		}
	}

	/**
	 * Sensor: Login Failed
	 */
	public function on_login_failed( string $username ): void {
		WM_Sentinel_Logger::log(
			null,
			'user_login_failed',
			'user',
			null,
			'warning',
			[
				'username_attempt' => sanitize_text_field( $username ),
				'user_agent'       => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown' ) ),
			]
		);
	}

	/**
	 * Sensor: Login Success
	 */
	public function on_login_success( string $user_login, WP_User $user ): void {
		WM_Sentinel_Logger::log(
			(int) $user->ID,
			'user_login_success',
			'user',
			(int) $user->ID,
			'info',
			[
				'roles' => $user->roles,
			]
		);
	}

	/**
	 * Sensor: User Registered
	 */
	public function on_user_registered( int $user_id ): void {
		$user = get_userdata( $user_id );
		WM_Sentinel_Logger::log(
			get_current_user_id(),
			'user_created',
			'user',
			$user_id,
			'notice',
			[
				'new_user_login' => $user ? $user->user_login : 'Unknown',
				'new_user_email' => $user ? $user->user_email : 'Unknown',
			]
		);
	}

	/**
	 * Sensor: User Deleted
	 */
	public function on_user_deleted( int $deleted_id, ?int $reassign ): void {
		WM_Sentinel_Logger::log(
			get_current_user_id(),
			'user_deleted',
			'user',
			$deleted_id,
			'warning',
			[
				'deleted_user_id' => $deleted_id,
				'reassign_to'     => $reassign,
			]
		);
	}

	/**
	 * Sensor: User Role Changed
	 */
	public function on_user_role_changed( int $user_id, string $role, array $old_roles ): void {
		$severity = ( 'administrator' === $role ) ? 'critical' : 'notice';
		WM_Sentinel_Logger::log(
			get_current_user_id(),
			'user_role_changed',
			'user',
			$user_id,
			$severity,
			[
				'target_user_id' => $user_id,
				'new_role'       => $role,
				'old_roles'      => $old_roles,
			]
		);
	}

	/**
	 * Sensor: Application Password Created. Core passes the plain password as the third argument;
	 * the callback takes only two so it never reaches the log.
	 */
	public function on_app_password_created( int $user_id, array $new_item ): void {
		WM_Sentinel_Logger::log(
			get_current_user_id(),
			'application_password_created',
			'user',
			$user_id,
			'warning',
			[
				'target_user_id' => $user_id,
				'app_name'       => sanitize_text_field( (string) ( $new_item['name'] ?? '' ) ),
			]
		);
	}

	/**
	 * Sensor: Sensitive REST API Surveillance
	 */
	public function on_rest_pre_dispatch( mixed $result, mixed $server, WP_REST_Request $request ): mixed {
		$route = $request->get_route();

		if ( str_starts_with( $route, '/wp/v2/plugins' ) ) {
			WM_Sentinel_Logger::log(
				get_current_user_id(),
				'rest_plugins_enumerated',
				'api',
				null,
				'warning',
				[ 'route' => $route, 'method' => $request->get_method() ]
			);
		} elseif ( str_starts_with( $route, '/wp/v2/users' ) && ! is_user_logged_in() ) {
			WM_Sentinel_Logger::log(
				null,
				'rest_users_enumerated',
				'api',
				null,
				'warning',
				[ 'route' => $route, 'method' => $request->get_method() ]
			);
		}

		return $result;
	}

	/**
	 * Sensor: Admin Screen / Page Opened
	 */
	public function on_admin_screen_viewed( ?WP_Screen $screen = null ): void {
		if ( ! (bool) get_option( 'wm_sentinel_monitor_screens', 1 ) ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! $screen && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
		}

		if ( ! $screen ) {
			return;
		}

		// Avoid heartbeat / autosave noise
		if ( isset( $_POST['action'] ) && 'heartbeat' === $_POST['action'] ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$friendly_title = $this->get_friendly_screen_title( $screen );
		$object_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : ( isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : null );

		WM_Sentinel_Logger::log(
			$user_id,
			'admin_screen_viewed',
			'navigation',
			$object_id,
			'info',
			[
				'screen_id'    => $screen->id,
				'screen_base'  => $screen->base,
				'screen_title' => $friendly_title,
				'post_type'    => $screen->post_type ?: null,
				'taxonomy'     => $screen->taxonomy ?: null,
				'uri'          => sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ),
			]
		);
	}

	/**
	 * Sensor: Frontend Prototype Page / Content Viewed
	 */
	public function on_frontend_page_viewed(): void {
		if ( ! (bool) get_option( 'wm_sentinel_monitor_frontend_views', 1 ) ) {
			return;
		}

		if ( ( is_admin() && ! defined( 'WM_SENTINEL_TESTING_MODE' ) ) || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		if ( is_feed() || is_robots() || is_trackback() ) {
			return;
		}

		$post_id = get_queried_object_id();
		$title = $post_id ? get_the_title( $post_id ) : ( is_front_page() ? 'Startseite' : 'Frontend-Ansicht' );

		WM_Sentinel_Logger::log(
			$user_id,
			'frontend_page_viewed',
			'navigation',
			$post_id ?: null,
			'info',
			[
				'post_id'    => $post_id ?: null,
				'post_title' => $title,
				'post_type'  => get_post_type( $post_id ) ?: ( is_front_page() ? 'front_page' : 'archive' ),
				'uri'        => sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ),
				'referrer'   => sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ),
			]
		);
	}

	/**
	 * Register Client-Side Telemetry Ingestion Route
	 */
	public function register_telemetry_routes(): void {
		register_rest_route( 'wm-sentinel/v1', '/telemetry', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_telemetry_beacon' ],
			'permission_callback' => [ self::class, 'telemetry_permission' ],
		] );
	}

	/**
	 * Only signed-in users write telemetry; the tracker is only loaded for them. Until 2026-09-15 a
	 * visitor with any valid REST nonce for user 0 was let in as well.
	 *
	 * @return true|\WP_Error
	 */
	public static function telemetry_permission() {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new \WP_Error(
			'rest_forbidden',
			__( 'Zugriff auf Sentinel Telemetrie erfordert eine aktive Sitzung.', 'wm-activity-sentinel' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Handle Telemetry Batch from Client Tracker
	 *
	 * Until 2026-09-15 any signed-in account, a subscriber included, could post up to 25 entries per
	 * request with any event type, category and severity: a forged `user_role_changed` marked
	 * critical went into the chain and sent the alert mail. Entries are now limited to what the
	 * tracker produces, always severity info, at most TELEMETRY_RATE_LIMIT per user per minute.
	 */
	public function handle_telemetry_beacon( WP_REST_Request $request ): WP_REST_Response {
		if ( ! (bool) get_option( 'wm_sentinel_monitor_ui_interactions', 1 ) ) {
			return new WP_REST_Response( [ 'success' => true, 'logged' => 0, 'notice' => 'UI Interaction monitoring disabled.' ], 200 );
		}

		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		$events = $params['events'] ?? [];
		if ( empty( $events ) || ! is_array( $events ) ) {
			if ( isset( $params['event_type'] ) ) {
				$events = [ $params ];
			} else {
				return new WP_REST_Response( [ 'error' => 'No valid events in payload.' ], 400 );
			}
		}

		$user_id      = get_current_user_id();
		$logged_count = 0;
		$refused      = 0;
		$rate_key     = 'wm_sentinel_telemetry_' . $user_id . '_' . gmdate( 'YmdHi' );
		$used         = (int) get_transient( $rate_key );
		$slice        = array_slice( $events, 0, 25 );

		foreach ( $slice as $evt ) {
			if ( ! is_array( $evt ) ) {
				continue;
			}

			$event_type = sanitize_key( (string) ( $evt['event_type'] ?? 'ui_click' ) );
			$category   = sanitize_key( (string) ( $evt['category'] ?? 'ui_interaction' ) );
			if ( ! in_array( $event_type, self::TELEMETRY_EVENT_TYPES, true ) || ! in_array( $category, self::TELEMETRY_CATEGORIES, true ) ) {
				$refused++;
				continue;
			}
			if ( $used >= self::TELEMETRY_RATE_LIMIT ) {
				$refused++;
				continue;
			}

			$raw_meta   = is_array( $evt['metadata'] ?? null ) ? $evt['metadata'] : [];
			$clean_meta = [];
			foreach ( self::TELEMETRY_METADATA_KEYS as $key ) {
				if ( isset( $raw_meta[ $key ] ) && is_scalar( $raw_meta[ $key ] ) ) {
					$clean_meta[ $key ] = mb_substr( sanitize_text_field( (string) $raw_meta[ $key ] ), 0, 300 );
				}
			}

			$object_id = isset( $clean_meta['object_id'] ) ? absint( $clean_meta['object_id'] ) : null;

			$log_id = WM_Sentinel_Logger::log(
				$user_id,
				$event_type,
				$category,
				$object_id ?: null,
				'info',
				$clean_meta
			);

			$used++;
			if ( $log_id ) {
				$logged_count++;
			}
		}

		set_transient( $rate_key, $used, MINUTE_IN_SECONDS * 2 );

		return new WP_REST_Response( [
			'success' => true,
			'logged'  => $logged_count,
			'refused' => $refused,
		], 200 );
	}

	/**
	 * Enqueue client tracker on frontend for logged-in reviewers
	 */
	public function enqueue_frontend_tracker(): void {
		if ( ! is_user_logged_in() || ! (bool) get_option( 'wm_sentinel_monitor_ui_interactions', 1 ) ) {
			return;
		}

		// Versioned by file time like the admin copy: with the plugin version a changed tracker stayed in browser
		// caches until the next release (until 2026-09-15).
		$tracker = WM_SENTINEL_PATH . 'assets/js/sentinel-tracker.js';
		wp_enqueue_script(
			'wm-sentinel-tracker',
			WM_SENTINEL_URL . 'assets/js/sentinel-tracker.js',
			[],
			(string) ( @filemtime( $tracker ) ?: WM_SENTINEL_VERSION ),
			true
		);

		wp_localize_script(
			'wm-sentinel-tracker',
			'wmSentinelTrackerConfig',
			[
				'restUrl'            => esc_url_raw( rest_url( 'wm-sentinel/v1/telemetry' ) ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'userId'             => get_current_user_id(),
				'telemetryIntensity' => get_option( 'wm_sentinel_telemetry_intensity', 'all' ),
				'debug'              => defined( 'WP_DEBUG' ) && WP_DEBUG,
			]
		);
	}

	/**
	 * Helper: Resolve human-friendly title for WordPress admin screen
	 */
	private function get_friendly_screen_title( WP_Screen $screen ): string {
		$map = [
			'dashboard'         => 'Dashboard (Startseite)',
			'plugins'           => 'Plugins-Übersicht',
			'plugin-install'    => 'Plugins hinzufügen/installieren',
			'plugin-editor'     => 'Plugin-Dateieditor',
			'themes'            => 'Design / Themes-Übersicht',
			'theme-editor'      => 'Theme-Dateieditor',
			'options-general'   => 'Einstellungen > Allgemein',
			'options-writing'   => 'Einstellungen > Schreiben',
			'options-reading'   => 'Einstellungen > Lesen',
			'options-discussion'=> 'Einstellungen > Diskussion',
			'options-media'     => 'Einstellungen > Medien',
			'options-permalink' => 'Einstellungen > Permalinks',
			'options-privacy'   => 'Einstellungen > Datenschutz',
			'users'             => 'Benutzer-Übersicht',
			'user-edit'         => 'Benutzerprofil bearbeiten',
			'profile'           => 'Eigenes Profil',
			'user-new'          => 'Neuen Benutzer anlegen',
			'upload'            => 'Mediathek',
			'media-new'         => 'Neue Medien hochladen',
			'tools'             => 'Werkzeuge-Übersicht',
			'export'            => 'Daten exportieren',
			'import'            => 'Daten importieren',
			'site-health'       => 'Website-Zustand (Site Health)',
			'export-personal-data' => 'Personenbezogene Daten exportieren',
			'erase-personal-data'  => 'Personenbezogene Daten löschen',
		];

		if ( isset( $map[ $screen->id ] ) ) {
			return $map[ $screen->id ];
		}

		if ( 'edit' === $screen->base ) {
			return sprintf( 'Inhaltsliste (%s)', $screen->post_type ?: 'post' );
		}

		if ( 'post' === $screen->base ) {
			return sprintf( 'Editor (%s bearbeiten)', $screen->post_type ?: 'post' );
		}

		if ( 'edit-tags' === $screen->base ) {
			return sprintf( 'Taxonomie (%s)', $screen->taxonomy ?: 'category' );
		}

		// Clean up custom plugin pages
		$clean_id = str_replace( [ 'toplevel_page_', 'settings_page_', 'tools_page_' ], '', $screen->id );
		return ucwords( str_replace( [ '-', '_' ], ' ', $clean_id ) );
	}

	/**
	 * Inter-Plugin Bridge: receive chained audit events from WM Suite Hub or third-party spokes.
	 *
	 * @param mixed $event Associative event payload.
	 */
	public function on_hub_audit_event( mixed $event ): void {
		if ( ! is_array( $event ) ) {
			return;
		}

		$module_slug = sanitize_key( (string) ( $event['module_slug'] ?? '' ) );
		$hub_event   = mb_substr( sanitize_text_field( (string) ( $event['event_type'] ?? '' ) ), 0, 100 );

		if ( 'wm-activity-sentinel' === $module_slug || ( '' === $module_slug && '' === $hub_event ) ) {
			return;
		}

		$severity = sanitize_key( (string) ( $event['severity'] ?? 'notice' ) );
		if ( ! in_array( $severity, [ 'info', 'notice', 'warning', 'critical' ], true ) ) {
			$severity = 'notice';
		}

		$context = [
			'module_slug'    => '' !== $module_slug ? $module_slug : 'external',
			'hub_event_type' => $hub_event,
		];

		if ( isset( $event['current_hash'] ) && is_scalar( $event['current_hash'] ) && '' !== (string) $event['current_hash'] ) {
			$context['hub_hash'] = mb_substr( sanitize_text_field( (string) $event['current_hash'] ), 0, 128 );
		}

		if ( isset( $event['timestamp'] ) && is_scalar( $event['timestamp'] ) && '' !== (string) $event['timestamp'] ) {
			$context['hub_timestamp'] = mb_substr( sanitize_text_field( (string) $event['timestamp'] ), 0, 64 );
		}

		$user_id = get_current_user_id();
		WM_Sentinel_Logger::log(
			$user_id > 0 ? $user_id : null,
			'hub_audit_bridged',
			'hub',
			null,
			$severity,
			$context
		);
	}

	/**
	 * Inter-Plugin Bridge: receive security incident reports (e.g. RequestShield blocks).
	 *
	 * Never persists raw client IP inside context JSON; extracts any supplied IP strictly
	 * for daily-salted HMAC computation in WM_Sentinel_Logger::log().
	 *
	 * @param mixed $incident Associative incident payload.
	 */
	public function on_security_incident( mixed $incident ): void {
		if ( ! is_array( $incident ) ) {
			return;
		}

		$type   = mb_substr( sanitize_text_field( (string) ( $incident['type'] ?? '' ) ), 0, 100 );
		$reason = mb_substr( sanitize_text_field( (string) ( $incident['reason'] ?? '' ) ), 0, 300 );

		if ( '' === $type && '' === $reason ) {
			return;
		}

		$severity = sanitize_key( (string) ( $incident['severity'] ?? 'warning' ) );
		if ( ! in_array( $severity, [ 'warning', 'critical' ], true ) ) {
			$severity = 'warning';
		}

		$raw_ip = isset( $incident['ip'] ) && is_scalar( $incident['ip'] )
			? sanitize_text_field( (string) $incident['ip'] )
			: '';

		$context = [
			'incident_type' => '' !== $type ? $type : 'security_incident',
			'reason'        => $reason,
		];

		if ( isset( $incident['parameter'] ) && is_scalar( $incident['parameter'] ) && '' !== (string) $incident['parameter'] ) {
			$context['parameter'] = mb_substr( sanitize_text_field( (string) $incident['parameter'] ), 0, 200 );
		}

		if ( isset( $incident['source'] ) && is_scalar( $incident['source'] ) && '' !== (string) $incident['source'] ) {
			$context['source'] = sanitize_key( (string) $incident['source'] );
		}

		$user_id = get_current_user_id();
		WM_Sentinel_Logger::log(
			$user_id > 0 ? $user_id : null,
			'security_incident_reported',
			'security',
			null,
			$severity,
			$context,
			'' !== $raw_ip ? $raw_ip : null
		);
	}
}

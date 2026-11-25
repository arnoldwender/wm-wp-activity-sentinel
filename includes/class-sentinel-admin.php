<?php
/**
 * WM Suite Activity Sentinel — Enterprise Admin Cockpit & Event Stream
 *
 * @package WM_Activity_Sentinel
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Sentinel_Admin {

	/**
	 * Register all admin hooks
	 */
	public function init_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_init', [ $this, 'handle_form_submissions' ] );
		add_action( 'admin_init', [ $this, 'handle_export_requests' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
		add_action( 'current_screen', [ $this, 'add_contextual_help' ] );
	}

	/**
	 * Enqueue admin styling and scripts
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		// Standalone the screens are toplevel_page_wm-sentinel-stream and wm-sentinel_page_*; under
		// the hub the page is dispatched as wm-suite_page_wm-activity-sentinel.
		$is_sentinel_page = str_contains( $hook_suffix, 'wm-sentinel' ) || str_contains( $hook_suffix, 'wm-activity-sentinel' );
		$is_dashboard     = ( 'index.php' === $hook_suffix && (bool) get_option( 'wm_sentinel_enable_dashboard_widget', 1 ) );

		if ( $is_sentinel_page || $is_dashboard ) {
			// Family sheets (canonical in the hub, byte-identical copies here): every WM plugin
			// registers the same two handles, so WordPress loads one copy however many plugins are
			// active. Versioned by file time: a sheet updated under an unchanged plugin version was
			// served from the browser cache (measured 2026-09-14). The Sentinel layer comes last.
			$root = dirname( __DIR__ ) . '/assets/css/';
			wp_enqueue_style(
				'wm-admin-tokens',
				WM_SENTINEL_URL . 'assets/css/wm-admin-tokens.css',
				[],
				(string) ( @filemtime( $root . 'wm-admin-tokens.css' ) ?: WM_SENTINEL_VERSION )
			);
			wp_enqueue_style(
				'wm-admin-ui',
				WM_SENTINEL_URL . 'assets/css/wm-admin-ui.css',
				[ 'wm-admin-tokens' ],
				(string) ( @filemtime( $root . 'wm-admin-ui.css' ) ?: WM_SENTINEL_VERSION )
			);
			wp_enqueue_style(
				'wm-sentinel-admin',
				WM_SENTINEL_URL . 'assets/css/sentinel-admin.css',
				[ 'wm-admin-ui', 'dashicons' ],
				(string) ( @filemtime( $root . 'sentinel-admin.css' ) ?: WM_SENTINEL_VERSION )
			);
		}

		// Scripts are versioned by file time for the same reason as the sheets: the modal focus fix
		// of 2026-09-14 was invisible in the browser while the file kept the plugin version.
		$js_root = dirname( __DIR__ ) . '/assets/js/';
		if ( $is_sentinel_page ) {
			wp_enqueue_script(
				'wm-sentinel-admin',
				WM_SENTINEL_URL . 'assets/js/sentinel-admin.js',
				[],
				(string) ( @filemtime( $js_root . 'sentinel-admin.js' ) ?: WM_SENTINEL_VERSION ),
				true
			);
		}

		// Enqueue client-side interaction tracker if enabled
		if ( (bool) get_option( 'wm_sentinel_monitor_ui_interactions', 1 ) ) {
			wp_enqueue_script(
				'wm-sentinel-tracker',
				WM_SENTINEL_URL . 'assets/js/sentinel-tracker.js',
				[],
				(string) ( @filemtime( $js_root . 'sentinel-tracker.js' ) ?: WM_SENTINEL_VERSION ),
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
	}

	/**
	 * Family menu icon: a monochrome hub-and-spoke mark, filled shapes only so the WordPress
	 * svg-painter can recolour it with the admin colour scheme. Placeholder until the family
	 * icon is decided (hackaton plan D12).
	 */
	public static function family_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><circle cx="10" cy="10" r="2.6" fill="black"/><circle cx="10" cy="3" r="1.8" fill="black"/><circle cx="16.1" cy="13.5" r="1.8" fill="black"/><circle cx="3.9" cy="13.5" r="1.8" fill="black"/><rect x="9.3" y="4.5" width="1.4" height="3.4" fill="black"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(-60 10 10)"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(60 10 10)"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Family header shared by the five screens: eyebrow badge with a dashicon, title, subtitle,
	 * and the WM Suite badge carrying the hub/standalone state. Strings arrive translated.
	 */
	private static function render_page_header( string $icon, string $eyebrow, string $title, string $subtitle ): void {
		?>
		<header class="wm-admin-header wm-sentinel-admin-header">
			<div class="wm-header-title">
				<span class="wm-badge wm-badge-cyan wm-sentinel-header-badge">
					<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $eyebrow ); ?>
				</span>
				<h1><?php echo esc_html( $title ); ?></h1>
				<p class="wm-subtitle"><?php echo esc_html( $subtitle ); ?></p>
			</div>
			<div class="wm-header-badges">
				<span class="wm-badge-family" title="<?php esc_attr_e( 'Part of the WM Suite plugin family', 'wm-activity-sentinel' ); ?>">
					<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="4" r="2"/><circle cx="19" cy="16" r="2"/><circle cx="5" cy="16" r="2"/><path d="M12 7v2M13.9 13.2l3.4 2M10.1 13.2l-3.4 2"/></svg>
					<?php esc_html_e( 'WM Suite', 'wm-activity-sentinel' ); ?>
					<span class="wm-badge-family-state"><?php echo defined( 'WM_SUITE_HUB_VERSION' ) ? esc_html__( 'Hub', 'wm-activity-sentinel' ) : esc_html__( 'Standalone', 'wm-activity-sentinel' ); ?></span>
				</span>
			</div>
		</header>
		<?php
	}

	/**
	 * Register Admin Menus
	 */
	public function register_admin_menu(): void {
		$kpis = WM_Sentinel_Database::get_kpis();
		$alert_badge = $kpis['critical_24h'] > 0
			? ' <span class="update-plugins count-' . esc_attr( (string) $kpis['critical_24h'] ) . '"><span class="plugin-count">' . esc_html( (string) $kpis['critical_24h'] ) . '</span></span>'
			: '';

		add_menu_page(
			__( 'WM Sentinel', 'wm-activity-sentinel' ),
			__( 'WM Sentinel', 'wm-activity-sentinel' ) . $alert_badge,
			'manage_options',
			'wm-sentinel-stream',
			[ $this, 'render_stream_page' ],
			self::family_menu_icon(),
			27
		);

		add_submenu_page(
			'wm-sentinel-stream',
			__( 'Aktivitäts-Stream', 'wm-activity-sentinel' ),
			__( 'Aktivitäts-Stream', 'wm-activity-sentinel' ) . $alert_badge,
			'manage_options',
			'wm-sentinel-stream',
			[ $this, 'render_stream_page' ]
		);

		add_submenu_page(
			'wm-sentinel-stream',
			__( 'Integritäts-Audit (Hash-Kette)', 'wm-activity-sentinel' ),
			__( 'Integritäts-Audit', 'wm-activity-sentinel' ),
			'manage_options',
			'wm-sentinel-integrity',
			[ $this, 'render_integrity_page' ]
		);

		add_submenu_page(
			'wm-sentinel-stream',
			__( 'Dateiintegrität (Kern & Plugins)', 'wm-activity-sentinel' ),
			__( 'Dateiintegrität', 'wm-activity-sentinel' ),
			'manage_options',
			'wm-sentinel-fim',
			[ $this, 'render_fim_page' ]
		);

		add_submenu_page(
			'wm-sentinel-stream',
			__( 'Export', 'wm-activity-sentinel' ),
			__( 'Export', 'wm-activity-sentinel' ),
			'manage_options',
			'wm-sentinel-export',
			[ $this, 'render_export_page' ]
		);

		add_submenu_page(
			'wm-sentinel-stream',
			__( 'Einstellungen', 'wm-activity-sentinel' ),
			__( 'Einstellungen', 'wm-activity-sentinel' ),
			'manage_options',
			'wm-sentinel-settings',
			[ $this, 'render_settings_page' ]
		);

		add_submenu_page(
			'wm-sentinel-stream',
			__( 'Handbuch & Dokumentation', 'wm-activity-sentinel' ),
			__( 'Handbuch', 'wm-activity-sentinel' ),
			'manage_options',
			'wm-sentinel-docs',
			[ $this, 'render_docs_page' ]
		);
	}

	/**
	 * Register WordPress Dashboard Widget
	 */
	public function register_dashboard_widget(): void {
		if ( ! (bool) get_option( 'wm_sentinel_enable_dashboard_widget', 1 ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
			require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		}

		wp_add_dashboard_widget(
			'wm_sentinel_dashboard_widget',
			__( 'WM Activity Sentinel', 'wm-activity-sentinel' ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	/**
	 * Render WordPress Dashboard Widget
	 */
	public function render_dashboard_widget(): void {
		$kpis = WM_Sentinel_Database::get_kpis();
		$integrity = WM_Sentinel_Integrity::verify_chain();
		$recent_logs = WM_Sentinel_Database::get_logs( [ 'limit' => 5 ] );

		?>
		<div class="wm-dash-widget-box wm-sentinel-dashboard-widget">
			<!-- Header Integrity Banner (family .wm-dash-banner; is-danger is the Sentinel layer) -->
			<div class="wm-dash-banner wm-sentinel-widget-banner <?php echo $integrity['valid'] ? 'is-valid' : 'is-danger'; ?>">
				<span class="dashicons <?php echo $integrity['valid'] ? 'dashicons-shield-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
				<div class="wm-sentinel-widget-status-text">
					<strong>
						<?php
						if ( $integrity['valid'] ) {
							// Until 1.4.2: «Kryptografische Kette intakt (%d Blöcke)»; the rows are entries, not blocks.
							/* translators: %d: number of verified chain entries */
							$intact_text = __( 'Kette intakt: %d Einträge geprüft', 'wm-activity-sentinel' );
							echo esc_html( sprintf( $intact_text, (int) $integrity['total_verified'] ) );
						} else {
							esc_html_e( 'Kettenprüfung fehlgeschlagen', 'wm-activity-sentinel' );
						}
						?>
					</strong>
					<small><?php esc_html_e( 'Jeder Eintrag ist mit HMAC-SHA256 an den vorherigen gebunden.', 'wm-activity-sentinel' ); ?></small>
				</div>
			</div>

			<!-- Mini KPI Grid -->
			<div class="wm-dash-kpi-grid wm-sentinel-widget-kpi-row">
				<div class="wm-dash-kpi-card wm-sentinel-widget-kpi-item">
					<span class="wm-dash-kpi-val wm-widget-kpi-num"><?php echo esc_html( (string) $kpis['total_logs'] ); ?></span>
					<?php // Until 1.4.2 «Gesamt Logs»: the stream page and the chain line above count «Einträge». ?>
					<span class="wm-dash-kpi-lbl wm-widget-kpi-lbl"><?php esc_html_e( 'Einträge', 'wm-activity-sentinel' ); ?></span>
				</div>
				<div class="wm-dash-kpi-card wm-sentinel-widget-kpi-item <?php echo esc_attr( $kpis['critical_24h'] > 0 ? 'wm-kpi-has-critical' : '' ); ?>">
					<span class="wm-dash-kpi-val wm-widget-kpi-num"><?php echo esc_html( (string) $kpis['critical_24h'] ); ?></span>
					<span class="wm-dash-kpi-lbl wm-widget-kpi-lbl"><?php esc_html_e( 'Kritisch (24h)', 'wm-activity-sentinel' ); ?></span>
				</div>
				<div class="wm-dash-kpi-card wm-sentinel-widget-kpi-item <?php echo esc_attr( $kpis['warning_24h'] > 0 ? 'wm-kpi-has-warning' : '' ); ?>">
					<span class="wm-dash-kpi-val wm-widget-kpi-num"><?php echo esc_html( (string) $kpis['warning_24h'] ); ?></span>
					<span class="wm-dash-kpi-lbl wm-widget-kpi-lbl"><?php esc_html_e( 'Warnungen (24h)', 'wm-activity-sentinel' ); ?></span>
				</div>
			</div>

			<!-- Recent Activity List -->
			<h4 class="wm-sentinel-widget-subhead"><?php esc_html_e( 'Letzte Systemereignisse', 'wm-activity-sentinel' ); ?></h4>
			<ul class="wm-sentinel-widget-list">
				<?php if ( ! empty( $recent_logs ) ) : ?>
					<?php foreach ( $recent_logs as $log ) : ?>
						<li class="wm-sentinel-widget-list-item">
							<span class="wm-sentinel-severity-badge wm-sev-<?php echo esc_attr( $log->severity ); ?>">
								<?php echo esc_html( strtoupper( substr( $log->severity, 0, 4 ) ) ); ?>
							</span>
							<div class="wm-sentinel-widget-item-main">
								<code><?php echo esc_html( $log->event_type ); ?></code>
								<span class="wm-sentinel-widget-item-meta">
									<?php echo esc_html( $log->user_name ); ?> &bull; <?php echo esc_html( $log->created_at ); ?>
								</span>
							</div>
						</li>
					<?php endforeach; ?>
				<?php else : ?>
					<li class="wm-sentinel-widget-list-item"><?php esc_html_e( 'Noch keine Ereignisse erfasst.', 'wm-activity-sentinel' ); ?></li>
				<?php endif; ?>
			</ul>

			<!-- Action Buttons -->
			<div class="wm-dash-actions wm-sentinel-widget-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wm-sentinel-stream' ) ); ?>" class="button button-primary button-small">
					<span class="dashicons dashicons-visibility" aria-hidden="true"></span> <?php esc_html_e( 'Aktivitäts-Stream öffnen', 'wm-activity-sentinel' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wm-sentinel-integrity' ) ); ?>" class="button button-secondary button-small">
					<span class="dashicons dashicons-shield" aria-hidden="true"></span> <?php esc_html_e( 'Integritäts-Audit', 'wm-activity-sentinel' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle Admin Form Submissions
	 */
	public function handle_form_submissions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['wm_sentinel_save_settings'] ) ) {
			check_admin_referer( 'wm_sentinel_settings_nonce' );

			$logging_intensity = sanitize_text_field( wp_unslash( $_POST['wm_sentinel_logging_intensity'] ?? 'all' ) );
			if ( ! in_array( $logging_intensity, [ 'all', 'standard', 'strict', 'critical_only' ], true ) ) {
				$logging_intensity = 'all';
			}
			update_option( 'wm_sentinel_logging_intensity', $logging_intensity, true );

			$telemetry_intensity = sanitize_text_field( wp_unslash( $_POST['wm_sentinel_telemetry_intensity'] ?? 'all' ) );
			if ( ! in_array( $telemetry_intensity, [ 'all', 'actions_only', 'critical_only' ], true ) ) {
				$telemetry_intensity = 'all';
			}
			update_option( 'wm_sentinel_telemetry_intensity', $telemetry_intensity, true );

			update_option( 'wm_sentinel_log_auth', isset( $_POST['wm_sentinel_log_auth'] ) ? 1 : 0, false );
			update_option( 'wm_sentinel_log_users', isset( $_POST['wm_sentinel_log_users'] ) ? 1 : 0, false );
			update_option( 'wm_sentinel_log_plugins', isset( $_POST['wm_sentinel_log_plugins'] ) ? 1 : 0, false );
			update_option( 'wm_sentinel_log_content', isset( $_POST['wm_sentinel_log_content'] ) ? 1 : 0, false );
			update_option( 'wm_sentinel_log_rest', isset( $_POST['wm_sentinel_log_rest'] ) ? 1 : 0, false );
			update_option( 'wm_sentinel_monitor_screens', isset( $_POST['wm_sentinel_monitor_screens'] ) ? 1 : 0, false );
			update_option( 'wm_sentinel_monitor_ui_interactions', isset( $_POST['wm_sentinel_monitor_ui_interactions'] ) ? 1 : 0, true );
			update_option( 'wm_sentinel_monitor_frontend_views', isset( $_POST['wm_sentinel_monitor_frontend_views'] ) ? 1 : 0, true );

			update_option( 'wm_sentinel_alert_email', sanitize_email( wp_unslash( $_POST['wm_sentinel_alert_email'] ?? '' ) ), false );
			$alert_min = sanitize_key( wp_unslash( (string) ( $_POST['wm_sentinel_alert_min_severity'] ?? 'critical' ) ) );
			update_option( 'wm_sentinel_alert_min_severity', in_array( $alert_min, [ 'critical', 'warning', 'notice' ], true ) ? $alert_min : 'critical', false );
			update_option( 'wm_sentinel_webhook_url', esc_url_raw( wp_unslash( $_POST['wm_sentinel_webhook_url'] ?? '' ) ), false );
			update_option( 'wm_sentinel_retention_days', absint( $_POST['wm_sentinel_retention_days'] ?? 90 ), false );
			// The schedule follows the setting, like the monitor's: a window nobody applies is not
			// a retention policy, and a window set to "keep everything" must not leave a daily
			// event running against a threshold that never matches.
			WM_Sentinel_Retention::sync_schedule();
			update_option( 'wm_sentinel_enable_dashboard_widget', isset( $_POST['wm_sentinel_enable_dashboard_widget'] ) ? 1 : 0, false );
			update_option( 'wm_sentinel_delete_data_on_uninstall', isset( $_POST['wm_sentinel_delete_data_on_uninstall'] ) ? 1 : 0, false );

			// SIEM forwarding. Until 2026-09-16 these seven settings existed and were read by the
			// forwarder, but no screen wrote them: the feature was only reachable through
			// `wp option update`, which is not a feature the operator has.
			update_option( 'wm_sentinel_siem_enabled', isset( $_POST['wm_sentinel_siem_enabled'] ) ? 1 : 0, false );

			$siem_format = sanitize_key( wp_unslash( (string) ( $_POST['wm_sentinel_siem_format'] ?? 'cef' ) ) );
			update_option( 'wm_sentinel_siem_format', in_array( $siem_format, WM_Sentinel_Forwarder::FORMATS, true ) ? $siem_format : 'cef', false );

			$siem_transport = sanitize_key( wp_unslash( (string) ( $_POST['wm_sentinel_siem_transport'] ?? 'https' ) ) );
			update_option( 'wm_sentinel_siem_transport', in_array( $siem_transport, WM_Sentinel_Forwarder::TRANSPORTS, true ) ? $siem_transport : 'https', false );

			update_option( 'wm_sentinel_siem_host', sanitize_text_field( wp_unslash( $_POST['wm_sentinel_siem_host'] ?? '' ) ), false );
			update_option( 'wm_sentinel_siem_port', min( 65535, max( 1, absint( $_POST['wm_sentinel_siem_port'] ?? 514 ) ) ), false );
			update_option( 'wm_sentinel_siem_url', esc_url_raw( wp_unslash( $_POST['wm_sentinel_siem_url'] ?? '' ) ), false );

			$siem_min = sanitize_key( wp_unslash( (string) ( $_POST['wm_sentinel_siem_min_severity'] ?? 'warning' ) ) );
			update_option( 'wm_sentinel_siem_min_severity', in_array( $siem_min, [ 'info', 'notice', 'warning', 'critical' ], true ) ? $siem_min : 'warning', false );

			// File integrity monitor. The schedule follows the switch, or "off" would leave a daily
			// event running and "on" would leave a monitor that never runs.
			update_option( 'wm_sentinel_fim_enabled', isset( $_POST['wm_sentinel_fim_enabled'] ) ? 1 : 0, false );
			update_option( 'wm_sentinel_fim_scan_suite', isset( $_POST['wm_sentinel_fim_scan_suite'] ) ? 1 : 0, false );
			WM_Sentinel_FIM::sync_schedule();

			wp_safe_redirect( add_query_arg( [ 'page' => 'wm-sentinel-settings', 'updated' => '1' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		// Run the file integrity scan now.
		if ( isset( $_POST['wm_sentinel_run_fim'] ) && check_admin_referer( 'wm_sentinel_fim_action', 'wm_sentinel_fim_nonce' ) ) {
			$report = WM_Sentinel_FIM::run( 'manual' );
			wp_safe_redirect( add_query_arg( [ 'page' => 'wm-sentinel-fim', 'fim_findings' => (int) $report['findings'] ], admin_url( 'admin.php' ) ) );
			exit;
		}

		// Capture and seal the WM Suite baseline.
		if ( isset( $_POST['wm_sentinel_seal_baseline'] ) && check_admin_referer( 'wm_sentinel_fim_action', 'wm_sentinel_fim_nonce' ) ) {
			$sealed = WM_Sentinel_FIM::seal_suite_baseline( WP_PLUGIN_DIR . '/' );
			$arg    = null !== $sealed['error']
				? [ 'page' => 'wm-sentinel-fim', 'fim_refused' => (int) $sealed['files'] ]
				: [ 'page' => 'wm-sentinel-fim', 'fim_sealed' => (int) $sealed['files'] ];
			wp_safe_redirect( add_query_arg( $arg, admin_url( 'admin.php' ) ) );
			exit;
		}

		// Handle Seed Demo Data
		if ( isset( $_POST['wm_sentinel_seed_demo'] ) && check_admin_referer( 'wm_sentinel_demo_action', 'wm_sentinel_demo_nonce' ) ) {
			$seeded = WM_Sentinel_Seeder::seed_demo_data( 15 );
			wp_safe_redirect( add_query_arg( [ 'page' => 'wm-sentinel-settings', 'demo_seeded' => $seeded ], admin_url( 'admin.php' ) ) );
			exit;
		}

		// Handle Delete Demo Data
		if ( isset( $_POST['wm_sentinel_delete_demo'] ) && check_admin_referer( 'wm_sentinel_demo_action', 'wm_sentinel_demo_nonce' ) ) {
			$deleted = WM_Sentinel_Seeder::delete_demo_data();
			$arg     = -1 === $deleted
				? [ 'page' => 'wm-sentinel-settings', 'demo_refused' => '1' ]
				: [ 'page' => 'wm-sentinel-settings', 'demo_deleted' => $deleted ];
			wp_safe_redirect( add_query_arg( $arg, admin_url( 'admin.php' ) ) );
			exit;
		}

		// Handle Factory Reset (Auf Werkseinstellungen zurücksetzen)
		if ( isset( $_POST['wm_sentinel_factory_reset'] ) && check_admin_referer( 'wm_sentinel_reset_action', 'wm_sentinel_reset_nonce' ) ) {
			WM_Sentinel_Seeder::reset_to_factory_defaults();
			wp_safe_redirect( add_query_arg( [ 'page' => 'wm-sentinel-settings', 'factory_reset' => '1' ], admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	/**
	 * Handle Exports
	 */
	public function handle_export_requests(): void {
		if ( isset( $_GET['action'] ) && 'wm_sentinel_export_csv' === $_GET['action'] ) {
			WM_Sentinel_Exporter::export_csv();
		}

		if ( isset( $_GET['action'] ) && 'wm_sentinel_export_json' === $_GET['action'] ) {
			WM_Sentinel_Exporter::export_json();
		}
	}

	/**
	 * Render Unified Navigation Tabs
	 */
	public static function render_nav_tabs( string $current_page ): void {
		// The icon of each tab is a dashicon rendered next to the label (no emoji in the strings).
		$tabs = [
			'wm-sentinel-stream'    => [ 'label' => __( 'Aktivitäts-Stream', 'wm-activity-sentinel' ), 'icon' => 'dashicons-visibility' ],
			'wm-sentinel-integrity' => [ 'label' => __( 'Integritäts-Audit (Hash-Kette)', 'wm-activity-sentinel' ), 'icon' => 'dashicons-shield-alt' ],
			'wm-sentinel-fim'       => [ 'label' => __( 'Dateiintegrität', 'wm-activity-sentinel' ), 'icon' => 'dashicons-media-code' ],
			'wm-sentinel-export'    => [ 'label' => __( 'Export', 'wm-activity-sentinel' ), 'icon' => 'dashicons-download' ],
			'wm-sentinel-settings'  => [ 'label' => __( 'Einstellungen & Alarme', 'wm-activity-sentinel' ), 'icon' => 'dashicons-admin-generic' ],
			'wm-sentinel-docs'      => [ 'label' => __( 'Handbuch', 'wm-activity-sentinel' ), 'icon' => 'dashicons-book' ],
		];
		?>
		<nav class="wm-nav-tabs" aria-label="<?php esc_attr_e( 'Bereiche von WM Activity Sentinel', 'wm-activity-sentinel' ); ?>">
			<?php foreach ( $tabs as $page => $data ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page ) ); ?>"
				   class="wm-tab-link <?php echo $current_page === $page ? 'active' : ''; ?>"<?php echo $current_page === $page ? ' aria-current="page"' : ''; ?>>
					<span class="dashicons <?php echo esc_attr( $data['icon'] ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $data['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render Live Activity Stream Page
	 */
	public function render_stream_page(): void {
		$kpis = WM_Sentinel_Database::get_kpis();
		$severity = sanitize_text_field( wp_unslash( $_GET['severity'] ?? '' ) );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 25;
		$offset = ( $paged - 1 ) * $per_page;

		$total_items = WM_Sentinel_Database::get_logs_count( [ 'severity' => $severity, 'search' => $search ] );
		$logs = WM_Sentinel_Database::get_logs( [
			'severity' => $severity,
			'search'   => $search,
			'limit'    => $per_page,
			'offset'   => $offset,
		] );

		$total_pages = ceil( $total_items / $per_page );

		?>
		<div class="wrap wm-admin wm-sentinel-admin-wrap">
			<?php
			self::render_page_header(
				'dashicons-visibility',
				__( 'Aktivitätsprotokoll', 'wm-activity-sentinel' ),
				__( 'WM Activity Sentinel — Aktivitäts-Stream', 'wm-activity-sentinel' ),
				__( 'Protokollierte Ereignisse, neueste zuerst. Jeder Eintrag ist über eine HMAC-SHA256-Kette mit seinem Vorgänger verbunden.', 'wm-activity-sentinel' )
			);
			?>

			<?php self::render_nav_tabs( 'wm-sentinel-stream' ); ?>

			<!-- Quick Jump Navigation -->
			<nav class="wm-jump-nav" aria-label="<?php esc_attr_e( 'Auf dieser Seite', 'wm-activity-sentinel' ); ?>">
				<span class="wm-jump-nav-label"><?php esc_html_e( 'Springen zu:', 'wm-activity-sentinel' ); ?></span>
				<a href="#wm-sec-guide" class="wm-jump-link"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span><?php esc_html_e( 'Kurzanleitung', 'wm-activity-sentinel' ); ?></a>
				<a href="#wm-sec-kpis" class="wm-jump-link"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span><?php esc_html_e( 'Kennzahlen 24 h', 'wm-activity-sentinel' ); ?></a>
				<a href="#wm-sec-filters" class="wm-jump-link"><span class="dashicons dashicons-search" aria-hidden="true"></span><?php esc_html_e( 'Filter und Suche', 'wm-activity-sentinel' ); ?></a>
				<a href="#wm-sec-logs" class="wm-jump-link"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><?php esc_html_e( 'Einträge', 'wm-activity-sentinel' ); ?></a>
			</nav>

			<!-- Quick Plain Language Guide -->
			<section id="wm-sec-guide" class="wm-quick-guide">
				<div class="wm-quick-guide-title">
					<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Was dieses Protokoll leistet', 'wm-activity-sentinel' ); ?></span>
				</div>
				<p class="wm-quick-guide-desc">
					<?php esc_html_e( 'WM Activity Sentinel schreibt ausgewählte Ereignisse (Anmeldungen, Benutzer und Rollen, Plugins und Themes, Exporte, Dateieditor, ausgewählte REST-Routen, Seitenaufrufe und Klicks angemeldeter Benutzer) in eine Tabelle, deren Einträge über eine HMAC-SHA256-Kette verbunden sind. Wer Einträge ändert, löscht oder einschleust, fällt bei der Prüfung auf, solange er den Schlüssel aus der wp-config.php nicht kennt. Verhindern kann das Protokoll solche Eingriffe nicht.', 'wm-activity-sentinel' ); ?>
				</p>
				<div class="wm-quick-guide-actions">
					<strong><?php esc_html_e( 'Schnellzugriff:', 'wm-activity-sentinel' ); ?></strong>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wm-sentinel-integrity' ) ); ?>" class="wm-preset-pill-btn">
						<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
						<?php esc_html_e( 'Kette prüfen', 'wm-activity-sentinel' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wm-sentinel-export' ) ); ?>" class="wm-preset-pill-btn">
						<span class="dashicons dashicons-media-document" aria-hidden="true"></span>
						<?php esc_html_e( 'Protokoll exportieren', 'wm-activity-sentinel' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wm-sentinel-settings' ) ); ?>" class="wm-preset-pill-btn">
						<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
						<?php esc_html_e( 'Protokollierung einstellen', 'wm-activity-sentinel' ); ?>
					</a>
				</div>
			</section>

			<!-- KPI Cards Grid -->
			<div id="wm-sec-kpis" class="wm-kpi-grid wm-sentinel-kpi-grid">
				<div class="wm-kpi-card wm-sentinel-kpi-card">
					<div class="wm-sentinel-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-database" aria-hidden="true"></span></div>
					<div class="wm-sentinel-kpi-data">
						<span class="wm-kpi-lbl wm-sentinel-kpi-label"><?php esc_html_e( 'Gesamt-Einträge', 'wm-activity-sentinel' ); ?></span>
						<span class="wm-kpi-val wm-sentinel-kpi-value"><?php echo esc_html( number_format_i18n( $kpis['total_logs'] ) ); ?></span>
					</div>
				</div>

				<div class="wm-kpi-card wm-sentinel-kpi-card <?php echo esc_attr( $kpis['critical_24h'] > 0 ? 'wm-sentinel-kpi-critical' : '' ); ?>">
					<div class="wm-sentinel-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-warning" aria-hidden="true"></span></div>
					<div class="wm-sentinel-kpi-data">
						<span class="wm-kpi-lbl wm-sentinel-kpi-label"><?php esc_html_e( 'Kritische Alarme (24h)', 'wm-activity-sentinel' ); ?></span>
						<span class="wm-kpi-val wm-sentinel-kpi-value"><?php echo esc_html( (string) $kpis['critical_24h'] ); ?></span>
					</div>
				</div>

				<div class="wm-kpi-card wm-sentinel-kpi-card <?php echo esc_attr( $kpis['warning_24h'] > 0 ? 'wm-sentinel-kpi-warning' : '' ); ?>">
					<div class="wm-sentinel-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-info" aria-hidden="true"></span></div>
					<div class="wm-sentinel-kpi-data">
						<span class="wm-kpi-lbl wm-sentinel-kpi-label"><?php esc_html_e( 'Warnungen (24h)', 'wm-activity-sentinel' ); ?></span>
						<span class="wm-kpi-val wm-sentinel-kpi-value"><?php echo esc_html( (string) $kpis['warning_24h'] ); ?></span>
					</div>
				</div>

				<div class="wm-kpi-card wm-sentinel-kpi-card">
					<div class="wm-sentinel-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span></div>
					<div class="wm-sentinel-kpi-data">
						<span class="wm-kpi-lbl wm-sentinel-kpi-label"><?php esc_html_e( 'Aktive Benutzer', 'wm-activity-sentinel' ); ?></span>
						<span class="wm-kpi-val wm-sentinel-kpi-value"><?php echo esc_html( (string) $kpis['distinct_users'] ); ?></span>
					</div>
				</div>
			</div>

			<!-- Filter Bar -->
			<form method="get" id="wm-sec-filters" class="wm-sentinel-filter-bar">
				<input type="hidden" name="page" value="wm-sentinel-stream" />
				
				<select name="severity">
					<option value=""><?php esc_html_e( 'Alle Schweregrade', 'wm-activity-sentinel' ); ?></option>
					<option value="critical" <?php selected( $severity, 'critical' ); ?>><?php esc_html_e( 'Kritisch (Critical)', 'wm-activity-sentinel' ); ?></option>
					<option value="warning" <?php selected( $severity, 'warning' ); ?>><?php esc_html_e( 'Warnung (Warning)', 'wm-activity-sentinel' ); ?></option>
					<option value="notice" <?php selected( $severity, 'notice' ); ?>><?php esc_html_e( 'Hinweis (Notice)', 'wm-activity-sentinel' ); ?></option>
					<option value="info" <?php selected( $severity, 'info' ); ?>><?php esc_html_e( 'Info', 'wm-activity-sentinel' ); ?></option>
				</select>

				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Ereignis, Benutzer oder Kontext durchsuchen...', 'wm-activity-sentinel' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Filtern', 'wm-activity-sentinel' ); ?></button>
				
				<?php if ( ! empty( $search ) || ! empty( $severity ) ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wm-sentinel-stream' ) ); ?>" class="button button-link"><?php esc_html_e( 'Zurücksetzen', 'wm-activity-sentinel' ); ?></a>
				<?php endif; ?>
			</form>

			<!-- Activity Stream Table -->
			<table id="wm-sec-logs" class="wp-list-table widefat fixed striped wm-sentinel-table">
				<thead>
					<tr>
						<th scope="col" class="wm-sentinel-col-id"><?php esc_html_e( 'Log-ID', 'wm-activity-sentinel' ); ?></th>
						<th scope="col" class="wm-sentinel-col-time"><?php esc_html_e( 'Zeitpunkt (UTC)', 'wm-activity-sentinel' ); ?></th>
						<th scope="col" class="wm-sentinel-col-severity"><?php esc_html_e( 'Schweregrad', 'wm-activity-sentinel' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Ereignis', 'wm-activity-sentinel' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Objekt', 'wm-activity-sentinel' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Akteur / Benutzer', 'wm-activity-sentinel' ); ?></th>
						<th scope="col"><?php esc_html_e( 'IP-Hash', 'wm-activity-sentinel' ); ?></th>
						<th scope="col" class="wm-sentinel-col-context"><?php esc_html_e( 'Kontext', 'wm-activity-sentinel' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $logs ) ) : ?>
						<?php foreach ( $logs as $l ) : ?>
							<tr>
								<td>#<?php echo esc_html( (string) $l->log_id ); ?></td>
								<td><?php echo esc_html( $l->created_at ); ?></td>
								<td>
									<span class="wm-sentinel-severity-badge wm-sev-<?php echo esc_attr( $l->severity ); ?>">
										<?php echo esc_html( strtoupper( $l->severity ) ); ?>
									</span>
								</td>
								<td><strong><code><?php echo esc_html( $l->event_type ); ?></code></strong></td>
								<td><span class="wm-sentinel-object-tag"><?php echo esc_html( $l->object_type ); ?></span></td>
								<td><?php echo esc_html( $l->user_name ); ?></td>
								<td><code><?php echo esc_html( substr( $l->ip_hmac, 0, 16 ) ); ?>...</code></td>
								<td class="wm-sentinel-cell-center">
									<button type="button" class="button button-small wm-sentinel-view-context" data-context="<?php echo esc_attr( $l->context ?? '{}' ); ?>" data-log-id="<?php echo esc_attr( (string) $l->log_id ); ?>" data-event="<?php echo esc_attr( $l->event_type ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: log entry id */ __( 'Kontext von Eintrag #%d anzeigen', 'wm-activity-sentinel' ), (int) $l->log_id ) ); ?>">
										<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="8"><?php esc_html_e( 'Keine Aktivitäten gefunden.', 'wm-activity-sentinel' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							/* translators: %d: total number of log entries */
							$entries_text = __( '%d Einträge', 'wm-activity-sentinel' );
							echo esc_html( sprintf( $entries_text, (int) $total_items ) );
							?>
						</span>
						<?php
						echo wp_kses_post(
							(string) paginate_links(
								[
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
									'total'     => $total_pages,
									'current'   => $paged,
								]
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>

		</div>
		<!-- Slide-out Context Drawer Modal (sibling of .wm-sentinel-admin-wrap so inert/aria-hidden never disables the dialog) -->
		<div id="wm-sentinel-context-modal" class="wm-sentinel-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="wm-sentinel-modal-title" style="display:none;">
			<div class="wm-sentinel-modal-card wm-admin">
				<header class="wm-sentinel-modal-header">
					<h3 id="wm-sentinel-modal-title"><?php esc_html_e( 'Ereignis-Kontext Inspektor', 'wm-activity-sentinel' ); ?></h3>
					<button type="button" class="wm-sentinel-modal-close" aria-label="<?php esc_attr_e( 'Inspektor schließen', 'wm-activity-sentinel' ); ?>"><span aria-hidden="true">&times;</span></button>
				</header>
				<div class="wm-sentinel-modal-body">
					<pre id="wm-sentinel-context-json" tabindex="0"></pre>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Integrity Audit Page
	 */
	public function render_integrity_page(): void {
		$audit_result = WM_Sentinel_Integrity::verify_chain();

		?>
		<div class="wrap wm-admin wm-sentinel-admin-wrap">
			<?php
			self::render_page_header(
				'dashicons-shield-alt',
				__( 'Kettenprüfung', 'wm-activity-sentinel' ),
				__( 'Kryptografisches Integritäts-Audit (Hash-Chaining)', 'wm-activity-sentinel' ),
				// Until 1.4.2 this page spoke of «Block #1» and a «Master-Block»: the rows are entries, and the tip is
				// the chain state (hash and count) mirrored outside the log table.
				__( 'Jeder Eintrag wird mit HMAC-SHA256 gegen seinen Vorgänger geprüft, und das Ende der Kette gegen den außerhalb der Protokolltabelle verzeichneten Kettenstand.', 'wm-activity-sentinel' )
			);
			?>

			<?php self::render_nav_tabs( 'wm-sentinel-integrity' ); ?>

			<div class="wm-card wm-sentinel-card">
				<h2><?php esc_html_e( 'Status der Prüfsummen-Kette', 'wm-activity-sentinel' ); ?></h2>
				
				<?php if ( $audit_result['valid'] ) : ?>
					<div class="wm-sentinel-status-banner wm-status-pass">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<div>
							<h3><?php esc_html_e( 'KETTE INTAKT', 'wm-activity-sentinel' ); ?></h3>
							<p>
								<?php
								/* translators: %d: number of verified log entries */
								$verified_text = __( '%d Log-Einträge vom ersten Eintrag bis zum verzeichneten Kettenstand geprüft. Keine nachträgliche Änderung, Lücke oder Kürzung erkennbar.', 'wm-activity-sentinel' );
								echo esc_html( sprintf( $verified_text, (int) $audit_result['total_verified'] ) );
								?>
							</p>
							<?php if ( ! empty( $audit_result['pseudonymized'] ) ) : ?>
								<p>
									<?php
									/* translators: %d: number of pseudonymised log entries */
									echo esc_html( sprintf( __( '%d davon wurden auf eine Löschanfrage hin pseudonymisiert und über den jeweiligen Löschnachweis in der Kette anerkannt.', 'wm-activity-sentinel' ), (int) $audit_result['pseudonymized'] ) );
									?>
								</p>
							<?php endif; ?>
							<?php if ( ! empty( $audit_result['last_entry_hash'] ) ) : ?>
								<p><strong><?php esc_html_e( 'Hash des letzten Eintrags:', 'wm-activity-sentinel' ); ?></strong> <code><?php echo esc_html( $audit_result['last_entry_hash'] ); ?></code></p>
							<?php endif; ?>
						</div>
					</div>
				<?php else : ?>
					<div class="wm-sentinel-status-banner wm-status-fail">
						<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
						<div>
							<h3><?php esc_html_e( 'MANIPULATION ODER KETTENBRUCH ERKANNT', 'wm-activity-sentinel' ); ?></h3>
							<p><strong><?php esc_html_e( 'Fehlerdiagnose:', 'wm-activity-sentinel' ); ?></strong> <?php echo esc_html( $audit_result['error_reason'] ?? '' ); ?></p>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( 'wp-config-placeholder' === ( $audit_result['key_source'] ?? '' ) ) : ?>
				<div class="notice notice-warning inline wm-sentinel-notice-spaced">
					<p><strong><?php esc_html_e( 'AUTH_KEY ist noch der Platzhalter aus wp-config-sample.php.', 'wm-activity-sentinel' ); ?></strong></p>
					<p><?php esc_html_e( 'Die Kette ist mit einem öffentlich bekannten Schlüssel signiert: Jeder mit Datenbankzugriff kann Einträge ändern und die Kette neu signieren. Ein neuer AUTH_KEY in der wp-config.php schützt künftige Einträge; bestehende Einträge bestehen die Prüfung dann nicht mehr.', 'wm-activity-sentinel' ); ?></p>
				</div>
				<?php elseif ( 'wp-config' !== ( $audit_result['key_source'] ?? 'wp-config' ) ) : ?>
				<div class="notice notice-warning inline wm-sentinel-notice-spaced">
					<p><strong><?php esc_html_e( 'Der Kettenschlüssel liegt in der Datenbank, nicht in der wp-config.php.', 'wm-activity-sentinel' ); ?></strong></p>
					<p><?php esc_html_e( 'In dieser Installation ist AUTH_KEY nicht definiert, daher greift WordPress auf automatisch erzeugte Salts zurück und legt sie in wp_options ab — also in derselben Datenbank, in die ein Angreifer ohnehin schreiben würde. Der Manipulationsnachweis hält hier nur gegen Unfälle, nicht gegen einen Angreifer. Setzen Sie AUTH_KEY in der wp-config.php.', 'wm-activity-sentinel' ); ?></p>
				</div>
				<?php endif; ?>

				<div class="wm-sentinel-actions-row">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wm-sentinel-integrity' ) ); ?>" class="button button-primary">
						<span class="dashicons dashicons-update" aria-hidden="true"></span> <?php esc_html_e( 'Integritätsprüfung erneut ausführen', 'wm-activity-sentinel' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the File Integrity Monitor page.
	 *
	 * Shows the last stored report rather than scanning on every page load: a full scan hashes every
	 * core file, which is a cron job's work, not a page render's.
	 */
	public function render_fim_page(): void {
		$report   = WM_Sentinel_FIM::last_report();
		$enabled  = (bool) get_option( 'wm_sentinel_fim_enabled', 1 );
		$next_run = wp_next_scheduled( WM_Sentinel_FIM::CRON_HOOK );

		$core  = is_array( $report ) ? (array) $report['core'] : [];
		$suite = is_array( $report ) ? (array) $report['suite'] : [];

		?>
		<div class="wrap wm-admin wm-sentinel-admin-wrap">
			<?php
			self::render_page_header(
				'dashicons-media-code',
				__( 'Dateiintegrität', 'wm-activity-sentinel' ),
				__( 'Abgleich gegen den offiziellen Prüfsummen-Katalog', 'wm-activity-sentinel' ),
				__( 'Die Kerndateien werden gegen api.wordpress.org geprüft, die WM-Suite-Plugins gegen eine Basislinie, deren Fingerabdruck in der Hash-Kette versiegelt ist.', 'wm-activity-sentinel' )
			);
			?>

			<?php self::render_nav_tabs( 'wm-sentinel-fim' ); ?>

			<?php if ( isset( $_GET['fim_sealed'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: number of files in the baseline */
					echo esc_html( sprintf( __( 'Basislinie über %d Dateien erfasst und in der Kette versiegelt.', 'wm-activity-sentinel' ), (int) $_GET['fim_sealed'] ) );
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['fim_refused'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p>
					<?php
					/* translators: 1: number of files found, 2: configured maximum */
					echo esc_html( sprintf( __( 'Die Basislinie wurde nicht gespeichert: %1$d Dateien überschreiten das Maximum von %2$d. Eine Basislinie dieser Größe wird bei jeder Prüfung gelesen und neu geschrieben. Prüfen Sie, ob in einem Plugin-Verzeichnis Entwicklungsdateien liegen, die dort nicht hingehören.', 'wm-activity-sentinel' ), (int) $_GET['fim_refused'], WM_Sentinel_FIM::MAX_BASELINE_FILES ) );
					?>
				</p></div>
			<?php endif; ?>

			<div class="wm-card wm-sentinel-card">
				<h2><?php esc_html_e( 'Letzte Prüfung', 'wm-activity-sentinel' ); ?></h2>

				<?php if ( null === $report ) : ?>
					<div class="wm-sentinel-status-banner">
						<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
						<div>
							<h3><?php esc_html_e( 'NOCH NICHT GEPRÜFT', 'wm-activity-sentinel' ); ?></h3>
							<p><?php esc_html_e( 'Es liegt kein Prüfbericht vor. Starten Sie die Prüfung unten oder warten Sie den täglichen Lauf ab. Ein ungeprüfter Dateibaum ist kein unauffälliger Dateibaum.', 'wm-activity-sentinel' ); ?></p>
						</div>
					</div>
				<?php elseif ( 'critical' === ( $report['severity'] ?? '' ) ) : ?>
					<div class="wm-sentinel-status-banner wm-status-fail">
						<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
						<div>
							<h3><?php esc_html_e( 'VERÄNDERTE ODER UNERWARTETE DATEIEN GEFUNDEN', 'wm-activity-sentinel' ); ?></h3>
							<p>
								<?php
								/* translators: 1: number of findings, 2: timestamp in UTC */
								echo esc_html( sprintf( __( '%1$d Abweichungen, zuletzt geprüft am %2$s UTC.', 'wm-activity-sentinel' ), (int) $report['findings'], (string) $report['ran_at'] ) );
								?>
							</p>
						</div>
					</div>
				<?php elseif ( 'info' === ( $report['severity'] ?? '' ) ) : ?>
					<div class="wm-sentinel-status-banner wm-status-pass">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<div>
							<h3><?php esc_html_e( 'KEINE ABWEICHUNG', 'wm-activity-sentinel' ); ?></h3>
							<p>
								<?php
								/* translators: 1: number of core files checked, 2: timestamp in UTC */
								echo esc_html( sprintf( __( '%1$d Kerndateien stimmen mit dem offiziellen Katalog überein (geprüft am %2$s UTC).', 'wm-activity-sentinel' ), (int) ( $core['checked'] ?? 0 ), (string) $report['ran_at'] ) );
								?>
							</p>
						</div>
					</div>
				<?php else : ?>
					<div class="notice notice-warning inline wm-sentinel-notice-spaced">
						<p>
							<?php
							/* translators: 1: number of findings, 2: timestamp in UTC */
							echo esc_html( sprintf( __( '%1$d Auffälligkeiten, zuletzt geprüft am %2$s UTC.', 'wm-activity-sentinel' ), (int) $report['findings'], (string) $report['ran_at'] ) );
							?>
						</p>
						<?php if ( ! empty( $core['error'] ) ) : ?>
							<p><?php echo esc_html( (string) $core['error'] ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( null !== $report ) : ?>
					<?php
					$buckets = [
						__( 'Verändert (Kerndateien)', 'wm-activity-sentinel' )                   => (array) ( $core['modified'] ?? [] ),
						__( 'Fehlend (Kerndateien)', 'wm-activity-sentinel' )                     => (array) ( $core['missing'] ?? [] ),
						__( 'Unerwartet in wp-admin / wp-includes', 'wm-activity-sentinel' )      => (array) ( $core['unexpected'] ?? [] ),
						__( 'Verändert (WM-Suite-Plugins)', 'wm-activity-sentinel' )          => (array) ( $suite['modified'] ?? [] ),
						__( 'Fehlend (WM-Suite-Plugins)', 'wm-activity-sentinel' )            => (array) ( $suite['missing'] ?? [] ),
						__( 'Neu gegenüber der Basislinie', 'wm-activity-sentinel' )              => (array) ( $suite['added'] ?? [] ),
					];
					?>
					<?php foreach ( $buckets as $label => $paths ) : ?>
						<?php if ( ! empty( $paths ) ) : ?>
							<h3><?php echo esc_html( $label ); ?> (<?php echo esc_html( (string) count( $paths ) ); ?>)</h3>
							<ul class="wm-sentinel-file-list">
								<?php foreach ( array_slice( $paths, 0, 50 ) as $path ) : ?>
									<li><code><?php echo esc_html( (string) $path ); ?></code></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					<?php endforeach; ?>

					<?php if ( ! empty( $core['hardening_removals'] ) ) : ?>
						<p class="description">
							<?php
							/* translators: %s: comma-separated file names */
							echo esc_html( sprintf( __( 'Nicht als Fund gewertet, weil ihr Entfernen gängige Härtung ist: %s.', 'wm-activity-sentinel' ), implode( ', ', array_map( 'strval', (array) $core['hardening_removals'] ) ) ) );
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<div class="wm-card wm-sentinel-card">
				<h2><?php esc_html_e( 'Basislinie der WM-Suite-Plugins', 'wm-activity-sentinel' ); ?></h2>

				<?php $seal = (string) ( $suite['seal'] ?? 'unsealed' ); ?>
				<?php if ( 'broken' === $seal ) : ?>
					<div class="notice notice-error inline wm-sentinel-notice-spaced">
						<p><strong><?php esc_html_e( 'Die gespeicherte Basislinie wurde nachträglich verändert.', 'wm-activity-sentinel' ); ?></strong></p>
						<p><?php esc_html_e( 'Ihr Fingerabdruck stimmt nicht mehr mit dem Wert überein, der beim Erfassen in die Hash-Kette geschrieben wurde. Wer eine Plugin-Datei ändert und anschließend die Basislinie anpasst, hinterlässt genau diese Abweichung.', 'wm-activity-sentinel' ); ?></p>
					</div>
				<?php elseif ( 'unsealed' === $seal ) : ?>
					<p><?php esc_html_e( 'Es ist noch keine Basislinie erfasst. Ohne sie werden nur die Kerndateien geprüft: für die Plugins gibt es keinen offiziellen Katalog, gegen den sich vergleichen ließe.', 'wm-activity-sentinel' ); ?></p>
				<?php else : ?>
					<p>
						<?php
						/* translators: %d: number of files covered by the baseline */
						echo esc_html( sprintf( __( 'Basislinie über %d Dateien, Fingerabdruck in der Kette versiegelt.', 'wm-activity-sentinel' ), (int) ( $suite['checked'] ?? 0 ) ) );
						?>
					</p>
				<?php endif; ?>

				<p class="description"><?php esc_html_e( 'Nach jeder Aktualisierung eines WM-Suite-Plugins meldet die Prüfung dessen Dateien als verändert, weil sie es sind. Erfassen Sie die Basislinie dann neu.', 'wm-activity-sentinel' ); ?></p>

				<form method="post">
					<?php wp_nonce_field( 'wm_sentinel_fim_action', 'wm_sentinel_fim_nonce' ); ?>
					<div class="wm-sentinel-actions-row">
						<button type="submit" name="wm_sentinel_run_fim" class="button button-primary">
							<span class="dashicons dashicons-update" aria-hidden="true"></span> <?php esc_html_e( 'Prüfung jetzt ausführen', 'wm-activity-sentinel' ); ?>
						</button>
						<button type="submit" name="wm_sentinel_seal_baseline" class="button button-secondary">
							<span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e( 'Basislinie erfassen und versiegeln', 'wm-activity-sentinel' ); ?>
						</button>
					</div>
				</form>

				<p class="description">
					<?php if ( ! $enabled ) : ?>
						<?php esc_html_e( 'Die geplante Prüfung ist ausgeschaltet; es läuft nur, was Sie hier von Hand starten.', 'wm-activity-sentinel' ); ?>
					<?php elseif ( $next_run ) : ?>
						<?php
						/* translators: %s: timestamp in UTC */
						echo esc_html( sprintf( __( 'Nächster geplanter Lauf: %s UTC.', 'wm-activity-sentinel' ), gmdate( 'Y-m-d H:i:s', (int) $next_run ) ) );
						?>
					<?php else : ?>
						<?php esc_html_e( 'Die Prüfung ist eingeschaltet, aber es ist kein Lauf geplant. Speichern Sie die Einstellungen einmal, damit der tägliche Termin angelegt wird.', 'wm-activity-sentinel' ); ?>
					<?php endif; ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Export Page
	 */
	public function render_export_page(): void {
		?>
		<div class="wrap wm-admin wm-sentinel-admin-wrap">
			<?php
			self::render_page_header(
				'dashicons-download',
				__( 'Export', 'wm-activity-sentinel' ),
				__( 'Export des Protokolls', 'wm-activity-sentinel' ),
				__( 'CSV- oder JSON-Datei mit bis zu 10.000 Einträgen, neueste zuerst.', 'wm-activity-sentinel' )
			);
			?>

			<?php self::render_nav_tabs( 'wm-sentinel-export' ); ?>

			<div class="wm-sentinel-columns">
				<div class="wm-card wm-sentinel-card">
					<h2><span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span> <?php esc_html_e( 'CSV-Datei', 'wm-activity-sentinel' ); ?></h2>
					<p><?php esc_html_e( 'Semikolon-getrennt, UTF-8 mit BOM. Zellen, die mit =, +, -, @, Tabulator oder Wagenrücklauf beginnen, erhalten ein vorangestelltes Apostroph, damit Tabellenprogramme sie nicht als Formel ausführen.', 'wm-activity-sentinel' ); ?></p>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wm-sentinel-export&action=wm_sentinel_export_csv' ), 'wm_sentinel_export_csv' ) ); ?>" class="button button-secondary">
						<span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'CSV-Datei herunterladen', 'wm-activity-sentinel' ); ?>
					</a>
				</div>

				<div class="wm-card wm-sentinel-card">
					<h2><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e( 'JSON-Paket', 'wm-activity-sentinel' ); ?></h2>
					<p><?php esc_html_e( 'Einträge mit ihren Hashes, das Ergebnis der Kettenprüfung zum Zeitpunkt des Exports und eine SHA-256-Prüfsumme über das Dokument ohne das Prüfsummenfeld. Die Datei ist nicht signiert: Wer sie ändert, kann die Prüfsumme neu berechnen.', 'wm-activity-sentinel' ); ?></p>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wm-sentinel-export&action=wm_sentinel_export_json' ), 'wm_sentinel_export_json' ) ); ?>" class="button button-primary">
						<span class="dashicons dashicons-shield" aria-hidden="true"></span> <?php esc_html_e( 'JSON-Paket herunterladen', 'wm-activity-sentinel' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Settings Page
	 */
	public function render_settings_page(): void {
		$logging_intensity   = get_option( 'wm_sentinel_logging_intensity', 'all' );
		$telemetry_intensity = get_option( 'wm_sentinel_telemetry_intensity', 'all' );
		$log_auth            = (bool) get_option( 'wm_sentinel_log_auth', 1 );
		$log_users           = (bool) get_option( 'wm_sentinel_log_users', 1 );
		$log_plugins         = (bool) get_option( 'wm_sentinel_log_plugins', 1 );
		$log_content         = (bool) get_option( 'wm_sentinel_log_content', 1 );
		$log_rest            = (bool) get_option( 'wm_sentinel_log_rest', 1 );
		$monitor_screens     = (bool) get_option( 'wm_sentinel_monitor_screens', 1 );
		$monitor_ui          = (bool) get_option( 'wm_sentinel_monitor_ui_interactions', 1 );
		$monitor_front       = (bool) get_option( 'wm_sentinel_monitor_frontend_views', 1 );

		$alert_email         = get_option( 'wm_sentinel_alert_email', get_option( 'admin_email' ) );
		$min_sev             = get_option( 'wm_sentinel_alert_min_severity', 'critical' );
		$webhook_url         = get_option( 'wm_sentinel_webhook_url', '' );
		$retention_days      = (int) get_option( 'wm_sentinel_retention_days', 90 );
		$enable_widget       = (bool) get_option( 'wm_sentinel_enable_dashboard_widget', 1 );
		$delete_on_uninstall = (bool) get_option( 'wm_sentinel_delete_data_on_uninstall', 0 );

		$siem_enabled        = (bool) get_option( 'wm_sentinel_siem_enabled', 0 );
		$siem_format         = (string) get_option( 'wm_sentinel_siem_format', 'cef' );
		$siem_transport      = (string) get_option( 'wm_sentinel_siem_transport', 'https' );
		$siem_host           = (string) get_option( 'wm_sentinel_siem_host', '' );
		$siem_port           = (int) get_option( 'wm_sentinel_siem_port', 514 );
		$siem_url            = (string) get_option( 'wm_sentinel_siem_url', '' );
		$siem_min_severity   = (string) get_option( 'wm_sentinel_siem_min_severity', 'warning' );

		$fim_enabled         = (bool) get_option( 'wm_sentinel_fim_enabled', 1 );
		$fim_scan_suite      = (bool) get_option( 'wm_sentinel_fim_scan_suite', 1 );

		?>
		<div class="wrap wm-admin wm-sentinel-admin-wrap">
			<?php
			self::render_page_header(
				'dashicons-admin-generic',
				__( 'Einstellungen', 'wm-activity-sentinel' ),
				__( 'Einstellungen & Protokollierungsstufe', 'wm-activity-sentinel' ),
				__( 'Protokollierungsstufe, überwachte Bereiche, Klick-Erfassung, Alarme per E-Mail und Webhook sowie Aufbewahrung.', 'wm-activity-sentinel' )
			);
			?>

			<?php self::render_nav_tabs( 'wm-sentinel-settings' ); ?>
			
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Einstellungen und Protokollierungsfilter wurden erfolgreich gespeichert.', 'wm-activity-sentinel' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['demo_seeded'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						/* translators: %d: number of demo log entries generated */
						$seeded_text = __( '%d von 15 Demo-Einträgen wurden an die Kette angehängt (ohne Alarm-Mails und Webhooks). Einträge, die Protokollierungsstufe oder Schalter nicht zulassen, werden nicht geschrieben.', 'wm-activity-sentinel' );
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only counter from the redirect after a nonce-checked action.
						echo esc_html( sprintf( $seeded_text, absint( $_GET['demo_seeded'] ) ) );
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['demo_deleted'] ) ) : ?>
				<div class="notice notice-info is-dismissible">
					<p>
						<?php
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only counter from the redirect after a nonce-checked action.
						$deleted_count = absint( $_GET['demo_deleted'] );
						if ( 0 === $deleted_count ) {
							// Nothing was reset: the old text said "vollständig zurückgesetzt" for 0 as well (until 2026-09-15).
							esc_html_e( 'Keine Demodaten gefunden; das Protokoll ist unverändert.', 'wm-activity-sentinel' );
						} else {
							/* translators: %d: number of demo log entries deleted */
							$deleted_text = __( '%d Demodaten-Logs wurden gelöscht. Das Protokoll wurde dabei vollständig zurückgesetzt und die Kette beginnt wieder beim Genesis-Block.', 'wm-activity-sentinel' );
							echo esc_html( sprintf( $deleted_text, $deleted_count ) );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['demo_refused'] ) ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><?php esc_html_e( 'Die Demodaten wurden nicht gelöscht: es liegen bereits echte Protokolleinträge vor. Eine fortlaufende Kette kann keinen Eintrag in der Mitte verlieren, ohne dass die Prüfung bricht — und sie nachträglich neu zu signieren würde genau die Manipulationserkennung aufheben, für die dieses Plugin da ist.', 'wm-activity-sentinel' ); ?></p>
					<p><?php esc_html_e( 'Die Demodaten bleiben als solche gekennzeichnet stehen. Wer sie zwingend loswerden muss, setzt das gesamte Protokoll über den Werksreset zurück und verliert dabei auch die echten Einträge.', 'wm-activity-sentinel' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['factory_reset'] ) ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><strong><?php esc_html_e( 'Werkseinstellungen wiederhergestellt:', 'wm-activity-sentinel' ); ?></strong> <?php esc_html_e( 'Alle Logs und Alarme wurden bereinigt, der Genesis-Block wurde neu initialisiert und alle Einstellungen auf Standardwerte zurückgesetzt.', 'wm-activity-sentinel' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'wm_sentinel_settings_nonce' ); ?>

				<!-- SECTION 1: Logging Intensity & Depth -->
				<div class="wm-card wm-sentinel-card">
					<h2>
						<span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
						<?php esc_html_e( '1. Protokollierungsstufe & Klick-Erfassung', 'wm-activity-sentinel' ); ?>
					</h2>
					<p class="description wm-sentinel-section-desc">
						<?php esc_html_e( 'Steuert, ab welchem Schweregrad Ereignisse in die Kette geschrieben werden. Einträge zu Löschanfragen und der Genesis-Block werden immer geschrieben.', 'wm-activity-sentinel' ); ?>
					</p>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="wm_sentinel_logging_intensity">
									<?php esc_html_e( 'Protokollierungsstufe', 'wm-activity-sentinel' ); ?>
								</label>
							</th>
							<td>
								<select name="wm_sentinel_logging_intensity" id="wm_sentinel_logging_intensity" class="wm-sentinel-select-wide">
									<option value="all" <?php selected( $logging_intensity, 'all' ); ?>>
										<?php esc_html_e( 'Vollständig (Info, Notice, Warning, Critical)', 'wm-activity-sentinel' ); ?>
									</option>
									<option value="standard" <?php selected( $logging_intensity, 'standard' ); ?>>
										<?php esc_html_e( 'Standard (Notice, Warning, Critical)', 'wm-activity-sentinel' ); ?>
									</option>
									<option value="strict" <?php selected( $logging_intensity, 'strict' ); ?>>
										<?php esc_html_e( 'Nur Warnungen (Warning, Critical)', 'wm-activity-sentinel' ); ?>
									</option>
									<option value="critical_only" <?php selected( $logging_intensity, 'critical_only' ); ?>>
										<?php esc_html_e( 'Nur kritische Ereignisse (Critical)', 'wm-activity-sentinel' ); ?>
									</option>
								</select>
								<p class="description">
									<strong><?php esc_html_e( 'Stufenübersicht:', 'wm-activity-sentinel' ); ?></strong><br />
									• <em><?php esc_html_e( 'Vollständig:', 'wm-activity-sentinel' ); ?></em> <?php esc_html_e( 'Schreibt jedes Ereignis der Sensoren, auch Info-Einträge wie Anmeldungen, Seitenaufrufe und Klicks.', 'wm-activity-sentinel' ); ?><br />
									• <em><?php esc_html_e( 'Standard:', 'wm-activity-sentinel' ); ?></em> <?php esc_html_e( 'Lässt Info-Einträge weg, also Anmeldungen, Seitenaufrufe und Klicks.', 'wm-activity-sentinel' ); ?><br />
									• <em><?php esc_html_e( 'Nur Warnungen:', 'wm-activity-sentinel' ); ?></em> <?php esc_html_e( 'Nur Ereignisse mit Schweregrad Warning oder Critical, zum Beispiel fehlgeschlagene Anmeldungen, Dateieditor, Deaktivieren von Plugins und Beförderung zum Administrator.', 'wm-activity-sentinel' ); ?><br />
									• <em><?php esc_html_e( 'Nur kritische Ereignisse:', 'wm-activity-sentinel' ); ?></em> <?php esc_html_e( 'Nur Schweregrad Critical, zum Beispiel ein Export der Website, ein gestartetes Backup oder die Beförderung zum Administrator.', 'wm-activity-sentinel' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wm_sentinel_telemetry_intensity">
									<?php esc_html_e( 'Klick- & Interaktions-Tiefe', 'wm-activity-sentinel' ); ?>
								</label>
							</th>
							<td>
								<select name="wm_sentinel_telemetry_intensity" id="wm_sentinel_telemetry_intensity" class="wm-sentinel-select-wide">
									<option value="all" <?php selected( $telemetry_intensity, 'all' ); ?>>
										<?php esc_html_e( 'Alle Interaktionen erfassen (Menüs, Buttons, Links, Tabs, Modals, Downloads)', 'wm-activity-sentinel' ); ?>
									</option>
									<option value="actions_only" <?php selected( $telemetry_intensity, 'actions_only' ); ?>>
										<?php esc_html_e( 'Nur operative Aktionen & Menü-Navigation (Menüs, Buttons, Speichern, Löschen)', 'wm-activity-sentinel' ); ?>
									</option>
									<option value="critical_only" <?php selected( $telemetry_intensity, 'critical_only' ); ?>>
										<?php esc_html_e( 'Nur destruktive Aktionen & Downloads (Lösch-Aktionen, Exporte, Archiv-Downloads)', 'wm-activity-sentinel' ); ?>
									</option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Filtert, welche Klicks angemeldeter Benutzer das Skript im Backend und im Frontend meldet.', 'wm-activity-sentinel' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>

				<!-- SECTION 2: Subsystem Surveillance Modules -->
				<div class="wm-card wm-sentinel-card">
					<h2>
						<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
						<?php esc_html_e( '2. Überwachte Systembereiche & Module', 'wm-activity-sentinel' ); ?>
					</h2>
					<p class="description wm-sentinel-section-desc">
						<?php esc_html_e( 'Schaltet einzelne Bereiche ab. Ein abgeschalteter Bereich schreibt keine Einträge.', 'wm-activity-sentinel' ); ?>
					</p>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Sicherheitsmodule', 'wm-activity-sentinel' ); ?></th>
							<td>
								<fieldset>
									<label for="wm_sentinel_log_auth" class="wm-sentinel-option-label">
										<input type="checkbox" name="wm_sentinel_log_auth" id="wm_sentinel_log_auth" value="1" <?php checked( $log_auth ); ?> />
										<strong><?php esc_html_e( 'Authentifizierung & Sessions', 'wm-activity-sentinel' ); ?></strong>
										<span class="description">— <?php esc_html_e( 'Erfolgreiche und fehlgeschlagene Anmeldungen, neue Anwendungspasswörter. Abmeldungen und Passwort-Resets werden nicht erfasst.', 'wm-activity-sentinel' ); ?></span>
									</label>

									<label for="wm_sentinel_log_users" class="wm-sentinel-option-label">
										<input type="checkbox" name="wm_sentinel_log_users" id="wm_sentinel_log_users" value="1" <?php checked( $log_users ); ?> />
										<strong><?php esc_html_e( 'Benutzerverwaltung & Berechtigungsrollen', 'wm-activity-sentinel' ); ?></strong>
										<span class="description">— <?php esc_html_e( 'Rollenänderungen (Administrator als Critical), angelegte und gelöschte Benutzer.', 'wm-activity-sentinel' ); ?></span>
									</label>

									<label for="wm_sentinel_log_plugins" class="wm-sentinel-option-label">
										<input type="checkbox" name="wm_sentinel_log_plugins" id="wm_sentinel_log_plugins" value="1" <?php checked( $log_plugins ); ?> />
										<strong><?php esc_html_e( 'Plugins, Themes & Core-Integrität', 'wm-activity-sentinel' ); ?></strong>
										<span class="description">— <?php esc_html_e( 'Plugin-Aktivierung, -Deaktivierung und -Löschung, Theme-Wechsel, Updates, ausgewählte Optionen, Aufrufe und Speicheranfragen des Dateieditors.', 'wm-activity-sentinel' ); ?></span>
									</label>

									<label for="wm_sentinel_log_content" class="wm-sentinel-option-label">
										<input type="checkbox" name="wm_sentinel_log_content" id="wm_sentinel_log_content" value="1" <?php checked( $log_content ); ?> />
										<strong><?php esc_html_e( 'Exporte & Backups', 'wm-activity-sentinel' ); ?></strong>
										<span class="description">— <?php esc_html_e( 'Native XML-Exporte, Aufrufe der Exportseite, Backup-Start von UpdraftPlus und Duplicator. Klicks auf Download-Links fallen unter „UI-Interaktionen“. Beitragsänderungen und Uploads werden nicht erfasst.', 'wm-activity-sentinel' ); ?></span>
									</label>

									<label for="wm_sentinel_log_rest" class="wm-sentinel-option-label">
										<input type="checkbox" name="wm_sentinel_log_rest" id="wm_sentinel_log_rest" value="1" <?php checked( $log_rest ); ?> />
										<strong><?php esc_html_e( 'REST-API', 'wm-activity-sentinel' ); ?></strong>
										<span class="description">— <?php esc_html_e( 'Aufrufe von /wp/v2/plugins und nicht angemeldete Aufrufe von /wp/v2/users.', 'wm-activity-sentinel' ); ?></span>
									</label>
								</fieldset>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Navigation & UI-Telemetrie', 'wm-activity-sentinel' ); ?></th>
							<td>
								<fieldset>
									<label for="wm_sentinel_monitor_screens" class="wm-sentinel-option-label">
										<input type="checkbox" name="wm_sentinel_monitor_screens" id="wm_sentinel_monitor_screens" value="1" <?php checked( $monitor_screens ); ?> />
										<strong><?php esc_html_e( 'Admin-Seitennavigation (current_screen)', 'wm-activity-sentinel' ); ?></strong>
										<span class="description">— <?php esc_html_e( 'Erfasst jeden Backend-Seitenaufruf mit übersetztem Klartextnamen.', 'wm-activity-sentinel' ); ?></span>
									</label>

									<label for="wm_sentinel_monitor_ui_interactions" class="wm-sentinel-option-label">
										<input type="checkbox" name="wm_sentinel_monitor_ui_interactions" id="wm_sentinel_monitor_ui_interactions" value="1" <?php checked( $monitor_ui ); ?> />
										<strong><?php esc_html_e( 'Klick- & Interaktions-Telemetrie (Client-Side)', 'wm-activity-sentinel' ); ?></strong>
										<span class="description">— <?php esc_html_e( 'Meldet Klicks angemeldeter Benutzer auf Menüs, Buttons, Tabs und Links mit Beschriftung, Ziel und Seitenadresse (höchstens 60 Einträge pro Benutzer und Minute).', 'wm-activity-sentinel' ); ?></span>
									</label>

									<label for="wm_sentinel_monitor_frontend_views" class="wm-sentinel-option-label">
										<input type="checkbox" name="wm_sentinel_monitor_frontend_views" id="wm_sentinel_monitor_frontend_views" value="1" <?php checked( $monitor_front ); ?> />
										<strong><?php esc_html_e( 'Frontend-Seitenaufrufe angemeldeter Benutzer', 'wm-activity-sentinel' ); ?></strong>
										<span class="description">— <?php esc_html_e( 'Protokolliert jede Frontend-Seite, die ein angemeldeter Benutzer aufruft, mit Adresse und Referrer.', 'wm-activity-sentinel' ); ?></span>
									</label>
								</fieldset>
							</td>
						</tr>
					</table>
				</div>

				<!-- SECTION 3: Real-Time Alerts & Webhooks -->
				<div class="wm-card wm-sentinel-card">
					<h2>
						<span class="dashicons dashicons-bell" aria-hidden="true"></span>
						<?php esc_html_e( '3. Echtzeit-Alarme & Benachrichtigungen', 'wm-activity-sentinel' ); ?>
					</h2>
					<p class="description wm-sentinel-section-desc">
						<?php esc_html_e( 'E-Mail und Webhook, sobald ein Eintrag mit mindestens dem gewählten Schweregrad geschrieben wird. Ein Kettenbruch löst keinen Alarm aus; er zeigt sich erst bei der nächsten Prüfung.', 'wm-activity-sentinel' ); ?>
					</p>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="wm_sentinel_alert_email">
									<?php esc_html_e( 'Alarm-Empfänger E-Mail', 'wm-activity-sentinel' ); ?>
								</label>
							</th>
							<td>
								<input type="email" name="wm_sentinel_alert_email" id="wm_sentinel_alert_email" value="<?php echo esc_attr( $alert_email ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Erhält die Alarm-Mails; ab welchem Schweregrad, legt das nächste Feld fest.', 'wm-activity-sentinel' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wm_sentinel_alert_min_severity">
									<?php esc_html_e( 'Min. Schweregrad für Alarme', 'wm-activity-sentinel' ); ?>
								</label>
							</th>
							<td>
								<select name="wm_sentinel_alert_min_severity" id="wm_sentinel_alert_min_severity">
									<option value="critical" <?php selected( $min_sev, 'critical' ); ?>><?php esc_html_e( 'Nur Kritische Vorfälle (Critical)', 'wm-activity-sentinel' ); ?></option>
									<option value="warning" <?php selected( $min_sev, 'warning' ); ?>><?php esc_html_e( 'Warnungen & Kritisch (Warning+)', 'wm-activity-sentinel' ); ?></option>
									<option value="notice" <?php selected( $min_sev, 'notice' ); ?>><?php esc_html_e( 'Notice und höher (Notice-Einträge selbst lösen keinen Alarm aus)', 'wm-activity-sentinel' ); ?></option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wm_sentinel_webhook_url">
									<?php esc_html_e( 'Webhook-URL', 'wm-activity-sentinel' ); ?>
								</label>
							</th>
							<td>
								<input type="url" name="wm_sentinel_webhook_url" id="wm_sentinel_webhook_url" value="<?php echo esc_attr( $webhook_url ); ?>" class="large-text" placeholder="https://hooks.slack.com/services/..." />
								<p class="description"><?php esc_html_e( 'Erhält einen JSON-POST mit text, log_id, event_type, severity, user, site und timestamp. Ob ein Chat-Dienst dieses Format annimmt, prüft das Plugin nicht.', 'wm-activity-sentinel' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- SECTION 4: GDPR & Dashboard Integration -->
				<div class="wm-card wm-sentinel-card">
					<h2>
						<span class="dashicons dashicons-lock" aria-hidden="true"></span>
						<?php esc_html_e( '4. Aufbewahrung & Dashboard', 'wm-activity-sentinel' ); ?>
					</h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="wm_sentinel_retention_days">
									<?php esc_html_e( 'Aufbewahrungsfrist', 'wm-activity-sentinel' ); ?>
								</label>
							</th>
							<td>
								<input type="number" name="wm_sentinel_retention_days" id="wm_sentinel_retention_days" value="<?php echo esc_attr( (string) $retention_days ); ?>" min="30" max="3650" class="small-text" /> <?php esc_html_e( 'Tage', 'wm-activity-sentinel' ); ?>
								<p class="description"><?php esc_html_e( 'Täglich angewendet: Einträge, die älter als diese Frist sind, verlieren Name, E-Mail-Adresse und IP-Kennung. Gelöscht wird nichts — jede gelöschte Zeile würde die Kette unterbrechen und die Prüfung würde Ihre eigene Aufbewahrungsregel als Manipulation melden. Ereignisart, Zeitpunkt, Schweregrad und Kontext bleiben stehen. Unter 30 Tagen bedeutet: alles behalten.', 'wm-activity-sentinel' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Dashboard-Widget', 'wm-activity-sentinel' ); ?></th>
							<td>
								<label for="wm_sentinel_enable_dashboard_widget">
									<input type="checkbox" name="wm_sentinel_enable_dashboard_widget" id="wm_sentinel_enable_dashboard_widget" value="1" <?php checked( $enable_widget ); ?> />
									<?php esc_html_e( 'Widget auf dem WordPress-Dashboard anzeigen', 'wm-activity-sentinel' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Zeigt den aktuellen Prüfsummen-Status (Hash-Chain), KPI-Kennzahlen und die letzten Vorfälle direkt auf der Hauptseite des WordPress-Backends an.', 'wm-activity-sentinel' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Deinstallation', 'wm-activity-sentinel' ); ?></th>
							<td>
								<label for="wm_sentinel_delete_data_on_uninstall">
									<input type="checkbox" name="wm_sentinel_delete_data_on_uninstall" id="wm_sentinel_delete_data_on_uninstall" value="1" <?php checked( $delete_on_uninstall ); ?> />
									<?php esc_html_e( 'Protokolltabellen und versiegelte Basislinie beim Löschen des Plugins endgültig entfernen', 'wm-activity-sentinel' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Standardmäßig bleiben die Tabellen wp_wm_activity_logs und wp_wm_activity_alerts sowie der gespiegelte Kettenendpunkt und die versiegelte Datei-Basislinie bei einer Deinstallation erhalten. Aktivieren Sie diese Option nur, wenn beim Entfernen des Plugins auch die gesamte Audit-Kette gelöscht werden soll.', 'wm-activity-sentinel' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- SECTION 5: SIEM forwarding -->
				<div class="wm-card wm-sentinel-card">
					<h2>
						<span class="dashicons dashicons-share-alt2" aria-hidden="true"></span>
						<?php esc_html_e( '5. Weiterleitung an ein SIEM', 'wm-activity-sentinel' ); ?>
					</h2>
					<p class="description"><?php esc_html_e( 'Jeder protokollierte Eintrag ab dem gewählten Schweregrad wird zusätzlich an einen Collector geschickt — als Syslog nach RFC 5424, als CEF:0 oder als LEEF:2.0. Splunk, Microsoft Sentinel, Datadog und Elastic lesen mindestens eines dieser Formate. Der Eintrag im Protokoll bleibt davon unberührt: die Weiterleitung ist eine Kopie, und ein nicht erreichbarer Collector hält WordPress nicht auf.', 'wm-activity-sentinel' ); ?></p>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Weiterleitung', 'wm-activity-sentinel' ); ?></th>
							<td>
								<label for="wm_sentinel_siem_enabled">
									<input type="checkbox" name="wm_sentinel_siem_enabled" id="wm_sentinel_siem_enabled" value="1" <?php checked( $siem_enabled ); ?> />
									<?php esc_html_e( 'Einträge an den konfigurierten Collector senden', 'wm-activity-sentinel' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="wm_sentinel_siem_format"><?php esc_html_e( 'Format', 'wm-activity-sentinel' ); ?></label></th>
							<td>
								<select name="wm_sentinel_siem_format" id="wm_sentinel_siem_format">
									<option value="cef" <?php selected( $siem_format, 'cef' ); ?>><?php esc_html_e( 'CEF:0 (ArcSight, Microsoft Sentinel, Splunk)', 'wm-activity-sentinel' ); ?></option>
									<option value="syslog" <?php selected( $siem_format, 'syslog' ); ?>><?php esc_html_e( 'Syslog RFC 5424 (Elastic, Datadog, rsyslog)', 'wm-activity-sentinel' ); ?></option>
									<option value="leef" <?php selected( $siem_format, 'leef' ); ?>><?php esc_html_e( 'LEEF:2.0 (IBM QRadar)', 'wm-activity-sentinel' ); ?></option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="wm_sentinel_siem_transport"><?php esc_html_e( 'Transport', 'wm-activity-sentinel' ); ?></label></th>
							<td>
								<select name="wm_sentinel_siem_transport" id="wm_sentinel_siem_transport">
									<option value="https" <?php selected( $siem_transport, 'https' ); ?>><?php esc_html_e( 'HTTPS (HEC-/HTTP-Endpunkt)', 'wm-activity-sentinel' ); ?></option>
									<option value="tcp" <?php selected( $siem_transport, 'tcp' ); ?>><?php esc_html_e( 'TCP', 'wm-activity-sentinel' ); ?></option>
									<option value="udp" <?php selected( $siem_transport, 'udp' ); ?>><?php esc_html_e( 'UDP (ohne Empfangsbestätigung)', 'wm-activity-sentinel' ); ?></option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="wm_sentinel_siem_url"><?php esc_html_e( 'Collector-URL (HTTPS)', 'wm-activity-sentinel' ); ?></label></th>
							<td>
								<input type="url" name="wm_sentinel_siem_url" id="wm_sentinel_siem_url" value="<?php echo esc_attr( $siem_url ); ?>" class="large-text" placeholder="https://collector.example.com/services/collector/raw" />
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="wm_sentinel_siem_host"><?php esc_html_e( 'Collector-Host und Port (TCP/UDP)', 'wm-activity-sentinel' ); ?></label></th>
							<td>
								<input type="text" name="wm_sentinel_siem_host" id="wm_sentinel_siem_host" value="<?php echo esc_attr( $siem_host ); ?>" class="regular-text" placeholder="siem.intern.example" />
								<input type="number" name="wm_sentinel_siem_port" id="wm_sentinel_siem_port" value="<?php echo esc_attr( (string) $siem_port ); ?>" min="1" max="65535" class="small-text" />
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="wm_sentinel_siem_min_severity"><?php esc_html_e( 'Min. Schweregrad für die Weiterleitung', 'wm-activity-sentinel' ); ?></label></th>
							<td>
								<select name="wm_sentinel_siem_min_severity" id="wm_sentinel_siem_min_severity">
									<option value="info" <?php selected( $siem_min_severity, 'info' ); ?>><?php esc_html_e( 'Alles (Info+)', 'wm-activity-sentinel' ); ?></option>
									<option value="notice" <?php selected( $siem_min_severity, 'notice' ); ?>><?php esc_html_e( 'Notice und höher', 'wm-activity-sentinel' ); ?></option>
									<option value="warning" <?php selected( $siem_min_severity, 'warning' ); ?>><?php esc_html_e( 'Warnungen und höher', 'wm-activity-sentinel' ); ?></option>
									<option value="critical" <?php selected( $siem_min_severity, 'critical' ); ?>><?php esc_html_e( 'Nur kritische Vorfälle', 'wm-activity-sentinel' ); ?></option>
								</select>
							</td>
						</tr>
					</table>
				</div>

				<!-- SECTION 6: File integrity monitor -->
				<div class="wm-card wm-sentinel-card">
					<h2>
						<span class="dashicons dashicons-media-code" aria-hidden="true"></span>
						<?php esc_html_e( '6. Dateiintegrität', 'wm-activity-sentinel' ); ?>
					</h2>
					<p class="description"><?php esc_html_e( 'Ein täglicher Abgleich der Kerndateien gegen den offiziellen Prüfsummen-Katalog von api.wordpress.org. Dabei verlassen nur die WordPress-Version und die Sprache die Website — dieselben Angaben, die WordPress ohnehin bei jeder Update-Prüfung sendet. Das Ergebnis wird als Eintrag in die Hash-Kette geschrieben und damit, sofern eingeschaltet, auch an das SIEM weitergeleitet.', 'wm-activity-sentinel' ); ?></p>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Tägliche Prüfung', 'wm-activity-sentinel' ); ?></th>
							<td>
								<label for="wm_sentinel_fim_enabled">
									<input type="checkbox" name="wm_sentinel_fim_enabled" id="wm_sentinel_fim_enabled" value="1" <?php checked( $fim_enabled ); ?> />
									<?php esc_html_e( 'Kerndateien täglich gegen api.wordpress.org prüfen', 'wm-activity-sentinel' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'WM-Suite-Plugins', 'wm-activity-sentinel' ); ?></th>
							<td>
								<label for="wm_sentinel_fim_scan_suite">
									<input type="checkbox" name="wm_sentinel_fim_scan_suite" id="wm_sentinel_fim_scan_suite" value="1" <?php checked( $fim_scan_suite ); ?> />
									<?php esc_html_e( 'Zusätzlich gegen die versiegelte Basislinie prüfen', 'wm-activity-sentinel' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Für Plugins gibt es keinen offiziellen Katalog. Stattdessen wird eine Basislinie erfasst, deren Fingerabdruck in der Hash-Kette versiegelt wird: Wer eine Plugin-Datei ändert und anschließend die Basislinie anpasst, bricht damit den Ketteneintrag. Die Basislinie wird auf der Seite "Dateiintegrität" erfasst.', 'wm-activity-sentinel' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<p class="submit">
					<button type="submit" name="wm_sentinel_save_settings" class="button button-primary button-large">
						<span class="dashicons dashicons-saved wm-sentinel-btn-icon" aria-hidden="true"></span>
						<?php esc_html_e( 'Einstellungen & Filter speichern', 'wm-activity-sentinel' ); ?>
					</button>
				</p>
			</form>

			<!-- SECTION 7: Demodaten-Management & Factory Reset -->
			<div class="wm-card wm-sentinel-card wm-sentinel-card-accent">
				<h2>
					<span class="dashicons dashicons-database-import wm-sentinel-icon-accent" aria-hidden="true"></span>
					<?php esc_html_e( '7. Demodaten-Management & Werkseinstellungen (Reset)', 'wm-activity-sentinel' ); ?>
				</h2>
				<p class="wm-field-desc">
					<?php esc_html_e( 'Beispieleinträge anhängen oder entfernen, oder das Protokoll leeren und alle Einstellungen zurücksetzen.', 'wm-activity-sentinel' ); ?>
				</p>

				<div class="wm-sentinel-actions-row">
					<!-- Form 1: Import Demo Data -->
					<form method="post">
						<?php wp_nonce_field( 'wm_sentinel_demo_action', 'wm_sentinel_demo_nonce' ); ?>
						<button type="submit" name="wm_sentinel_seed_demo" class="button button-secondary">
							<span class="dashicons dashicons-download wm-sentinel-btn-icon" aria-hidden="true"></span>
							<?php esc_html_e( 'Demodaten anhängen (15 Einträge)', 'wm-activity-sentinel' ); ?>
						</button>
					</form>

					<!-- Form 2: Delete Demo Data Only -->
					<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Demodaten lassen sich nur entfernen, solange noch keine echten Ereignisse protokolliert wurden — eine fortlaufende Kette kann keinen Eintrag in der Mitte verlieren. Trifft das zu, wird das gesamte Protokoll zurückgesetzt und die Kette startet neu beim Genesis-Block.', 'wm-activity-sentinel' ) ); ?>');">
						<?php wp_nonce_field( 'wm_sentinel_demo_action', 'wm_sentinel_demo_nonce' ); ?>
						<button type="submit" name="wm_sentinel_delete_demo" class="button button-secondary wm-sentinel-btn-danger-text">
							<span class="dashicons dashicons-trash wm-sentinel-btn-icon" aria-hidden="true"></span>
							<?php esc_html_e( 'Demodaten bereinigen (Nur Testdaten)', 'wm-activity-sentinel' ); ?>
						</button>
					</form>
				</div>

				<hr class="wm-sentinel-divider" />

				<!-- Danger Zone: Factory Reset -->
				<div class="wm-sentinel-danger-zone">
					<h4>
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<?php esc_html_e( 'Gefahrenbereich: Auf Werkseinstellungen zurücksetzen', 'wm-activity-sentinel' ); ?>
					</h4>
					<p>
						<?php esc_html_e( 'Dieser Vorgang löscht unwiderruflich ALLE Logs und Alarme aus der Datenbank, initialisiert einen neuen Genesis-Block und setzt alle Einstellungen auf die Standardwerte zurück.', 'wm-activity-sentinel' ); ?>
					</p>
					<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'WARNUNG: Sind Sie sicher? Dies löscht ALLE Aktivitätsprotokolle und setzt Sentinel vollständig auf Werkseinstellungen zurück!', 'wm-activity-sentinel' ) ); ?>');">
						<?php wp_nonce_field( 'wm_sentinel_reset_action', 'wm_sentinel_reset_nonce' ); ?>
						<button type="submit" name="wm_sentinel_factory_reset" class="wm-btn wm-btn-danger">
							<span class="dashicons dashicons-undo" aria-hidden="true"></span>
							<?php esc_html_e( 'Werkseinstellungen wiederherstellen (Factory Reset)', 'wm-activity-sentinel' ); ?>
						</button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Add WordPress Native Contextual Help Tabs
	 */
	public function add_contextual_help(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! str_contains( $screen->id, 'wm-sentinel' ) ) {
			return;
		}

		$screen->add_help_tab( [
			'id'      => 'wm_sentinel_help_overview',
			'title'   => __( 'Überblick & Hash-Kette', 'wm-activity-sentinel' ),
			'content' => '<p><strong>' . esc_html__( 'WM Activity Sentinel', 'wm-activity-sentinel' ) . '</strong> ' . esc_html__( 'ist ein Aktivitätsprotokoll für WordPress. Jeder Logeintrag wird über HMAC-SHA256 mit seinem Vorgänger verknüpft, und das Kettenende zusätzlich außerhalb der Log-Tabelle verzeichnet. Wer nur Schreibzugriff auf die Datenbank hat, kann Einträge daher nicht ändern, löschen oder einschleusen, ohne dass die Prüfung darauf hinweist. Der Schlüssel liegt in der wp-config.php: wer diese Datei liest, kann die Kette neu signieren — das Verfahren weist Manipulation nach, es verhindert sie nicht.', 'wm-activity-sentinel' ) . '</p>',
		] );

		$screen->add_help_tab( [
			'id'      => 'wm_sentinel_help_intensity',
			'title'   => __( 'Protokollierungsstufen', 'wm-activity-sentinel' ),
			'content' => '<p>' . esc_html__( 'Die Protokollierungsstufe wird unter Einstellungen gewählt: "Vollständig" schreibt auch Info-Einträge wie Anmeldungen, Seitenaufrufe und Klicks; "Standard" lässt Info-Einträge weg; "Nur Warnungen" schreibt Warning und Critical; "Nur kritische Ereignisse" nur Critical. Der Genesis-Eintrag sowie Lösch- und Aufbewahrungsnachweise werden auf jeder Stufe geschrieben.', 'wm-activity-sentinel' ) . '</p>',
		] );

		$screen->add_help_tab( [
			'id'      => 'wm_sentinel_help_gdpr',
			'title'   => __( 'Personenbezogene Daten', 'wm-activity-sentinel' ),
			'content' => '<p>' . esc_html__( 'IP-Adressen werden nicht im Klartext gespeichert, sondern als HMAC mit täglich wechselndem Schlüssel. Name und E-Mail-Adresse des handelnden Benutzers, besuchte Adressen und Klick-Beschriftungen stehen im Klartext im Protokoll. Die Datenschutz-Werkzeuge von WordPress können Einträge zu einer Adresse exportieren und pseudonymisieren.', 'wm-activity-sentinel' ) . '</p>',
		] );

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'Support & Handbuch:', 'wm-activity-sentinel' ) . '</strong></p>' .
			'<p><a href="' . esc_url( admin_url( 'admin.php?page=wm-sentinel-docs' ) ) . '" class="button button-secondary">' . esc_html__( 'Vollständiges Handbuch öffnen', 'wm-activity-sentinel' ) . '</a></p>' .
			'<p>' . esc_html__( 'Entwickelt von WM Suite.', 'wm-activity-sentinel' ) . '</p>'
		);
	}

	/**
	 * Render In-Plugin Documentation & Handbook Page
	 */
	public function render_docs_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-activity-sentinel' ) );
		}
		?>
		<div class="wrap wm-admin wm-sentinel-admin-wrap">
			<?php
			self::render_page_header(
				'dashicons-book',
				__( 'Handbuch', 'wm-activity-sentinel' ),
				__( 'WM Activity Sentinel — Handbuch', 'wm-activity-sentinel' ),
				__( 'Hash-Kette, Sensoren, Entwickler-API und Hinweise zum Betrieb.', 'wm-activity-sentinel' )
			);
			?>

			<?php self::render_nav_tabs( 'wm-sentinel-docs' ); ?>

			<div class="wm-grid-2 wm-sentinel-docs-grid">
				<!-- CARD 1: Cryptographic Model -->
				<div class="wm-card wm-sentinel-card">
					<h2>
						<span class="dashicons dashicons-shield" aria-hidden="true"></span>
						<?php esc_html_e( '1. Kryptografische Hash-Kette (Integrität)', 'wm-activity-sentinel' ); ?>
					</h2>
					<p><?php esc_html_e( 'Jeder Datensatz enthält den kryptografischen Fingerabdruck seines direkten Vorgängers (prev_hash). Die Prüfsumme wird wie folgt berechnet:', 'wm-activity-sentinel' ); ?></p>
					<pre>payload    = JSON( alphabetisch sortiert: context, created_at, event_type, ip_hmac,
                   object_id, object_type, prev_hash, severity, user_email, user_id, user_name )
entry_hash = HMAC-SHA256( prev_hash + '|' + payload, AUTH_KEY )</pre>
					<ul class="wm-sentinel-list">
						<li><strong><?php esc_html_e( 'Änderung erkennbar:', 'wm-activity-sentinel' ); ?></strong> <?php esc_html_e( 'Wird eine Zeile ohne den Schlüssel geändert, schlägt die Prüfsumme bei der nächsten Prüfung fehl. Wer den Schlüssel hat, kann die Kette neu signieren.', 'wm-activity-sentinel' ); ?></li>
						<li><strong><?php esc_html_e( 'Löschungsnachweis:', 'wm-activity-sentinel' ); ?></strong> <?php esc_html_e( 'Wird eine Zeile in der Mitte gelöscht, passt der Verweis der Folgezeile nicht mehr; fehlen die neuesten Zeilen, weicht das in wp_options gespiegelte Kettenende ab.', 'wm-activity-sentinel' ); ?></li>
						<li><strong><?php esc_html_e( 'Concurrency-Locking:', 'wm-activity-sentinel' ); ?></strong> <?php esc_html_e( 'InnoDB "SELECT ... FOR UPDATE" verhindert Ketten-Bifurkationen bei parallelen Schreibzugriffen.', 'wm-activity-sentinel' ); ?></li>
					</ul>
				</div>

				<!-- CARD 2: Sensor Hierarchy -->
				<div class="wm-card wm-sentinel-card">
					<h2>
						<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
						<?php esc_html_e( '2. Sensoren', 'wm-activity-sentinel' ); ?>
					</h2>
					<p><?php esc_html_e( 'Diese WordPress-Hooks schreiben Einträge; alles andere erfasst das Plugin nicht:', 'wm-activity-sentinel' ); ?></p>
					<table class="widefat striped wm-sentinel-table-compact">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Bereich', 'wm-activity-sentinel' ); ?></th>
								<th><?php esc_html_e( 'Sensoren & Hooks', 'wm-activity-sentinel' ); ?></th>
								<th><?php esc_html_e( 'Schweregrad', 'wm-activity-sentinel' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'Authentifizierung', 'wm-activity-sentinel' ); ?></strong></td>
								<td><?php esc_html_e( 'wp_login (Info), wp_login_failed und wp_create_application_password (Warning)', 'wm-activity-sentinel' ); ?></td>
								<td><span class="wm-sentinel-badge wm-sentinel-badge-warning"><?php esc_html_e( 'Info / Warning', 'wm-activity-sentinel' ); ?></span></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Benutzer & Rollen', 'wm-activity-sentinel' ); ?></strong></td>
								<td><?php esc_html_e( 'user_register (Notice), deleted_user (Warning), set_user_role (Notice, Critical bei der Rolle Administrator)', 'wm-activity-sentinel' ); ?></td>
								<td><span class="wm-sentinel-badge wm-sentinel-badge-critical"><?php esc_html_e( 'Notice / Warning / Critical', 'wm-activity-sentinel' ); ?></span></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Core & Plugins', 'wm-activity-sentinel' ); ?></strong></td>
								<td><?php esc_html_e( 'activated_plugin, upgrader_process_complete und updated_option für ausgewählte Optionen (Notice); deactivated_plugin, delete_plugin und after_switch_theme (Warning)', 'wm-activity-sentinel' ); ?></td>
								<td><span class="wm-sentinel-badge wm-sentinel-badge-notice"><?php esc_html_e( 'Notice / Warning', 'wm-activity-sentinel' ); ?></span></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Exporte & Dateieditor', 'wm-activity-sentinel' ); ?></strong></td>
								<td><?php esc_html_e( 'Aufruf von export.php, load-plugin-editor.php und load-theme-editor.php (Warning); export_wp, wp_ajax_edit-theme-plugin-file, updraft_backup_started und duplicator_backup_started (Critical)', 'wm-activity-sentinel' ); ?></td>
								<td><span class="wm-sentinel-badge wm-sentinel-badge-critical"><?php esc_html_e( 'Warning / Critical', 'wm-activity-sentinel' ); ?></span></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Admin & Telemetrie', 'wm-activity-sentinel' ); ?></strong></td>
								<td><?php esc_html_e( 'current_screen, template_redirect und Klicks über POST /wm-sentinel/v1/telemetry (Info); rest_pre_dispatch für /wp/v2/plugins und anonyme Abfragen von /wp/v2/users (Warning)', 'wm-activity-sentinel' ); ?></td>
								<td><span class="wm-sentinel-badge wm-sentinel-badge-info"><?php esc_html_e( 'Info / Warning', 'wm-activity-sentinel' ); ?></span></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- CARD 3: Developer API & Code Snippets -->
			<div class="wm-card wm-sentinel-card">
				<h2>
					<span class="dashicons dashicons-code-standards" aria-hidden="true"></span>
					<?php esc_html_e( '3. Entwickler-API & Eigene Sensoren registrieren', 'wm-activity-sentinel' ); ?>
				</h2>
				<p><?php esc_html_e( 'Eigene Ereignisse lassen sich über die statische Logger-Klasse an die Kette anhängen:', 'wm-activity-sentinel' ); ?></p>
				<pre><code><span class="wm-code-cm">/**
 * Beispiel: Eigenes Sicherheitsereignis in WooCommerce oder Custom Plugin loggen
 */</span>
<span class="wm-code-kw">if</span> ( class_exists( <span class="wm-code-str">'WM_Sentinel_Logger'</span> ) ) {
    <span class="wm-code-str">WM_Sentinel_Logger</span>::<span class="wm-code-fn">log</span>(
        <span class="wm-code-var">$user_id</span>,                  <span class="wm-code-cm">// WordPress User-ID (oder null für anonyme Besucher)</span>
        <span class="wm-code-str">'custom_security_event'</span>,   <span class="wm-code-cm">// Event-Typ Kennung (z.B. 'payment_failed', 'api_key_rotated')</span>
        <span class="wm-code-str">'shop_order'</span>,              <span class="wm-code-cm">// Objekt-Typ (z.B. 'post', 'user', 'option', 'order')</span>
        <span class="wm-code-var">$order_id</span>,                 <span class="wm-code-cm">// Objekt-ID (Integer)</span>
        <span class="wm-code-str">'warning'</span>,                 <span class="wm-code-cm">// Schweregrad: 'info' | 'notice' | 'warning' | 'critical'</span>
        [                          <span class="wm-code-cm">// Kontextdaten (beliebiges Array, wird kanonisch formatiert)</span>
            <span class="wm-code-str">'amount'</span>   => <span class="wm-code-var">$amount</span>,
            <span class="wm-code-str">'gateway'</span>  => <span class="wm-code-str">'stripe'</span>,
            <span class="wm-code-str">'reason'</span>   => <span class="wm-code-str">'card_declined'</span>,
            <span class="wm-code-str">'attempts'</span> => <span class="wm-code-var">$attempt_count</span>,
        ]
    );
}</code></pre>
			</div>

			<!-- CARD 4: Gotchas & Troubleshooting -->
			<div class="wm-card wm-sentinel-card">
				<h2>
					<span class="dashicons dashicons-info" aria-hidden="true"></span>
					<?php esc_html_e( '4. Wichtige Hinweise, Gotchas & Troubleshooting', 'wm-activity-sentinel' ); ?>
				</h2>
				<ul class="wm-sentinel-list">
					<li><strong><?php esc_html_e( 'IP-Hashes:', 'wm-activity-sentinel' ); ?></strong> <?php esc_html_e( 'IP-Adressen werden als HMAC mit täglich wechselndem Schlüssel gespeichert; Hashes derselben Adresse stimmen nur innerhalb eines Tages (UTC) überein. Name und E-Mail des Benutzers stehen im Klartext.', 'wm-activity-sentinel' ); ?></li>
					<li><strong><?php esc_html_e( 'CSV-Export:', 'wm-activity-sentinel' ); ?></strong> <?php esc_html_e( 'Werte, die mit =, +, -, @, Tabulator oder Wagenrücklauf beginnen, erhalten ein vorangestelltes Apostroph, damit Tabellenprogramme sie nicht als Formel ausführen.', 'wm-activity-sentinel' ); ?></li>
					<li><strong><?php esc_html_e( 'WP-CLI Testausführung:', 'wm-activity-sentinel' ); ?></strong> <?php esc_html_e( 'In WP-CLI Tests liefert is_admin() immer true. Frontend-Tests setzen daher die Konstante WM_SENTINEL_TESTING_MODE.', 'wm-activity-sentinel' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}


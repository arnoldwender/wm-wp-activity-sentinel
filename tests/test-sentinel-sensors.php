<?php
/**
 * WM Activity Sentinel — Forensic Event Sensor & Hardening Test Suite
 *
 * WARNING: appends test entries to the activity log, which cannot be removed without breaking the chain.
 * Refuses to run without WM_SENTINEL_SCRATCH_INSTALL=1.
 *
 * Run via WP-CLI: WM_SENTINEL_SCRATCH_INSTALL=1 wp eval-file tests/test-sentinel-sensors.php --allow-root
 */

if ( ! defined( 'WM_SENTINEL_TESTING_MODE' ) ) {
	define( 'WM_SENTINEL_TESTING_MODE', true );
}

if ( ! defined( 'ABSPATH' ) ) {
	echo "ABSPATH not defined. Must be run within WordPress context via WP-CLI.\n";
	exit( 1 );
}

// The docblock warning did not stop runs against demo installs (2026-09-15, wm-hinschg-whistleblower); a gate does.
if ( '1' !== getenv( 'WM_SENTINEL_SCRATCH_INSTALL' ) ) {
	echo "Refusing to run: this test writes permanent entries to the activity log. On a scratch install: WM_SENTINEL_SCRATCH_INSTALL=1 wp eval-file ...\n";
	exit( 1 );
}

echo "\n===============================================================\n";
echo "WM ACTIVITY SENTINEL — SENSORS & COMPLIANCE TEST SUITE\n";
echo "Environment: " . PHP_OS . " | PHP " . PHP_VERSION . " | WP " . get_bloginfo( 'version' ) . "\n";
echo "===============================================================\n\n";

$pass_count = 0;
$total_tests = 11;

global $wpdb;
$logs_table = WM_Sentinel_Database::logs_table();
$sensors = new WM_Sentinel_Sensors();

// Reset all subsystem toggles and intensity to default for reliable test harness
update_option( 'wm_sentinel_logging_intensity', 'all' );
update_option( 'wm_sentinel_log_plugins', 1 );
update_option( 'wm_sentinel_log_content', 1 );
update_option( 'wm_sentinel_log_auth', 1 );
update_option( 'wm_sentinel_log_users', 1 );
update_option( 'wm_sentinel_monitor_screens', 1 );
update_option( 'wm_sentinel_monitor_frontend_views', 1 );
update_option( 'wm_sentinel_monitor_ui_interactions', 1 );
update_option( 'wm_sentinel_enable_dashboard_widget', 1 );

// -------------------------------------------------------------
// TEST 1: Native Export Sensor
// -------------------------------------------------------------
echo "[TEST 1/{$total_tests}] Native Export Sensor Trigger (export_wp)...\n";
$_GET['content'] = 'posts';
$sensors->on_native_export_triggered();

$latest_log = WM_Sentinel_Database::get_last_entry();
if ( $latest_log && 'export_wp_native_triggered' === $latest_log->event_type && 'critical' === $latest_log->severity ) {
	echo "  -> PASS: Export event recorded with critical severity.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Native export sensor did not record event properly.\n";
}

// -------------------------------------------------------------
// TEST 2: Plugin Activation / Deactivation Sensor
// -------------------------------------------------------------
echo "\n[TEST 2/{$total_tests}] Plugin Activation Sensor (activated_plugin)...\n";
$sensors->on_plugin_activated( 'test-sample-plugin/test.php', false );

$plugin_log = WM_Sentinel_Database::get_last_entry();
if ( $plugin_log && 'plugin_activated' === $plugin_log->event_type && 'notice' === $plugin_log->severity ) {
	echo "  -> PASS: Plugin activation captured with metadata: " . substr( $plugin_log->context, 0, 50 ) . "...\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Plugin activation sensor failed.\n";
}

// -------------------------------------------------------------
// TEST 3: Login Failed Sensor with IP Pseudonymization
// -------------------------------------------------------------
echo "\n[TEST 3/{$total_tests}] Failed Login Brute Force Sensor (wp_login_failed)...\n";
$_SERVER['REMOTE_ADDR'] = '198.51.100.44';
$_SERVER['HTTP_USER_AGENT'] = 'SecurityScanner/1.0';

$sensors->on_login_failed( 'unauthorized_attacker' );

$failed_log = WM_Sentinel_Database::get_last_entry();
if ( $failed_log && 'user_login_failed' === $failed_log->event_type && 'warning' === $failed_log->severity && 64 === strlen( $failed_log->ip_hmac ) ) {
	echo "  -> PASS: Failed login attempt captured with a daily-keyed IP HMAC: " . substr( $failed_log->ip_hmac, 0, 16 ) . "...\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Failed login sensor did not capture event.\n";
}

// -------------------------------------------------------------
// TEST 4: User Role Escalation to Administrator
// -------------------------------------------------------------
echo "\n[TEST 4/{$total_tests}] Privilege Escalation Sensor (set_user_role -> administrator)...\n";
$sensors->on_user_role_changed( 42, 'administrator', [ 'subscriber' ] );

$role_log = WM_Sentinel_Database::get_last_entry();
if ( $role_log && 'user_role_changed' === $role_log->event_type && 'critical' === $role_log->severity ) {
	echo "  -> PASS: Role escalation to administrator logged as CRITICAL security event.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Role escalation sensor failed.\n";
}

// -------------------------------------------------------------
// TEST 5: CWE-1236 Formula Injection Neutralization in CSV Exporter
// -------------------------------------------------------------
echo "\n[TEST 5/{$total_tests}] CWE-1236 Formula Injection Neutralization...\n";
$test_cases = [
	'=1+1'                 => '\'=1+1',
	'+cmd|\' /C calc\'!A0' => '\'+cmd|\' /C calc\'!A0',
	'-2+5'                 => '\'-2+5',
	'@SUM(1..10)'          => '\'@SUM(1..10)',
	"\tSECRET"             => "'\tSECRET",
	'Safe Event Name'      => 'Safe Event Name',
];

$cwe_ok = true;
foreach ( $test_cases as $raw => $expected ) {
	$clean = WM_Sentinel_Exporter::sanitize_csv_cell( $raw );
	if ( $clean !== $expected ) {
		$cwe_ok = false;
		echo "  -> ERROR: Expected '{$expected}', got '{$clean}'\n";
	}
}

if ( $cwe_ok ) {
	echo "  -> PASS: All formula injection characters sanitized with leading quote.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: CSV formula sanitizer vulnerability.\n";
}

// -------------------------------------------------------------
// TEST 6: Dashboard Widget Registration & Activation Setting
// -------------------------------------------------------------
echo "\n[TEST 6/{$total_tests}] Dashboard Widget Registration & Activation Setting...\n";
if ( ! function_exists( 'set_current_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}
if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
	require_once ABSPATH . 'wp-admin/includes/dashboard.php';
}
if ( ! function_exists( 'add_meta_box' ) ) {
	require_once ABSPATH . 'wp-admin/includes/template.php';
}

wp_set_current_user( 1 );
set_current_screen( 'dashboard' );
$admin = new WM_Sentinel_Admin();

// Test with setting enabled
update_option( 'wm_sentinel_enable_dashboard_widget', 1 );
global $wp_meta_boxes;
$wp_meta_boxes = [];
$admin->register_dashboard_widget();

$widget_registered_enabled = isset( $wp_meta_boxes['dashboard']['normal']['core']['wm_sentinel_dashboard_widget'] );

// Test with setting disabled
update_option( 'wm_sentinel_enable_dashboard_widget', 0 );
$wp_meta_boxes = [];
$admin->register_dashboard_widget();

$widget_registered_disabled = isset( $wp_meta_boxes['dashboard']['normal']['core']['wm_sentinel_dashboard_widget'] );

// Reset setting to 1
update_option( 'wm_sentinel_enable_dashboard_widget', 1 );

if ( $widget_registered_enabled && ! $widget_registered_disabled ) {
	echo "  -> PASS: Dashboard widget correctly registers when enabled and suppresses when disabled.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Dashboard widget toggle failure.\n";
}

// -------------------------------------------------------------
// TEST 7: Admin Screen Navigation Sensor (current_screen)
// -------------------------------------------------------------
echo "\n[TEST 7/{$total_tests}] Admin Screen Navigation Sensor (current_screen)...\n";
wp_set_current_user( 1 );
set_current_screen( 'plugins' );
$screen = get_current_screen();
$sensors->on_admin_screen_viewed( $screen );

$latest_screen_log = WM_Sentinel_Database::get_last_entry();
$screen_context = json_decode( $latest_screen_log->context ?? '{}', true );

if ( $latest_screen_log && 'admin_screen_viewed' === $latest_screen_log->event_type && 'plugins' === ( $screen_context['screen_id'] ?? '' ) ) {
	echo "  -> PASS: Admin navigation to screen '{$screen_context['screen_id']}' logged with title '{$screen_context['screen_title']}'.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Admin screen navigation logging failed.\n";
}

// -------------------------------------------------------------
// TEST 8: Frontend Page Navigation Sensor (template_redirect)
// -------------------------------------------------------------
echo "\n[TEST 8/{$total_tests}] Frontend Page Navigation Sensor (template_redirect)...\n";
wp_set_current_user( 1 );
$_SERVER['REQUEST_URI'] = '/prototype-demo-page/';
$_SERVER['HTTP_REFERER'] = 'https://arnoldwender.com/';
$sensors->on_frontend_page_viewed();

$latest_front_log = WM_Sentinel_Database::get_last_entry();
$front_context = json_decode( $latest_front_log->context ?? '{}', true );

if ( $latest_front_log && 'frontend_page_viewed' === $latest_front_log->event_type && '/prototype-demo-page/' === ( $front_context['uri'] ?? '' ) ) {
	echo "  -> PASS: Frontend prototype navigation logged with URI '{$front_context['uri']}'.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Frontend page navigation logging failed.\n";
}

// -------------------------------------------------------------
// TEST 9: Client Telemetry REST Beacon (Click & UI Interaction)
// -------------------------------------------------------------
echo "\n[TEST 9/{$total_tests}] Client Telemetry REST Beacon (Clicks & UI Interactions)...\n";
wp_set_current_user( 1 );

$telemetry_request = new WP_REST_Request( 'POST', '/wm-sentinel/v1/telemetry' );
$telemetry_request->set_header( 'content-type', 'application/json' );
$telemetry_request->set_body( wp_json_encode( [
	'events' => [
		[
			'event_type' => 'admin_menu_click',
			'category'   => 'navigation',
			'severity'   => 'info',
			'metadata'   => [
				'element_tag'        => 'A',
				'element_text'       => 'Plugins > Installieren',
				'element_href'       => 'plugin-install.php',
				'action_description' => 'Admin-Menü geklickt: Plugins > Installieren',
			],
		],
		[
			'event_type' => 'button_pressed',
			'category'   => 'action',
			'severity'   => 'info',
			'metadata'   => [
				'element_tag'        => 'BUTTON',
				'element_text'       => 'Änderungen speichern',
				'action_description' => 'Button betätigt: Änderungen speichern',
			],
		],
	],
] ) );

$telemetry_response = $sensors->handle_telemetry_beacon( $telemetry_request );
$telemetry_data = $telemetry_response->get_data();

$latest_click_log = WM_Sentinel_Database::get_last_entry();
$click_context = json_decode( $latest_click_log->context ?? '{}', true );

if ( 200 === $telemetry_response->get_status() && ( $telemetry_data['logged'] ?? 0 ) >= 2 && 'button_pressed' === $latest_click_log->event_type ) {
	echo "  -> PASS: Telemetry beacon successfully ingested {$telemetry_data['logged']} client interaction events into hash chain.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Client telemetry beacon ingestion failed.\n";
}

// -------------------------------------------------------------
// TEST 10: Logging Intensity Levels & Severity Thresholds
// -------------------------------------------------------------
echo "\n[TEST 10/{$total_tests}] Configurable Logging Intensity Enforcement...\n";

// Set intensity to 'strict' (allows only warning, critical)
update_option( 'wm_sentinel_logging_intensity', 'strict' );

$info_logged = WM_Sentinel_Logger::log( 1, 'test_info_event', 'system', null, 'info', [ 'test' => 'suppress_me' ] );
$notice_logged = WM_Sentinel_Logger::log( 1, 'test_notice_event', 'system', null, 'notice', [ 'test' => 'suppress_me' ] );
$warning_logged = WM_Sentinel_Logger::log( 1, 'test_warning_event', 'system', null, 'warning', [ 'test' => 'allow_me' ] );
$critical_logged = WM_Sentinel_Logger::log( 1, 'test_critical_event', 'system', null, 'critical', [ 'test' => 'allow_me' ] );

// Reset intensity back to 'all'
update_option( 'wm_sentinel_logging_intensity', 'all' );

if ( false === $info_logged && false === $notice_logged && is_int( $warning_logged ) && is_int( $critical_logged ) ) {
	echo "  -> PASS: Strict intensity correctly suppressed info/notice and accepted warning/critical.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Logging intensity filtering failed.\n";
}

// -------------------------------------------------------------
// TEST 11: Subsystem Module Toggles
// -------------------------------------------------------------
echo "\n[TEST 11/{$total_tests}] Subsystem Module Toggles (wm_sentinel_log_plugins & wm_sentinel_log_auth)...\n";

// Disable plugin logging
update_option( 'wm_sentinel_log_plugins', 0 );
$plugin_log_attempt = WM_Sentinel_Logger::log( 1, 'plugin_activated', 'plugin', null, 'info', [ 'plugin' => 'disabled-test.php' ] );

// Re-enable plugin logging
update_option( 'wm_sentinel_log_plugins', 1 );
$plugin_log_allowed = WM_Sentinel_Logger::log( 1, 'plugin_activated', 'plugin', null, 'info', [ 'plugin' => 'enabled-test.php' ] );

if ( false === $plugin_log_attempt && is_int( $plugin_log_allowed ) ) {
	echo "  -> PASS: Plugin module toggle dynamically enables and disables subsystem surveillance.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Subsystem module toggle failed.\n";
}

echo "\n-------------------------------------------------------------\n";
if ( $pass_count === $total_tests ) {
	echo "VERDICT: PASS ({$pass_count}/{$total_tests} Sensor & Compliance Tests Successful)\n";
} else {
	echo "VERDICT: FAIL ({$pass_count}/{$total_tests} Tests Passed)\n";
}
echo "-------------------------------------------------------------\n\n";

// The verdict was printed and the file exited 0 either way (until 2026-09-15).
exit( $pass_count === $total_tests ? 0 : 1 );

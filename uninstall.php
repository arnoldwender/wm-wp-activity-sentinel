<?php
/**
 * WM Suite Activity Sentinel — uninstaller.
 *
 * Deletes every option the plugin writes, unschedules the retention hook and DROPS both tables,
 * which destroys the whole audit trail. Whether it must be kept is an open decision (fleet audit
 * D6, same question as the consent vault and the privacy eraser).
 *
 * Until 2026-09-15 this file deleted eight options, four of them names nothing reads, and left twelve
 * settings the plugin actually uses in the database. The list below mirrors
 * WM_Sentinel_Database::default_options(), LEGACY_OPTIONS and TIP_OPTION (tests/test-suite.php
 * group 9 compares them).
 *
 * @package WM_Activity_Sentinel
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// A closure keeps every variable local: this file runs in the global scope.
( static function (): void {
	global $wpdb;

	$delete_all = 1 === (int) get_option( 'wm_sentinel_delete_data_on_uninstall', 0 );

	$options = [
		// Settings (default_options()).
		'wm_sentinel_logging_intensity',
		'wm_sentinel_telemetry_intensity',
		'wm_sentinel_log_auth',
		'wm_sentinel_log_users',
		'wm_sentinel_log_plugins',
		'wm_sentinel_log_content',
		'wm_sentinel_log_rest',
		'wm_sentinel_monitor_screens',
		'wm_sentinel_monitor_ui_interactions',
		'wm_sentinel_monitor_frontend_views',
		'wm_sentinel_alert_email',
		'wm_sentinel_alert_min_severity',
		'wm_sentinel_webhook_url',
		'wm_sentinel_retention_days',
		'wm_sentinel_enable_dashboard_widget',
		// SIEM forwarding (class-sentinel-forwarder.php).
		'wm_sentinel_siem_enabled',
		'wm_sentinel_siem_format',
		'wm_sentinel_siem_transport',
		'wm_sentinel_siem_host',
		'wm_sentinel_siem_port',
		'wm_sentinel_siem_url',
		'wm_sentinel_siem_min_severity',
		// File integrity monitor settings (class-sentinel-fim.php).
		'wm_sentinel_fim_enabled',
		'wm_sentinel_fim_scan_suite',
		// Uninstall retention opt-in (default_options()).
		'wm_sentinel_delete_data_on_uninstall',
		// Written by earlier versions, read by nothing (LEGACY_OPTIONS).
		'wm_sentinel_monitor_rest',
		'wm_sentinel_monitor_files',
		'wm_sentinel_monitor_exports',
		'wm_sentinel_modules',
		'wm_sentinel_logging_level',
		'wm_sentinel_daily_salt',
		'wm_sentinel_salt_date',
		'wm_sentinel_settings',
	];

	// Audit trail state: the stored FIM baseline and last report plus the chain tip mirror.
	// Preserved alongside the tables unless the operator opted into full deletion on uninstall.
	$audit_state_options = [
		'wm_sentinel_fim_baseline',
		'wm_sentinel_fim_last_scan',
		'wm_sentinel_chain_tip',
		'wm_sentinel_db_version',
	];

	if ( $delete_all ) {
		$options = array_merge( $options, $audit_state_options );
	}

	foreach ( $options as $option ) {
		delete_option( $option );
		delete_site_option( $option );
	}

	// Telemetry rate-limit counters (WM_Sentinel_Sensors: wm_sentinel_telemetry_<user>_<minute>); they expire
	// after two minutes, but uninstall should not leave them behind. The cached checksum catalogues
	// (wm_sentinel_fim_catalogue_<hash>) are hundreds of kilobytes each and expire after 12 hours.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_wm_sentinel_telemetry_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wm_sentinel_telemetry_' ) . '%',
			$wpdb->esc_like( '_transient_wm_sentinel_fim_catalogue_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wm_sentinel_fim_catalogue_' ) . '%'
		)
	);

	// Earlier versions cleared retention_purge on deactivation; nothing schedules it today.
	wp_clear_scheduled_hook( 'wm_sentinel_retention_purge' );
	wp_clear_scheduled_hook( 'wm_sentinel_fim_scan' );
	wp_clear_scheduled_hook( 'wm_sentinel_apply_retention' );

	if ( $delete_all ) {
		$logs_table   = $wpdb->prefix . 'wm_activity_logs';
		$alerts_table = $wpdb->prefix . 'wm_activity_alerts';

		$wpdb->query( "DROP TABLE IF EXISTS `{$alerts_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- table names from $wpdb->prefix.
		$wpdb->query( "DROP TABLE IF EXISTS `{$logs_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- table names from $wpdb->prefix.
	}
} )();

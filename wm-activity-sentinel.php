<?php
/**
 * Plugin Name:       WM Activity Sentinel
 * Plugin URI:        https://arnoldwender.com/wordpress-plugins/wm-activity-sentinel/
 * Description:       Aktivitätsprotokoll mit HMAC-SHA256-Hashkette: Anmeldungen, Benutzer und Rollen, Plugins und Themes, Exporte, Dateieditor, ausgewählte REST-Routen, Seitenaufrufe und Klicks angemeldeter Benutzer; Prüfung der Kette, E-Mail- und Webhook-Alarme, CSV- und JSON-Export. Manipulation wird nachweisbar, nicht verhindert.
 * Version:           1.4.3
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      8.2
 * Author:            Arnold Wender
 * Author URI:        https://arnoldwender.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wm-activity-sentinel
 * Domain Path:       /languages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Global Plugin Constants
define( 'WM_SENTINEL_VERSION', '1.4.3' );
define( 'WM_SENTINEL_FILE', __FILE__ );
define( 'WM_SENTINEL_PATH', plugin_dir_path( __FILE__ ) );
define( 'WM_SENTINEL_URL', plugin_dir_url( __FILE__ ) );
define( 'WM_SENTINEL_BASENAME', plugin_basename( __FILE__ ) );

// Require Subsystems
require_once WM_SENTINEL_PATH . 'includes/class-sentinel-database.php';
require_once WM_SENTINEL_PATH . 'includes/class-sentinel-logger.php';
require_once WM_SENTINEL_PATH . 'includes/class-sentinel-sensors.php';
require_once WM_SENTINEL_PATH . 'includes/class-sentinel-integrity.php';
require_once WM_SENTINEL_PATH . 'includes/class-sentinel-fim.php';
require_once WM_SENTINEL_PATH . 'includes/class-sentinel-alerts.php';
require_once WM_SENTINEL_PATH . 'includes/class-sentinel-forwarder.php';
require_once WM_SENTINEL_PATH . 'includes/class-sentinel-exporter.php';
require_once WM_SENTINEL_PATH . 'includes/class-sentinel-retention.php';
require_once WM_SENTINEL_PATH . 'includes/class-sentinel-seeder.php';
require_once WM_SENTINEL_PATH . 'includes/class-sentinel-admin.php';
require_once WM_SENTINEL_PATH . 'includes/class-sentinel-cli.php';
require_once WM_SENTINEL_PATH . 'includes/class-sentinel-core.php';

/**
 * Register as Spoke in WM Suite Hub
 */
add_filter( 'wm_register_suite_module', function ( array $modules ): array {
	if ( ! class_exists( 'WM_Sentinel_Spoke_Adapter' ) && file_exists( WM_SENTINEL_PATH . 'includes/class-sentinel-spoke-adapter.php' ) ) {
		require_once WM_SENTINEL_PATH . 'includes/class-sentinel-spoke-adapter.php';
	}
	if ( class_exists( 'WM_Sentinel_Spoke_Adapter' ) ) {
		$modules[] = new WM_Sentinel_Spoke_Adapter();
	}
	return $modules;
} );

/**
 * Activation Lifecycle Hook
 */
register_activation_hook( __FILE__, static function ( $network_wide = false ): void {
	WM_Sentinel_Core::activate( (bool) $network_wide );
} );

/**
 * Provision new Multisite blogs when the plugin is network-activated.
 */
add_action( 'wp_initialize_site', [ 'WM_Sentinel_Core', 'on_initialize_site' ], 20, 1 );

/**
 * Deactivation Lifecycle Hook
 */
register_deactivation_hook( __FILE__, function () {
	// 'wm_sentinel_retention_purge' was never scheduled by any version of this plugin: it is the
	// leftover of a purge that was planned and never written. Cleared anyway, in case an older
	// install of some other origin left one behind.
	wp_clear_scheduled_hook( 'wm_sentinel_retention_purge' );
	wp_clear_scheduled_hook( WM_Sentinel_FIM::CRON_HOOK );
	wp_clear_scheduled_hook( WM_Sentinel_Retention::CRON_HOOK );
} );

/**
 * The scheduled file integrity scan.
 */
add_action( WM_Sentinel_FIM::CRON_HOOK, [ 'WM_Sentinel_FIM', 'run_scheduled' ] );

/**
 * The scheduled retention pass.
 */
add_action( WM_Sentinel_Retention::CRON_HOOK, [ 'WM_Sentinel_Retention', 'run_scheduled' ] );

/**
 * Schedule the retention pass on every load, not only on activation.
 *
 * Measured on a local WordPress install on 2026-09-17: after deploying the feature to an install where the plugin
 * was ALREADY ACTIVE, wm_nda_apply_retention was scheduled and wm_sentinel_apply_retention was not
 * — because an active plugin never runs its activation hook again. The retention would have
 * arrived dead on every existing site, which is all of them.
 *
 * sync_schedule() only reads the setting and asks wp_next_scheduled(), so running it on
 * plugins_loaded costs one option read and nothing else. It also means changing the setting with
 * `wp option update` (not only through the form) takes effect on the next request.
 */
add_action( 'plugins_loaded', [ 'WM_Sentinel_Retention', 'sync_schedule' ], 5 );

/**
 * Internationalization & Text Domain Loading
 */
add_action( 'init', function () {
	load_plugin_textdomain( 'wm-activity-sentinel', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/**
 * Bootstrap Plugin Core Instance
 */
add_action( 'plugins_loaded', function () {
	WM_Sentinel_Core::get_instance();
} );

/**
 * Plugin Action Links in wp-admin/plugins.php
 */
add_filter( 'plugin_action_links_' . WM_SENTINEL_BASENAME, function ( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=wm-sentinel-settings' ) ),
		esc_html__( 'Einstellungen', 'wm-activity-sentinel' )
	);
	$docs_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=wm-sentinel-docs' ) ),
		esc_html__( 'Handbuch', 'wm-activity-sentinel' )
	);
	$audit_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=wm-sentinel-integrity' ) ),
		esc_html__( 'Integritäts-Audit', 'wm-activity-sentinel' )
	);
	return array_merge( [ $settings_link, $docs_link, $audit_link ], $links );
} );

/**
 * Plugin Row Meta in wp-admin/plugins.php
 */
add_filter( 'plugin_row_meta', function ( array $links, string $file ): array {
	if ( WM_SENTINEL_BASENAME === $file ) {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wm-sentinel-docs' ) ),
			esc_html__( 'Handbuch', 'wm-activity-sentinel' )
		);
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wm-sentinel-export' ) ),
			esc_html__( 'Export', 'wm-activity-sentinel' )
		);
	}
	return $links;
}, 10, 2 );

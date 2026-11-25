<?php
/**
 * WM Suite Activity Sentinel — Main Singleton Core Orchestrator
 *
 * @package WM_Activity_Sentinel
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Sentinel_Core {

	/**
	 * Singleton instance
	 */
	private static ?self $instance = null;

	/**
	 * Subsystems
	 */
	public WM_Sentinel_Sensors $sensors;
	public WM_Sentinel_Admin $admin;

	/**
	 * Get singleton instance
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset singleton instance (used by deterministic test suite).
	 */
	public static function reset_instance(): void {
		self::$instance = null;
	}

	/**
	 * Private constructor
	 */
	private function __construct() {
		$this->sensors = new WM_Sentinel_Sensors();
		$this->admin   = new WM_Sentinel_Admin();

		$this->init();
	}

	/**
	 * Initialize subsystems
	 */
	private function init(): void {
		WM_Sentinel_Database::maybe_upgrade();
		WM_Sentinel_FIM::sync_schedule();

		// Register the sensor hooks
		$this->sensors->init_sensors();

		// Register WordPress Core Privacy Tools (DSGVO Art. 17 Exporter & Eraser)
		add_filter( 'wp_privacy_personal_data_exporters', [ 'WM_Sentinel_Exporter', 'register_privacy_exporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ 'WM_Sentinel_Exporter', 'register_privacy_erasers' ] );

		// Register WP 6.5+ Block Bindings source and GenerateBlocks 2.x Dynamic Tag
		add_action( 'init', [ self::class, 'register_block_bindings' ] );
		add_action( 'init', [ self::class, 'register_generateblocks_tags' ], 20 );
		add_filter( 'generateblocks_dynamic_tag_replacement', [ self::class, 'filter_generateblocks_tag_output' ], 10, 2 );

		// Register Admin Dashboard if in admin context
		if ( is_admin() ) {
			$this->admin->init_hooks();
		}

		/**
		 * Fires once WM Activity Sentinel has registered its sensors and core hooks.
		 *
		 * @since 1.3.1
		 * @param self $core Active singleton instance.
		 */
		do_action( 'wm_sentinel_ready', $this );
	}

	/**
	 * Non-sensitive audit status and counters exposed to Block Bindings and GenerateBlocks Dynamic Tags.
	 *
	 * @return array<string, string|int>
	 */
	public static function get_public_metrics(): array {
		$kpis  = WM_Sentinel_Database::get_kpis();
		$audit = WM_Sentinel_Integrity::verify_chain();

		$metrics = [
			'chain_status'    => ! empty( $audit['valid'] ) ? 'INTACT' : 'BROKEN',
			'verified_blocks' => (int) ( $audit['total_verified'] ?? 0 ),
			'total_events'    => (int) ( $kpis['total_logs'] ?? 0 ),
			'version'         => defined( 'WM_SENTINEL_VERSION' ) ? (string) WM_SENTINEL_VERSION : '1.3.5',
		];

		/**
		 * Filters the non-sensitive metrics exposed to Block Bindings and GenerateBlocks Dynamic Tags.
		 *
		 * @since 1.3.1
		 * @param array<string, string|int> $metrics Non-sensitive status and counter map.
		 */
		$filtered = apply_filters( 'wm_sentinel_public_metrics', $metrics );

		return is_array( $filtered ) ? $filtered : $metrics;
	}

	/**
	 * Register WordPress 6.5+ Block Bindings source `wm-sentinel/metrics`.
	 */
	public static function register_block_bindings(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source(
			'wm-sentinel/metrics',
			[
				'label'              => __( 'WM Activity Sentinel Kennzahlen', 'wm-activity-sentinel' ),
				'get_value_callback' => [ self::class, 'resolve_block_binding_value' ],
				'uses_context'       => [],
			]
		);
	}

	/**
	 * Resolve a bound block attribute from `wm-sentinel/metrics`.
	 *
	 * @param array<string, mixed> $source_args Binding arguments (`key`).
	 */
	public static function resolve_block_binding_value( array $source_args ): ?string {
		$key = isset( $source_args['key'] ) && is_scalar( $source_args['key'] )
			? sanitize_key( (string) $source_args['key'] )
			: 'chain_status';

		$metrics = self::get_public_metrics();
		if ( ! array_key_exists( $key, $metrics ) ) {
			return null;
		}

		return (string) $metrics[ $key ];
	}

	/**
	 * Register GenerateBlocks 2.x Dynamic Tag `wm_sentinel_metric`.
	 */
	public static function register_generateblocks_tags(): void {
		if ( ! class_exists( 'GenerateBlocks_Register_Dynamic_Tag' ) ) {
			return;
		}

		new GenerateBlocks_Register_Dynamic_Tag(
			[
				'title'       => __( 'WM Sentinel Kennzahl', 'wm-activity-sentinel' ),
				'tag'         => 'wm_sentinel_metric',
				'type'        => 'wm-activity-sentinel',
				'supports'    => [],
				'description' => __( 'Gibt nicht-sensible Prüfstatus- und Protokollzähler von WM Activity Sentinel aus.', 'wm-activity-sentinel' ),
				'options'     => [
					'key' => [
						'type'    => 'select',
						'label'   => __( 'Kennzahl', 'wm-activity-sentinel' ),
						'default' => 'chain_status',
						'options' => [
							[
								'label' => __( 'Kettenstatus (INTACT / BROKEN)', 'wm-activity-sentinel' ),
								'value' => 'chain_status',
							],
							[
								'label' => __( 'Geprüfte Einträge', 'wm-activity-sentinel' ),
								'value' => 'verified_blocks',
							],
							[
								'label' => __( 'Gesamt-Einträge', 'wm-activity-sentinel' ),
								'value' => 'total_events',
							],
							[
								'label' => __( 'Plugin-Version', 'wm-activity-sentinel' ),
								'value' => 'version',
							],
						],
					],
				],
				'return'      => [ self::class, 'render_generateblocks_tag' ],
			]
		);
	}

	/**
	 * Render callback for GenerateBlocks 2.x Dynamic Tag `wm_sentinel_metric`.
	 *
	 * @param array<string, mixed> $options  Tag options (`key`).
	 * @param array<string, mixed> $block    Block attributes.
	 * @param mixed                $instance Block instance.
	 */
	public static function render_generateblocks_tag( array $options = [], array $block = [], mixed $instance = null ): string {
		$key = isset( $options['key'] ) && is_scalar( $options['key'] )
			? sanitize_key( (string) $options['key'] )
			: 'chain_status';

		$metrics = self::get_public_metrics();
		if ( ! array_key_exists( $key, $metrics ) ) {
			return '';
		}

		return esc_html( (string) $metrics[ $key ] );
	}

	/**
	 * Filter GenerateBlocks dynamic tag replacement when `wm_sentinel_metric` is evaluated.
	 *
	 * @param mixed $output         Current replacement/output.
	 * @param mixed $tag_data       Parsed GenerateBlocks tag descriptor or legacy tag identifier.
	 * @param mixed $options_or_raw Optional legacy options array or raw scalar output.
	 */
	public static function filter_generateblocks_tag_output( mixed $output, mixed $tag_data = null, mixed $options_or_raw = null ): mixed {
		$tag     = '';
		$options = [];
		if ( is_string( $tag_data ) ) {
			$tag     = $tag_data;
			$options = is_array( $options_or_raw ) ? $options_or_raw : [];
		} elseif ( is_array( $tag_data ) ) {
			$tag     = (string) ( $tag_data['tag'] ?? $tag_data['name'] ?? '' );
			$options = isset( $tag_data['options'] ) && is_array( $tag_data['options'] ) ? $tag_data['options'] : [];
		}

		if ( 'wm_sentinel_metric' !== $tag ) {
			return $output;
		}

		return self::render_generateblocks_tag( $options );
	}

	/**
	 * Provision tables, genesis block, default options, and schedules for the active blog.
	 */
	public static function activate_site(): void {
		WM_Sentinel_Database::create_tables();
		WM_Sentinel_Database::ensure_genesis_block();

		// Default settings, only where none is stored yet.
		foreach ( WM_Sentinel_Database::default_options() as $option => $value ) {
			if ( false === get_option( $option ) ) {
				update_option( $option, $value, WM_Sentinel_Database::should_autoload_option( $option ) );
			}
		}

		// The file integrity monitor is only "continuous" if something runs it; the schedule follows the
		// setting, so switching it off later also removes the event.
		WM_Sentinel_FIM::sync_schedule();

		// Same reasoning for retention: a stated Aufbewahrungsfrist that nothing applies is not a
		// storage-limitation control. Until 2026-09-17 nothing applied it.
		WM_Sentinel_Retention::sync_schedule();
	}

	/**
	 * Plugin activation handler supporting single-site and network-wide multisite activation.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( function_exists( 'is_multisite' ) && is_multisite() && $network_wide && function_exists( 'get_sites' ) ) {
			$site_ids = get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			);
			foreach ( $site_ids as $site ) {
				$blog_id = is_object( $site ) && isset( $site->blog_id ) ? (int) $site->blog_id : (int) $site;
				self::provision_blog( $blog_id );
			}
			return;
		}

		self::activate_site();
	}

	/**
	 * Provision a newly initialized Multisite blog when the plugin is network-activated.
	 *
	 * @param mixed $new_site New site object (WP_Site) or blog ID.
	 */
	public static function on_initialize_site( mixed $new_site ): void {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$basename = defined( 'WM_SENTINEL_BASENAME' )
			? (string) WM_SENTINEL_BASENAME
			: ( function_exists( 'plugin_basename' ) && defined( 'WM_SENTINEL_FILE' ) ? plugin_basename( WM_SENTINEL_FILE ) : 'wm-activity-sentinel/wm-activity-sentinel.php' );

		if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( $basename ) ) {
			return;
		}

		$blog_id = is_object( $new_site ) && isset( $new_site->blog_id ) ? (int) $new_site->blog_id : (int) $new_site;
		self::provision_blog( $blog_id );
	}

	/**
	 * Switch into a multisite blog, provision Sentinel state, and always restore the blog context.
	 */
	private static function provision_blog( int $blog_id ): void {
		if ( $blog_id <= 0 || ! function_exists( 'switch_to_blog' ) || ! function_exists( 'restore_current_blog' ) ) {
			return;
		}

		switch_to_blog( $blog_id );
		try {
			self::activate_site();
		} finally {
			restore_current_blog();
		}
	}
}

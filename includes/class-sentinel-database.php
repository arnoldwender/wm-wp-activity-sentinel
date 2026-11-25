<?php
/**
 * WM Suite Activity Sentinel — Database Layer
 *
 * @package WM_Activity_Sentinel
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Sentinel_Database {

	/**
	 * Table name for the append-only activity log
	 */
	/** Mirrors the chain tip outside the log table so tail truncation is detectable. */
	public const TIP_OPTION        = 'wm_sentinel_chain_tip';
	public const DB_VERSION_OPTION = 'wm_sentinel_db_version';
	public const SCHEMA_VERSION    = 2;

	/**
	 * Settings the plugin reads, with the values activation and the factory reset write.
	 *
	 * Until 2026-09-15 activation wrote wm_sentinel_monitor_rest / _files / _exports, which nothing
	 * reads, and the factory reset wrote wm_sentinel_logging_intensity 'high' (not a level the logger
	 * knows) and wm_sentinel_modules (never read) while leaving every real switch as it was.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_options(): array {
		return [
			'wm_sentinel_logging_intensity'       => 'all',
			'wm_sentinel_telemetry_intensity'     => 'all',
			'wm_sentinel_log_auth'                => 1,
			'wm_sentinel_log_users'               => 1,
			'wm_sentinel_log_plugins'             => 1,
			'wm_sentinel_log_content'             => 1,
			'wm_sentinel_log_rest'                => 1,
			'wm_sentinel_monitor_screens'         => 1,
			'wm_sentinel_monitor_ui_interactions' => 1,
			'wm_sentinel_monitor_frontend_views'  => 1,
			'wm_sentinel_alert_email'             => (string) get_option( 'admin_email' ),
			'wm_sentinel_alert_min_severity'      => 'critical',
			'wm_sentinel_webhook_url'             => '',
			'wm_sentinel_retention_days'          => 90,
			'wm_sentinel_enable_dashboard_widget' => 1,
			// SIEM forwarding: off until a collector is configured, and then only what reaches the
			// minimum severity. Read in class-sentinel-forwarder.php.
			'wm_sentinel_siem_enabled'            => 0,
			'wm_sentinel_siem_format'             => 'cef',
			'wm_sentinel_siem_transport'          => 'https',
			'wm_sentinel_siem_host'               => '',
			'wm_sentinel_siem_port'               => 514,
			'wm_sentinel_siem_url'                => '',
			'wm_sentinel_siem_min_severity'       => 'warning',
			// File integrity monitor. On by default: the only thing that leaves the site is the
			// version and locale in a query string to api.wordpress.org, which WordPress already
			// sends on every update check, and a monitor nobody switches on monitors nothing.
			// Read in class-sentinel-fim.php.
			'wm_sentinel_fim_enabled'             => 1,
			'wm_sentinel_fim_scan_suite'          => 1,
			// Uninstall behaviour: 0 preserves the chained audit tables, chain tip mirror and sealed FIM
			// baseline when the plugin is deleted; 1 drops them explicitly on operator opt-in.
			'wm_sentinel_delete_data_on_uninstall' => 0,
		];
	}

	/** Options earlier versions wrote that nothing reads. */
	public const LEGACY_OPTIONS = [
		'wm_sentinel_monitor_rest',
		'wm_sentinel_monitor_files',
		'wm_sentinel_monitor_exports',
		'wm_sentinel_modules',
		'wm_sentinel_logging_level',
		'wm_sentinel_daily_salt',
		'wm_sentinel_salt_date',
		'wm_sentinel_settings',
	];

	/**
	 * Options evaluated on ordinary frontend page loads and kept autoloaded.
	 *
	 * @var list<string>
	 */
	public const FRONTEND_AUTOLOAD_OPTIONS = [
		'wm_sentinel_logging_intensity',
		'wm_sentinel_telemetry_intensity',
		'wm_sentinel_monitor_ui_interactions',
		'wm_sentinel_monitor_frontend_views',
	];

	/**
	 * Whether a plugin option should be autoloaded into wp_options.
	 */
	public static function should_autoload_option( string $option ): bool {
		return in_array( $option, self::FRONTEND_AUTOLOAD_OPTIONS, true );
	}

	public static function logs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wm_activity_logs';
	}

	/**
	 * Table name for real-time security alerts
	 */
	public static function alerts_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wm_activity_alerts';
	}

	/**
	 * Create or update InnoDB database tables via dbDelta
	 */
	public static function create_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$logs_table = self::logs_table();
		$alerts_table = self::alerts_table();

		$sql_logs = "CREATE TABLE {$logs_table} (
			log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NULL,
			user_name VARCHAR(100) NOT NULL DEFAULT 'System',
			user_email VARCHAR(100) NOT NULL DEFAULT '',
			event_type VARCHAR(100) NOT NULL,
			object_type VARCHAR(50) NOT NULL,
			object_id BIGINT UNSIGNED NULL,
			severity ENUM('info', 'notice', 'warning', 'critical') NOT NULL DEFAULT 'info',
			context LONGTEXT NULL,
			ip_hmac VARCHAR(64) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			prev_hash VARCHAR(64) NOT NULL,
			entry_hash VARCHAR(64) NOT NULL,
			tsa_timestamp TEXT NULL,
			PRIMARY KEY  (log_id),
			KEY user_time_idx (user_id, created_at),
			KEY severity_idx (severity),
			KEY event_type_idx (event_type),
			KEY created_at_idx (created_at)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_alerts = "CREATE TABLE {$alerts_table} (
			alert_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			log_id BIGINT UNSIGNED NOT NULL,
			severity ENUM('warning', 'critical') NOT NULL,
			alert_message TEXT NOT NULL,
			channel VARCHAR(50) NOT NULL,
			status ENUM('sent', 'failed', 'pending') NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (alert_id),
			KEY log_id_idx (log_id),
			KEY status_idx (status)
		) ENGINE=InnoDB {$charset_collate};";

		if ( ! function_exists( 'dbDelta' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql_logs );
			dbDelta( $sql_alerts );
			update_option( self::DB_VERSION_OPTION, self::SCHEMA_VERSION, false );
		}
	}

	/**
	 * Bring tables forward on already-active installs where activation does not re-run.
	 */
	public static function maybe_upgrade(): void {
		if ( self::SCHEMA_VERSION <= (int) get_option( self::DB_VERSION_OPTION, 0 ) ) {
			return;
		}
		self::create_tables();
	}

	/**
	 * Initialize the Genesis block for the hash chain if table is empty
	 */
	public static function ensure_genesis_block(): void {
		global $wpdb;
		$logs_table = self::logs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $logs_table ) );
		if ( $count > 0 ) {
			return;
		}

		$prev_hash = 'GENESIS';
		$timestamp = current_time( 'mysql', true );
		$context_json = (string) wp_json_encode( [
			'message'     => 'Genesis block initialized by WM Suite Activity Sentinel',
			'version'     => WM_SENTINEL_VERSION,
			'environment' => PHP_OS . ' | WP ' . get_bloginfo( 'version' ),
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$ip_hmac = WM_Sentinel_Logger::generate_ip_hmac( '127.0.0.1' );
		$entry_hash = WM_Sentinel_Logger::compute_entry_hash(
			$prev_hash,
			null,
			'System',
			'',
			'genesis_initialized',
			'system',
			null,
			'info',
			$context_json,
			$ip_hmac,
			$timestamp
		);

		$wpdb->insert(
			$logs_table,
			[
				'user_id'      => null,
				'user_name'    => 'System',
				'user_email'   => '',
				'event_type'   => 'genesis_initialized',
				'object_type'  => 'system',
				'object_id'    => null,
				'severity'     => 'info',
				'context'      => $context_json,
				'ip_hmac'      => $ip_hmac,
				'created_at'   => $timestamp,
				'prev_hash'    => $prev_hash,
				'entry_hash'   => $entry_hash,
			]
		);

		self::record_chain_tip( $entry_hash, 1 );
	}

	/**
	 * Retrieve the latest log entry in the chain (standard read)
	 */
	public static function get_last_entry(): ?object {
		global $wpdb;
		$logs_table = self::logs_table();
		return $wpdb->get_row( "SELECT * FROM {$logs_table} ORDER BY log_id DESC LIMIT 1" );
	}

	/**
	 * Retrieve the latest log entry with an exclusive InnoDB row-level lock (FOR UPDATE)
	 * Guarantees zero hash-chain forks under high concurrency
	 */
	public static function get_last_entry_for_update(): ?object {
		global $wpdb;
		$logs_table = self::logs_table();
		return $wpdb->get_row( "SELECT * FROM {$logs_table} ORDER BY log_id DESC LIMIT 1 FOR UPDATE" );
	}

	/**
	 * Fetch paginated and filtered activity logs
	 */
	public static function get_logs( array $args = [] ): array {
		global $wpdb;
		$logs_table = self::logs_table();

		$defaults = [
			'event_type'  => '',
			'severity'    => '',
			'object_type' => '',
			'user_id'     => '',
			'search'      => '',
			'date_from'   => '',
			'date_to'     => '',
			'limit'       => 50,
			'offset'      => 0,
			'orderby'     => 'log_id',
			'order'       => 'DESC',
		];
		$r = wp_parse_args( $args, $defaults );

		$where = [ '1=1' ];
		$values = [];

		if ( ! empty( $r['event_type'] ) ) {
			$where[] = 'event_type = %s';
			$values[] = $r['event_type'];
		}

		if ( ! empty( $r['severity'] ) ) {
			$where[] = 'severity = %s';
			$values[] = $r['severity'];
		}

		if ( ! empty( $r['object_type'] ) ) {
			$where[] = 'object_type = %s';
			$values[] = $r['object_type'];
		}

		if ( '' !== $r['user_id'] ) {
			$where[] = 'user_id = %d';
			$values[] = (int) $r['user_id'];
		}

		if ( ! empty( $r['search'] ) ) {
			$where[] = '(user_name LIKE %s OR user_email LIKE %s OR context LIKE %s OR event_type LIKE %s)';
			$like = '%' . $wpdb->esc_like( $r['search'] ) . '%';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		if ( ! empty( $r['date_from'] ) ) {
			$where[] = 'created_at >= %s';
			$values[] = $r['date_from'] . ' 00:00:00';
		}

		if ( ! empty( $r['date_to'] ) ) {
			$where[] = 'created_at <= %s';
			$values[] = $r['date_to'] . ' 23:59:59';
		}

		$where_clause = implode( ' AND ', $where );
		$allowed_orders = [ 'log_id', 'created_at', 'severity', 'user_name' ];
		$orderby = in_array( $r['orderby'], $allowed_orders, true ) ? $r['orderby'] : 'log_id';
		$order = ( 'ASC' === strtoupper( $r['order'] ) ) ? 'ASC' : 'DESC';

		// Every dynamic part is a whitelisted literal (orderby, order), a literal fragment with
		// placeholders (where), or a placeholder value; the table name goes through %i.
		$sql = "SELECT * FROM %i WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		array_unshift( $values, $logs_table );
		$values[] = $r['limit'];
		$values[] = $r['offset'];

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- assembled from literals and placeholders above; all values pass through prepare().
		return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Count total filtered logs
	 */
	public static function get_logs_count( array $args = [] ): int {
		global $wpdb;
		$logs_table = self::logs_table();

		$defaults = [
			'event_type'  => '',
			'severity'    => '',
			'object_type' => '',
			'user_id'     => '',
			'search'      => '',
		];
		$r = wp_parse_args( $args, $defaults );

		$where = [ '1=1' ];
		$values = [];

		if ( ! empty( $r['event_type'] ) ) {
			$where[] = 'event_type = %s';
			$values[] = $r['event_type'];
		}

		if ( ! empty( $r['severity'] ) ) {
			$where[] = 'severity = %s';
			$values[] = $r['severity'];
		}

		if ( ! empty( $r['object_type'] ) ) {
			$where[] = 'object_type = %s';
			$values[] = $r['object_type'];
		}

		if ( '' !== $r['user_id'] ) {
			$where[] = 'user_id = %d';
			$values[] = (int) $r['user_id'];
		}

		if ( ! empty( $r['search'] ) ) {
			$where[] = '(user_name LIKE %s OR user_email LIKE %s OR context LIKE %s OR event_type LIKE %s)';
			$like = '%' . $wpdb->esc_like( $r['search'] ) . '%';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$where_clause = implode( ' AND ', $where );
		if ( empty( $values ) ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table}" );
		}

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE {$where_clause}", $values ) );
	}

	/**
	 * Compute Dashboard Key Performance Indicators (KPIs)
	 */
	public static function get_kpis(): array {
		global $wpdb;
		$logs_table = self::logs_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_logs = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $logs_table ) );

		$critical_24h = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE severity = %s AND created_at >= %s',
				$logs_table,
				'critical',
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);

		$warning_24h = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE severity = %s AND created_at >= %s',
				$logs_table,
				'warning',
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);

		$distinct_users = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT user_id) FROM %i WHERE user_id > %d',
				$logs_table,
				0
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return [
			'total_logs'     => $total_logs,
			'critical_24h'   => $critical_24h,
			'warning_24h'    => $warning_24h,
			'distinct_users' => $distinct_users,
		];
	}

	/**
	 * Reset tables to factory defaults (Truncate and re-seed Genesis block)
	 */
	public static function reset_tables(): void {
		global $wpdb;
		$logs_table = self::logs_table();
		$alerts_table = self::alerts_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$logs_table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$alerts_table}" );

		// A reset is not laundering: the chain visibly restarts at genesis and the mirror has to
		// follow, or every later audit would report a break that the operator caused on purpose.
		delete_option( self::TIP_OPTION );
		self::ensure_genesis_block();
	}

	/**
	 * Record the current chain tip outside the log table.
	 *
	 * Without this, deleting the newest rows produces a chain that verifies perfectly: every
	 * remaining link still matches its predecessor, and nothing knows the chain used to be
	 * longer. Measured on a clean install, dropping the two most recent entries left
	 * verify_chain() reporting valid=true. For an audit log that is the whole attack — an
	 * intruder truncates what they just did.
	 *
	 * The mirror lives in wp_options, so removing the evidence now takes a second, separate
	 * write to a different table. That is a real raise, not a wall: whoever can write both can
	 * still do it, and the developer guide says so rather than implying otherwise.
	 *
	 * @param string $entry_hash Newest entry hash.
	 * @param int    $count      Number of rows the chain should hold.
	 * @return void
	 */
	public static function record_chain_tip( string $entry_hash, int $count ): void {
		update_option( self::TIP_OPTION, array( 'hash' => $entry_hash, 'count' => $count ), false );
	}

	/**
	 * The recorded tip, or null when none has been written yet.
	 *
	 * @return array{hash:string, count:int}|null
	 */
	public static function get_chain_tip(): ?array {
		$tip = get_option( self::TIP_OPTION, null );

		if ( ! is_array( $tip ) || ! isset( $tip['hash'], $tip['count'] ) ) {
			return null;
		}

		return array( 'hash' => (string) $tip['hash'], 'count' => (int) $tip['count'] );
	}

	/**
	 * Re-anchor the mirror to whatever the table currently holds.
	 *
	 * Only for the paths that legitimately restart the chain — the factory reset and the demo
	 * purge that truncates. Never call it to make a failing audit pass.
	 *
	 * @return void
	 */
	public static function reanchor_chain_tip(): void {
		global $wpdb;
		$logs_table = self::logs_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$last  = $wpdb->get_var( $wpdb->prepare( 'SELECT entry_hash FROM %i ORDER BY log_id DESC LIMIT %d', $logs_table, 1 ) );
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $logs_table ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		self::record_chain_tip( (string) $last, $count );
	}
}

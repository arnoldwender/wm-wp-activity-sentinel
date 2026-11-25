<?php
/**
 * WM Suite Activity Sentinel — Cryptographic Logger Engine
 *
 * @package WM_Activity_Sentinel
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Sentinel_Logger {

	/**
	 * Records the chain itself depends on. They bypass the intensity and module filters: a
	 * "critical_only" setting must not suppress the erasure record that pseudonymised rows need
	 * in order to verify (2026-09-15).
	 */
	public const ALWAYS_RECORDED = [ 'genesis_initialized', 'privacy_erasure_applied', 'retention_pseudonymized' ];

	/** The value wp-config-sample.php ships for every key and salt; WordPress itself refuses to use it (wp_salt()). */
	public const PLACEHOLDER_KEY = 'put your unique phrase here';

	/** While true, log() writes entries but dispatches no mail or webhook (demo data). */
	private static bool $alerts_muted = false;

	/**
	 * Mute or unmute alert dispatch. The demo import used to send real alert mails and webhooks for its
	 * invented "credential stuffing" and "privilege escalation" entries: four critical mails per import with
	 * the default settings (until 2026-09-15).
	 */
	public static function mute_alerts( bool $muted ): void {
		self::$alerts_muted = $muted;
	}

	/**
	 * Main logging API
	 */
	public static function log(
		?int $user_id = null,
		string $event_type = 'general_event',
		string $object_type = 'system',
		?int $object_id = null,
		string $severity = 'info',
		array $context = [],
		?string $ip = null
	): int|false {
		global $wpdb;
		$logs_table = WM_Sentinel_Database::logs_table();

		$always = in_array( $event_type, self::ALWAYS_RECORDED, true );

		// 1. Check if event severity is allowed under the configured logging intensity
		if ( ! $always && ! self::is_severity_permitted( $severity ) ) {
			return false;
		}

		// 2. Check if the event category/module is enabled in settings
		if ( ! $always && ! self::is_module_permitted( $object_type, $event_type ) ) {
			return false;
		}

		// Resolve user context
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		$user_name = 'System';
		$user_email = '';

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$user_name = $user->display_name;
				$user_email = $user->user_email;
			}
		}

		// Resolve IP
		if ( null === $ip ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ) );
		}
		$ip_hmac = self::generate_ip_hmac( $ip );

		// Serialize context to canonical JSON
		$canonical_context = self::canonical_json( $context );

		$timestamp = current_time( 'mysql', true );

		// Execute atomic InnoDB transaction with exclusive row-level locking
		// to guarantee zero chain forks or race conditions during high concurrency spikes
		$wpdb->query( 'START TRANSACTION' );

		try {
			// Fetch previous hash in chain with exclusive row-level lock. A failed SELECT … FOR UPDATE also
			// returns null; until 2026-09-15 that chained the new entry to 'GENESIS' in the middle of a log,
			// which the next audit reports as a broken chain. No lock, no entry.
			$wpdb->last_error = '';
			$last_entry       = WM_Sentinel_Database::get_last_entry_for_update();
			if ( null === $last_entry && '' !== (string) $wpdb->last_error ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
			$prev_hash = $last_entry ? $last_entry->entry_hash : 'GENESIS';

			// Compute cryptographic HMAC-SHA256 entry hash
			$entry_hash = self::compute_entry_hash(
				$prev_hash,
				$user_id,
				$user_name,
				$user_email,
				$event_type,
				$object_type,
				$object_id,
				$severity,
				$canonical_context,
				$ip_hmac,
				$timestamp
			);

			$inserted = $wpdb->insert(
				$logs_table,
				[
					'user_id'      => $user_id > 0 ? $user_id : null,
					'user_name'    => sanitize_text_field( $user_name ),
					'user_email'   => sanitize_email( $user_email ),
					'event_type'   => sanitize_text_field( $event_type ),
					'object_type'  => sanitize_text_field( $object_type ),
					'object_id'    => $object_id,
					'severity'     => in_array( $severity, [ 'info', 'notice', 'warning', 'critical' ], true ) ? $severity : 'info',
					'context'      => $canonical_context,
					'ip_hmac'      => $ip_hmac,
					'created_at'   => $timestamp,
					'prev_hash'    => $prev_hash,
					'entry_hash'   => $entry_hash,
				],
				[ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);

			if ( ! $inserted ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}

			$log_id = (int) $wpdb->insert_id;

			// The mirror is still a write to a different table (wp_options), which is what makes
			// deleting the newest rows detectable. It happens before COMMIT, while the FOR UPDATE
			// lock is held: until 2026-09-15 it ran after COMMIT, so two concurrent requests could
			// store their tips in the wrong order, and a request that died between COMMIT and this
			// write left a tip one entry behind; either way the next audit reported removed
			// entries that were never removed.
			WM_Sentinel_Database::record_chain_tip(
				$entry_hash,
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chain tip, must count the table now.
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $logs_table ) )
			);

			if ( false === $wpdb->query( 'COMMIT' ) ) {
				// The entry and the tip are not stored; nothing to alert about (the result was ignored until 2026-09-15).
				return false;
			}

			// Check if real-time alert should be dispatched
			if ( ! self::$alerts_muted && in_array( $severity, [ 'warning', 'critical' ], true ) ) {
				WM_Sentinel_Alerts::evaluate_and_dispatch( $log_id, $severity, $event_type, $user_name, $context );
			}

			// A SIEM wants the stream, not only what raises an alarm here, so this is not inside the
			// alert condition: the forwarder applies its own minimum severity and is off by default.
			if ( ! self::$alerts_muted && class_exists( 'WM_Sentinel_Forwarder' ) ) {
				WM_Sentinel_Forwarder::forward(
					WM_Sentinel_Forwarder::build_entry( $log_id, $severity, $event_type, $user_name, $context, $timestamp )
				);
			}

			return $log_id;
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) { error_log( '[WM Sentinel Logger Error] ' . $e->getMessage() ); } // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}
	}

	/**
	 * Where the chain key comes from on this install.
	 *
	 * 'wp-config' is the case the tamper-evidence claim relies on: the key sits in a file, so
	 * database access alone cannot forge a link. 'database' means AUTH_KEY is not defined and
	 * WordPress fell back to auto-generated salts, which it stores in wp_options — same database
	 * an attacker would already be in. It is weaker and the dashboard says so instead of
	 * printing the same badge either way.
	 *
	 * Until 1.3.0 the fallback was a literal shipped in this file
	 * ('wm_sentinel_auth_key_2026'), so on any install without AUTH_KEY the "key" was public and
	 * anyone holding a copy of the plugin could forge the entire chain.
	 *
	 * 'wp-config-placeholder': AUTH_KEY still holds the phrase from wp-config-sample.php. The chain is signed
	 * with it (switching keys would make every stored entry fail), but that key is public, so the
	 * dashboard, the CLI and the hub say so. Until 2026-09-15 such an install reported 'wp-config'.
	 *
	 * @return string 'wp-config', 'wp-config-placeholder' or 'database'.
	 */
	public static function chain_key_source(): string {
		if ( ! defined( 'AUTH_KEY' ) || '' === (string) AUTH_KEY ) {
			return 'database';
		}
		return self::PLACEHOLDER_KEY === (string) AUTH_KEY ? 'wp-config-placeholder' : 'wp-config';
	}

	/**
	 * The HMAC key for the chain.
	 *
	 * @return string
	 */
	private static function chain_key(): string {
		return 'database' === self::chain_key_source() ? wp_salt( 'auth' ) : (string) AUTH_KEY;
	}

	/**
	 * Compute deterministic HMAC-SHA256 entry hash
	 */
	public static function compute_entry_hash(
		string $prev_hash,
		?int $user_id,
		string $user_name,
		string $user_email,
		string $event_type,
		string $object_type,
		?int $object_id,
		string $severity,
		string $canonical_context,
		string $ip_hmac,
		string $timestamp
	): string {
		$secret_key = self::chain_key();
		
		$payload_array = [
			'context'     => $canonical_context,
			'created_at'  => $timestamp,
			'event_type'  => $event_type,
			'ip_hmac'     => $ip_hmac,
			'object_id'   => $object_id ? (int) $object_id : null,
			'object_type' => $object_type,
			'prev_hash'   => $prev_hash,
			'severity'    => $severity,
			'user_email'  => $user_email,
			'user_id'     => $user_id ? (int) $user_id : null,
			'user_name'   => $user_name,
		];
		
		// Ensure alphabetical order
		ksort( $payload_array );
		$serialized_payload = (string) wp_json_encode( $payload_array, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return hash_hmac( 'sha256', $prev_hash . '|' . $serialized_payload, $secret_key );
	}

	/**
	 * Check if event severity is permitted by the configured logging intensity
	 */
	public static function is_severity_permitted( string $severity ): bool {
		// Records in ALWAYS_RECORDED are exempted in log(); this only maps severity to intensity.
		$intensity = (string) get_option( 'wm_sentinel_logging_intensity', 'all' );

		$allowed_map = [
			'all'           => [ 'info', 'notice', 'warning', 'critical' ],
			'standard'      => [ 'notice', 'warning', 'critical' ],
			'strict'        => [ 'warning', 'critical' ],
			'critical_only' => [ 'critical' ],
		];

		$allowed = $allowed_map[ $intensity ] ?? $allowed_map['all'];

		return in_array( $severity, $allowed, true );
	}

	/**
	 * Check if the event category/module is enabled in settings
	 */
	public static function is_module_permitted( string $object_type, string $event_type ): bool {
		// System genesis is always permitted
		if ( 'genesis_initialized' === $event_type ) {
			return true;
		}

		// Screen views
		if ( 'admin_screen_viewed' === $event_type ) {
			return (bool) get_option( 'wm_sentinel_monitor_screens', 1 );
		}

		// Frontend views
		if ( 'frontend_page_viewed' === $event_type ) {
			return (bool) get_option( 'wm_sentinel_monitor_frontend_views', 1 );
		}

		// Client telemetry interactions
		if ( in_array( $object_type, [ 'navigation', 'action', 'ui_interaction' ], true ) || in_array( $event_type, [ 'admin_menu_click', 'admin_bar_click', 'tab_switch_click', 'table_action_click', 'button_pressed', 'download_link_clicked', 'link_clicked', 'interactive_element_click', 'ui_click' ], true ) ) {
			return (bool) get_option( 'wm_sentinel_monitor_ui_interactions', 1 );
		}

		// Authentication & Identity. The sensors emit user_login_success / user_login_failed; until
		// 2026-09-15 only names no sensor emits were listed, so this switch never filtered a login
		// and logins fell through to the user-management switch below.
		if ( in_array( $event_type, [ 'user_login_success', 'user_login_failed', 'application_password_created', 'login_success', 'login_failed', 'wp_login_failed', 'wp_login', 'logout', 'session_destroyed', 'password_reset', 'user_registered', 'user_register' ], true ) ) {
			return (bool) get_option( 'wm_sentinel_log_auth', 1 );
		}

		// User Management & Roles
		if ( in_array( $object_type, [ 'user', 'role', 'user_role' ], true ) || in_array( $event_type, [ 'user_role_changed', 'set_user_role', 'user_created', 'user_deleted', 'deleted_user' ], true ) ) {
			return (bool) get_option( 'wm_sentinel_log_users', 1 );
		}

		// System, Core, Themes & Plugins, including the file editor (object type 'file', which no
		// switch covered until 2026-09-15 although the settings screen listed it here).
		if ( in_array( $object_type, [ 'plugin', 'theme', 'core', 'system', 'option', 'file' ], true ) || in_array( $event_type, [ 'plugin_activated', 'activated_plugin', 'plugin_deactivated', 'deactivated_plugin', 'plugin_deleted', 'delete_plugin', 'theme_switched', 'after_switch_theme', 'core_updated', 'upgrader_process', 'critical_option_updated', 'code_file_edited', 'plugin_editor_accessed', 'theme_editor_accessed' ], true ) ) {
			return (bool) get_option( 'wm_sentinel_log_plugins', 1 );
		}

		// Posts, Pages, Media & Content Exports
		if ( in_array( $object_type, [ 'post', 'page', 'attachment', 'media', 'export', 'backup' ], true ) || in_array( $event_type, [ 'export_wp_native_triggered', 'admin_export_visited', 'backup_triggered', 'post_created', 'post_updated', 'post_deleted', 'attachment_uploaded' ], true ) ) {
			return (bool) get_option( 'wm_sentinel_log_content', 1 );
		}

		// REST API
		if ( 'rest_api' === $object_type || str_starts_with( $event_type, 'rest_' ) ) {
			return (bool) get_option( 'wm_sentinel_log_rest', 1 );
		}

		return true;
	}

	/**
	 * Produce canonical sorted JSON string
	 */
	public static function canonical_json( array $data ): string {
		if ( empty( $data ) ) {
			return '{}';
		}
		
		self::recursive_ksort( $data );
		return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Recursively sort associative array keys alphabetically
	 */
	private static function recursive_ksort( array &$array ): void {
		ksort( $array );
		foreach ( $array as &$value ) {
			if ( is_array( $value ) ) {
				self::recursive_ksort( $value );
			}
		}
	}

	/**
	 * Daily-keyed IP HMAC: the raw address is not stored, and hashes of the same address match only
	 * within one UTC day.
	 *
	 * Until 2026-09-15 an install without NONCE_SALT used the literal 'wm_sentinel_default_salt_2026'
	 * shipped in this file, so anyone with a copy of the plugin could test candidate addresses
	 * against the stored hashes. The fallback is WordPress's own stored nonce salt.
	 */
	public static function generate_ip_hmac( string $ip_address ): string {
		$salt = defined( 'NONCE_SALT' ) && '' !== (string) NONCE_SALT ? (string) NONCE_SALT : wp_salt( 'nonce' );
		$daily_salt = gmdate( 'Y-m-d' ) . $salt;
		return hash_hmac( 'sha256', $ip_address, $daily_salt );
	}

	/**
	 * Keyed fingerprint of a row as it stands after pseudonymisation.
	 *
	 * An erasure changes user_name, user_email, ip_hmac and parts of context, so the row's own
	 * entry_hash can no longer be recomputed. The erasure record (a chained entry written by the
	 * same key) stores this fingerprint for each changed row; verification accepts the row only
	 * when the fingerprint still matches, so every later edit of that row stays detectable.
	 *
	 * @param object $row Log row with the pseudonymised values.
	 * @return string
	 */
	public static function compute_residual_hash( object $row ): string {
		$payload = [
			'context'     => (string) ( $row->context ?? '{}' ),
			'created_at'  => (string) $row->created_at,
			'entry_hash'  => (string) $row->entry_hash,
			'event_type'  => (string) $row->event_type,
			'ip_hmac'     => (string) $row->ip_hmac,
			'log_id'      => (int) $row->log_id,
			'object_id'   => $row->object_id ? (int) $row->object_id : null,
			'object_type' => (string) $row->object_type,
			'prev_hash'   => (string) $row->prev_hash,
			'severity'    => (string) $row->severity,
			'user_email'  => (string) $row->user_email,
			'user_id'     => $row->user_id ? (int) $row->user_id : null,
			'user_name'   => (string) $row->user_name,
		];
		ksort( $payload );

		return hash_hmac( 'sha256', 'erasure|' . (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), self::chain_key() );
	}
}

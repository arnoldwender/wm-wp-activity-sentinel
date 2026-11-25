<?php
/**
 * WM Suite Activity Sentinel — Demo Data & Factory Reset Engine
 *
 * @package WM_Activity_Sentinel
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Sentinel_Seeder {

	/**
	 * Seed realistic enterprise activity & forensic security demo logs
	 *
	 * @param int $count Number of demo log records to generate
	 * @return int Number of successfully seeded demo logs
	 */
	public static function seed_demo_data( int $count = 15 ): int {
		WM_Sentinel_Database::ensure_genesis_block();

		$demo_scenarios = [
			[
				'user_id'     => 1,
				'event_type'  => 'user_login_success',
				'object_type' => 'user',
				'object_id'   => 1,
				'severity'    => 'info',
				'context'     => [
					'auth_method' => 'two_factor_totp',
					'user_agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36',
					'session_id'  => wp_generate_password( 24, false ),
					'_is_demo'    => true,
				],
			],
			[
				'user_id'     => 0,
				'event_type'  => 'failed_login_burst',
				'object_type' => 'auth',
				'object_id'   => null,
				'severity'    => 'critical',
				'context'     => [
					'attempted_user' => 'admin_staging',
					'burst_count'    => 12,
					'threat_class'   => 'credential_stuffing',
					'action_taken'   => 'ip_temporarily_throttled',
					'_is_demo'       => true,
				],
			],
			[
				'user_id'     => 1,
				'event_type'  => 'plugin_activated',
				'object_type' => 'plugin',
				'object_id'   => null,
				'severity'    => 'notice',
				'context'     => [
					'plugin_slug'    => 'wm-cyber-events-core/wm-cyber-events-core.php',
					'plugin_version' => '1.0.0',
					'_is_demo'       => true,
				],
			],
			[
				'user_id'     => 1,
				'event_type'  => 'export_download_authorized',
				'object_type' => 'export',
				'object_id'   => null,
				'severity'    => 'warning',
				'context'     => [
					'format'         => 'CSV_EXTF',
					'record_count'   => 142,
					'dsgVO_consent'  => true,
					'purpose'        => 'Finanz-Audit Q3',
					'_is_demo'       => true,
				],
			],
			[
				'user_id'     => 1,
				'event_type'  => 'nda_consent_signed',
				'object_type' => 'nda_term',
				'object_id'   => 1,
				'severity'    => 'info',
				'context'     => [
					'term_version'   => '1.0.0',
					'signature_hash' => hash( 'sha256', 'demo_signature_' . wp_rand() ),
					'_is_demo'       => true,
				],
			],
			[
				'user_id'     => 0,
				'event_type'  => 'unauthorized_rest_probe',
				'object_type' => 'rest_api',
				'object_id'   => null,
				'severity'    => 'warning',
				'context'     => [
					'endpoint'       => '/wp-json/wp/v2/users',
					'http_status'    => 403,
					'origin_header'  => 'https://untrusted.example',
					'_is_demo'       => true,
				],
			],
			[
				'user_id'     => 1,
				'event_type'  => 'privilege_escalation_check',
				'object_type' => 'user',
				'object_id'   => 2,
				'severity'    => 'critical',
				'context'     => [
					'target_user'    => 'editor_marketing',
					'assigned_roles' => [ 'administrator' ],
					'verified_by'    => 'super_admin',
					'_is_demo'       => true,
				],
			],
			[
				'user_id'     => 1,
				'event_type'  => 'settings_updated',
				'object_type' => 'options',
				'object_id'   => null,
				'severity'    => 'notice',
				'context'     => [
					'modified_keys'  => [ 'logging_intensity', 'sensor_rest_api' ],
					'previous_level' => 'normal',
					'new_level'      => 'high',
					'_is_demo'       => true,
				],
			],
		];

		// Counts what log() actually wrote: the logging level and the switches can refuse entries, and until
		// 2026-09-15 the import reported 15 whatever happened. Alerts stay muted for the invented scenarios.
		$seeded = 0;
		WM_Sentinel_Logger::mute_alerts( true );
		try {
			for ( $i = 0; $i < $count; $i++ ) {
				$template = $demo_scenarios[ $i % count( $demo_scenarios ) ];
				$context  = $template['context'];
				$context['iteration'] = $i + 1;
				$context['seeded_at'] = current_time( 'mysql' );

				$written = WM_Sentinel_Logger::log(
					$template['user_id'],
					$template['event_type'],
					$template['object_type'],
					$template['object_id'],
					$template['severity'],
					$context
				);
				if ( is_int( $written ) ) {
					$seeded++;
				}
			}
		} finally {
			WM_Sentinel_Logger::mute_alerts( false );
		}

		return $seeded;
	}

	/**
	 * Delete all demo activity logs and rebuild the cryptographic hash chain
	 *
	 * @return int Number of deleted demo logs
	 */
	public static function delete_demo_data(): int {
		global $wpdb;
		$logs_table = WM_Sentinel_Database::logs_table();

		$like_compact = '%' . $wpdb->esc_like( '"_is_demo":true' ) . '%';
		$like_spaced  = '%' . $wpdb->esc_like( '"_is_demo": true' ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$demo_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE context LIKE %s OR context LIKE %s',
				$logs_table,
				$like_compact,
				$like_spaced
			)
		);

		if ( 0 === $demo_count ) {
			return 0;
		}

		// Anything that is neither a demo row nor the genesis block is a real audit record.
		$real_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE event_type <> %s AND NOT ( context LIKE %s OR context LIKE %s )',
				$logs_table,
				'genesis_initialized',
				$like_compact,
				$like_spaced
			)
		);

		if ( $real_count > 0 ) {
			// Until 1.3.0 this branch deleted the demo rows and then called rebuild_chain(),
			// which re-signed every surviving row so the audit passed again. Measured on a clean
			// install: edit a row by hand, verification goes red, press this button, verification
			// goes green with the edit still in place. That turned the admin screen into an
			// eraser for the plugin's own tamper evidence — against the exact actor an audit log
			// exists to hold accountable.
			//
			// An append-only chain cannot lose a row from the middle. The honest options are to
			// keep the demo rows or to reset the whole log, and the caller is told which.
			return -1;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Nothing but demo rows and genesis: the chain can restart from scratch. That is visible
		// in the audit (block count returns to 1), not laundered.
		WM_Sentinel_Database::reset_tables();

		return $demo_count;
	}

	/**
	 * Factory reset: empties both tables, starts a new chain at genesis and writes every setting
	 * back to WM_Sentinel_Database::default_options().
	 */
	public static function reset_to_factory_defaults(): void {
		// 1. Truncate tables and seed fresh Genesis block
		WM_Sentinel_Database::reset_tables();

		// 2. Every setting the plugin reads back to its default; options nothing reads are removed.
		foreach ( WM_Sentinel_Database::default_options() as $option => $value ) {
			update_option( $option, $value );
		}
		foreach ( WM_Sentinel_Database::LEGACY_OPTIONS as $option ) {
			delete_option( $option );
		}
	}
}

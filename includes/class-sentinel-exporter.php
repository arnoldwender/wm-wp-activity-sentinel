<?php
/**
 * WM Suite Activity Sentinel — Forensic Exporter (CWE-1236 Sanitized)
 *
 * @package WM_Activity_Sentinel
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Sentinel_Exporter {

	/**
	 * Sanitize cell against CSV Formula Injection (CWE-1236)
	 */
	public static function sanitize_csv_cell( string $value ): string {
		$dangerous_prefixes = [ '=', '+', '-', '@', "\t", "\r" ];
		$first_char = mb_substr( $value, 0, 1 );

		if ( in_array( $first_char, $dangerous_prefixes, true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Export logs as sanitized CSV
	 */
	public static function export_csv( array $filters = [] ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-activity-sentinel' ), 403 );
		}

		check_admin_referer( 'wm_sentinel_export_csv' );

		$filters['limit'] = 10000;
		$filters['offset'] = 0;
		$logs = WM_Sentinel_Database::get_logs( $filters );

		$filename = 'wm-activity-sentinel-log-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv( $output, [
			'Log ID',
			'Zeitpunkt (UTC)',
			'Schweregrad',
			'Ereignis',
			'Objekttyp',
			'Benutzer',
			'E-Mail',
			'IP-HMAC (DSGVO)',
			'Prev Hash',
			'Entry Hash',
			'Kontext-Daten (JSON)',
		], ';' );

		foreach ( $logs as $row ) {
			fputcsv( $output, [
				self::sanitize_csv_cell( (string) $row->log_id ),
				self::sanitize_csv_cell( $row->created_at ),
				self::sanitize_csv_cell( strtoupper( $row->severity ) ),
				self::sanitize_csv_cell( $row->event_type ),
				self::sanitize_csv_cell( $row->object_type ),
				self::sanitize_csv_cell( $row->user_name ),
				self::sanitize_csv_cell( $row->user_email ),
				self::sanitize_csv_cell( $row->ip_hmac ),
				self::sanitize_csv_cell( $row->prev_hash ),
				self::sanitize_csv_cell( $row->entry_hash ),
				self::sanitize_csv_cell( $row->context ?? '{}' ),
			], ';' );
		}

		// php://output is the HTTP response stream, not the filesystem; WP_Filesystem does not apply.
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Export logs as a JSON package with a SHA-256 checksum over the rows (not a signature: anyone can recompute it)
	 */
	public static function export_json( array $filters = [] ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-activity-sentinel' ), 403 );
		}

		check_admin_referer( 'wm_sentinel_export_json' );

		$filters['limit'] = 10000;
		$filters['offset'] = 0;
		$logs = WM_Sentinel_Database::get_logs( $filters );
		$integrity = WM_Sentinel_Integrity::verify_chain();

		$payload = [
			'audit_package_metadata' => [
				'issuer'            => 'WM Suite Activity Sentinel',
				'system_url'        => site_url(),
				'generated_at_utc'  => current_time( 'mysql', true ),
				'total_records'     => count( $logs ),
				'chain_intact'      => $integrity['valid'],
				'verified_blocks'   => $integrity['total_verified'],
				'pseudonymized'     => (int) ( $integrity['pseudonymized'] ?? 0 ),
				// Until 2026-09-15 this package carried "compliance_shield: GoBD, GeschGehG § 2,
				// DSGVO Art. 6(1)(f)", a legal label the plugin cannot assess. The checksum below is
				// SHA-256 of this document encoded without the master_checksum_sha256 key.
			],
			'activity_records'       => array_map( function ( $l ) {
				return [
					'log_id'      => (int) $l->log_id,
					'created_at'  => $l->created_at,
					'severity'    => $l->severity,
					'event_type'  => $l->event_type,
					'object_type' => $l->object_type,
					'object_id'   => $l->object_id ? (int) $l->object_id : null,
					'user_id'     => $l->user_id ? (int) $l->user_id : null,
					'user_name'   => $l->user_name,
					'user_email'  => $l->user_email,
					'ip_hmac'     => $l->ip_hmac,
					'prev_hash'   => $l->prev_hash,
					'entry_hash'  => $l->entry_hash,
					'context'     => json_decode( $l->context ?? '{}', true ),
				];
			}, $logs ),
		];

		$canonical_json = (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$master_checksum = hash( 'sha256', $canonical_json );
		$payload['audit_package_metadata']['master_checksum_sha256'] = $master_checksum;

		$filename = 'wm-activity-sentinel-audit-' . gmdate( 'Y-m-d-His' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// A JSON document served as a download (Content-Type application/json): HTML escaping would corrupt it.
		echo (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Register WordPress Core Personal Data Exporter.
	 */
	public static function register_privacy_exporters( array $exporters ): array {
		$exporters['wm-activity-sentinel'] = [
			'exporter_friendly_name' => __( 'WM Activity Sentinel Audit-Logs', 'wm-activity-sentinel' ),
			'callback'               => [ __CLASS__, 'export_personal_data' ],
		];
		return $exporters;
	}

	/**
	 * Register WordPress Core Personal Data Eraser (DSGVO Art. 17).
	 */
	public static function register_privacy_erasers( array $erasers ): array {
		$erasers['wm-activity-sentinel'] = [
			'eraser_friendly_name' => __( 'WM Activity Sentinel Audit-Logs', 'wm-activity-sentinel' ),
			'callback'             => [ __CLASS__, 'erase_personal_data' ],
		];
		return $erasers;
	}

	/** Rows in which an address appears, as actor or inside the context JSON. */
	private static function rows_mentioning( string $email_address, int $limit = 0 ): array {
		global $wpdb;

		$sql  = 'SELECT * FROM %i WHERE user_email = %s OR context LIKE %s ORDER BY log_id ASC';
		$args = [ WM_Sentinel_Database::logs_table(), $email_address, '%' . $wpdb->esc_like( $email_address ) . '%' ];
		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d';
			$args[] = $limit;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SQL assembled from literals; every value and the table name pass through prepare().
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) ) ?: [];
	}

	/**
	 * Exporter Callback for WordPress Core Privacy Tool.
	 *
	 * Until 2026-09-15 this and the eraser called WM_Sentinel_Database::get_table_name(), a method
	 * that does not exist: Tools › Export Personal Data and Erase Personal Data ended in a fatal
	 * error for every request once this plugin was active (measured on a local WordPress install).
	 */
	public static function export_personal_data( string $email_address, int $page = 1 ): array {
		$export_items = [];
		$logs         = self::rows_mentioning( $email_address, 1000 );

		if ( ! empty( $logs ) ) {
			foreach ( $logs as $log ) {
				$item_data = [
					[
						'name'  => __( 'Zeitpunkt (UTC)', 'wm-activity-sentinel' ),
						'value' => esc_html( $log->created_at ),
					],
					[
						'name'  => __( 'Ereignis', 'wm-activity-sentinel' ),
						'value' => esc_html( $log->event_type ),
					],
					[
						'name'  => __( 'Schweregrad', 'wm-activity-sentinel' ),
						'value' => esc_html( $log->severity ),
					],
					[
						'name'  => __( 'Objekttyp', 'wm-activity-sentinel' ),
						'value' => esc_html( $log->object_type ?? '' ),
					],
					[
						'name'  => __( 'Benutzername', 'wm-activity-sentinel' ),
						'value' => esc_html( $log->user_name ),
					],
					[
						'name'  => __( 'Benutzer-E-Mail', 'wm-activity-sentinel' ),
						'value' => esc_html( $log->user_email ),
					],
					[
						'name'  => __( 'Eintrags-Hash', 'wm-activity-sentinel' ),
						'value' => esc_html( $log->entry_hash ),
					],
				];

				$export_items[] = [
					'group_id'    => 'wm_sentinel_logs',
					'group_label' => __( 'Sicherheits- und Audit-Protokolle', 'wm-activity-sentinel' ),
					'item_id'     => "wm-sentinel-log-{$log->log_id}",
					'data'        => $item_data,
				];
			}
		}

		return [
			'data' => $export_items,
			'done' => true,
		];
	}

	/**
	 * Eraser Callback for WordPress Core Privacy Tool.
	 *
	 * Rows where the address is the actor get a placeholder name, a placeholder address and a
	 * neutral IP hash; every occurrence of the address inside a row's context is replaced as well.
	 * Before the rows change, a chained entry `privacy_erasure_applied` records a keyed fingerprint
	 * of each row as it will look afterwards, so the audit accepts those rows and still detects
	 * any later edit of them.
	 *
	 * Until 2026-09-15 the eraser fatalled (see export_personal_data()); had it run, it changed the
	 * rows without such a record, the next audit reported tampering at the first erased row, and
	 * its message said the chain signatures stayed intact. It also left the address in context.
	 */
	public static function erase_personal_data( string $email_address, int $page = 1 ): array {
		global $wpdb;

		$email_address = (string) sanitize_email( $email_address );
		$rows          = '' === $email_address ? [] : self::rows_mentioning( $email_address );
		$planned       = [];

		foreach ( $rows as $row ) {
			$after   = clone $row;
			$changes = [];
			if ( 0 === strcasecmp( (string) $row->user_email, $email_address ) ) {
				$changes['user_name']  = '[DSGVO Art. 17 Gelöscht]';
				$changes['user_email'] = sprintf( 'anonymized-%d@privacy.local', (int) $row->log_id );
				$changes['ip_hmac']    = hash_hmac( 'sha256', '0.0.0.0', wp_salt( 'auth' ) );
			}
			$context = (string) ( $row->context ?? '' );
			if ( '' !== $context && false !== stripos( $context, $email_address ) ) {
				$changes['context'] = str_ireplace( $email_address, sprintf( 'anonymized-%d@privacy.local', (int) $row->log_id ), $context );
			}
			if ( [] === $changes ) {
				continue; // LIKE matched a substring of a longer address.
			}
			foreach ( $changes as $field => $value ) {
				$after->$field = $value;
			}
			$planned[ (int) $row->log_id ] = [ 'changes' => $changes, 'residual' => WM_Sentinel_Logger::compute_residual_hash( $after ) ];
		}

		if ( [] === $planned ) {
			return [
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => [],
				'done'           => true,
			];
		}

		// The record first: if an update below fails, that row keeps its original values and still
		// verifies normally; if the record could not be written, nothing is changed.
		$record_id = WM_Sentinel_Logger::log(
			get_current_user_id(),
			'privacy_erasure_applied',
			'privacy',
			null,
			'notice',
			[
				'pseudonymized_rows' => count( $planned ),
				'residual_hashes'    => array_map( static fn( $p ) => $p['residual'], $planned ),
			]
		);

		if ( ! $record_id ) {
			return [
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => [ __( 'Der Löschnachweis konnte nicht in die Kette geschrieben werden; es wurde nichts geändert.', 'wm-activity-sentinel' ) ],
				'done'           => true,
			];
		}

		$changed = 0;
		foreach ( $planned as $log_id => $plan ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- erasure of this plugin's own rows.
			if ( false !== $wpdb->update( WM_Sentinel_Database::logs_table(), $plan['changes'], [ 'log_id' => $log_id ] ) ) {
				$changed++;
			}
		}

		return [
			'items_removed'  => $changed > 0,
			'items_retained' => $changed < count( $planned ),
			'messages'       => [
				sprintf(
					/* translators: 1: number of pseudonymised log entries, 2: id of the erasure record */
					__( '%1$d Protokolleinträge pseudonymisiert (Name, Adresse, IP-Hash, Adresse im Kontext). Die Prüfung erkennt sie über den Löschnachweis #%2$d an; Ereignisart, Zeitpunkt und übriger Kontext bleiben stehen.', 'wm-activity-sentinel' ),
					$changed,
					(int) $record_id
				),
			],
			'done'           => true,
		];
	}
}


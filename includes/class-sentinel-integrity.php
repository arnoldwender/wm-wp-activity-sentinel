<?php
/**
 * WM Suite Activity Sentinel — Cryptographic Chain Integrity Auditor
 *
 * @package WM_Activity_Sentinel
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Sentinel_Integrity {

	/**
	 * Audit and verify entire hash chain from Genesis to latest block
	 */
	public static function verify_chain(): array {
		global $wpdb;
		$logs_table = WM_Sentinel_Database::logs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- audit reads the plugin's own log table directly.
		$logs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY log_id ASC', $logs_table ) );

		$tip = WM_Sentinel_Database::get_chain_tip();

		if ( empty( $logs ) ) {
			// An empty table is only innocent when nothing was ever written. If a tip was
			// recorded, the rows it counted are gone.
			$wiped = null !== $tip && $tip['count'] > 0;

			return [
				'valid'          => ! $wiped,
				'total_verified' => 0,
				'broken_at_id'   => null,
				'error_reason'   => $wiped
					? sprintf(
						/* translators: %d: number of entries the recorded chain tip counted */
						__( 'Die Log-Tabelle ist leer, obwohl zuletzt %d Einträge verzeichnet waren. Das Protokoll wurde gelöscht.', 'wm-activity-sentinel' ),
						$tip['count']
					)
					: null,
				'key_source'     => WM_Sentinel_Logger::chain_key_source(),
				'pseudonymized'  => 0,
			];
		}

		$expected_prev_hash = 'GENESIS';
		$verified_count = 0;
		$pseudonymized  = 0;

		// Fingerprints of pseudonymised rows, taken from the two kinds of record that write them:
		// WM_Sentinel_Exporter::erase_personal_data() (Art. 17, per person, on request) and
		// WM_Sentinel_Retention::apply() (Art. 5(1)(e), by age, on a schedule). Each record is
		// itself a chained entry: a forged one fails its own recomputation below, so trusting its
		// list is safe once the loop has walked past it. Only rows older than the record that
		// lists them are accepted.
		$residual_records = [ 'privacy_erasure_applied', WM_Sentinel_Retention::RECORD_EVENT ];
		$residuals        = [];
		foreach ( $logs as $row ) {
			if ( ! in_array( (string) $row->event_type, $residual_records, true ) ) {
				continue;
			}
			$record = json_decode( (string) $row->context, true );
			foreach ( (array) ( $record['residual_hashes'] ?? [] ) as $listed_id => $residual ) {
				$residuals[ (int) $listed_id ] = [ 'hash' => (string) $residual, 'record_id' => (int) $row->log_id ];
			}
		}

		foreach ( $logs as $index => $row ) {
			$log_id = (int) $row->log_id;

			// 1. Check previous hash linkage
			if ( ! hash_equals( $expected_prev_hash, (string) $row->prev_hash ) ) {
				return [
					'valid'          => false,
					'total_verified' => $verified_count,
					'broken_at_id'   => $log_id,
					'error_reason'   => sprintf(
						/* translators: 1: log id, 2: stored prev_hash prefix, 3: expected hash prefix */
						__( 'Kettenbruch bei Log-ID #%1$d: prev_hash "%2$s" stimmt nicht mit dem erwarteten Hash "%3$s" überein (Hinweis auf gelöschte oder eingeschleuste Zeilen).', 'wm-activity-sentinel' ),
						$log_id,
						substr( $row->prev_hash, 0, 16 ) . '...',
						substr( $expected_prev_hash, 0, 16 ) . '...'
					),
					'key_source'     => WM_Sentinel_Logger::chain_key_source(),
					'pseudonymized'  => $pseudonymized ?? 0,
				];
			}

			// 2. Re-compute HMAC entry hash to detect tampering
			$recomputed_hash = WM_Sentinel_Logger::compute_entry_hash(
				$row->prev_hash,
				$row->user_id ? (int) $row->user_id : null,
				$row->user_name,
				$row->user_email,
				$row->event_type,
				$row->object_type,
				$row->object_id ? (int) $row->object_id : null,
				$row->severity,
				$row->context ?? '{}',
				$row->ip_hmac,
				$row->created_at
			);

			$accepted_as_pseudonymized = ! hash_equals( $recomputed_hash, (string) $row->entry_hash )
				&& isset( $residuals[ $log_id ] )
				&& $residuals[ $log_id ]['record_id'] > $log_id
				&& hash_equals( $residuals[ $log_id ]['hash'], WM_Sentinel_Logger::compute_residual_hash( $row ) );

			if ( $accepted_as_pseudonymized ) {
				$pseudonymized++;
			} elseif ( ! hash_equals( $recomputed_hash, (string) $row->entry_hash ) ) {
				return [
					'valid'          => false,
					'total_verified' => $verified_count,
					'broken_at_id'   => $log_id,
					'error_reason'   => sprintf(
						// An HMAC mismatch cannot tell an edited row from a different key: a site moved to a server with
						// another AUTH_KEY fails here at the first entry (measured on a migrated staging site, 2026-09-15).
						/* translators: 1: log id, 2: recomputed hash prefix, 3: stored hash prefix */
						__( 'Manipulationsnachweis bei Log-ID #%1$d: Der berechnete Prüfsummen-Hash "%2$s" weicht vom gespeicherten Hash "%3$s" ab. Entweder wurden die Daten nachträglich in der Datenbank verändert, oder das Protokoll wurde mit einem anderen AUTH_KEY geschrieben (etwa vor einem Umzug der Website).', 'wm-activity-sentinel' ),
						$log_id,
						substr( $recomputed_hash, 0, 16 ) . '...',
						substr( $row->entry_hash, 0, 16 ) . '...'
					),
					'key_source'     => WM_Sentinel_Logger::chain_key_source(),
					'pseudonymized'  => $pseudonymized ?? 0,
				];
			}

			$expected_prev_hash = $row->entry_hash;
			$verified_count++;
		}

		// Everything above only proves the surviving rows are consistent with each other. A
		// chain with its newest entries cut off is internally perfect — which is exactly the
		// edit an intruder makes. The mirrored tip is the only thing that sees it.
		if ( null !== $tip && ( ! hash_equals( $tip['hash'], $expected_prev_hash ) || $tip['count'] > $verified_count ) ) {
			return [
				'valid'          => false,
				'total_verified' => $verified_count,
				'broken_at_id'   => null,
				'error_reason'   => sprintf(
					// The tip also mismatches when the count agrees and only the hash differs, so the
					// reason names replacement as well as removal (until 1.4.2 it said «entfernt» alone).
					/* translators: 1: entries present, 2: entries the recorded chain tip counted */
					__( 'Das Ende der Kette stimmt nicht mit dem verzeichneten Kettenstand überein (%1$d von %2$d Einträgen vorhanden): Die neuesten Protokolleinträge wurden entfernt oder ersetzt.', 'wm-activity-sentinel' ),
					$verified_count,
					$tip['count']
				),
				'last_entry_hash'=> $expected_prev_hash,
				'key_source'     => WM_Sentinel_Logger::chain_key_source(),
				'pseudonymized'  => $pseudonymized ?? 0,
			];
		}

		return [
			'valid'          => true,
			'total_verified' => $verified_count,
			'broken_at_id'   => null,
			'error_reason'   => null,
			'last_entry_hash'=> $expected_prev_hash,
			'key_source'     => WM_Sentinel_Logger::chain_key_source(),
			'pseudonymized'  => $pseudonymized ?? 0,
		];
	}
}

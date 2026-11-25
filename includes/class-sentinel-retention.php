<?php
/**
 * WM Suite Activity Sentinel — Retention
 *
 * Applies `wm_sentinel_retention_days` to the log. Until 2026-09-17 that setting was written by
 * activation, by the seeder and by the settings form, and read in exactly one place — to render
 * itself back into that form. Nothing removed anything. A field labelled *Aufbewahrungsdauer*
 * that removes nothing reads like a DSGVO Art. 5(1)(e) storage-limitation control and is not one.
 *
 * ## Why this pseudonymises instead of deleting
 *
 * The log is a hash chain: every row carries the `entry_hash` of the row before it, and the
 * recorded tip carries the count. Deleting old rows breaks the linkage check at the first
 * survivor and the tip count at the end — the audit would report the site's own retention policy
 * as tampering, which is the worst possible outcome for an evidence log.
 *
 * Art. 5(1)(e) limits how long **personal data** is kept, not how long the fact that an event
 * happened is kept. So the row stays, its chain position stays, and the identifying fields are
 * neutralised: `user_name`, `user_email` and `ip_hmac`. Event type, time, severity and object
 * survive, and the entry remains verifiable through the same residual-fingerprint mechanism the
 * Art. 17 eraser already uses (`WM_Sentinel_Logger::compute_residual_hash()`).
 *
 * ## What is deliberately NOT touched
 *
 * `context` is left as it stands. It is the evidence of what happened, and blanking it would
 * destroy the record this plugin exists to keep. Where a context field holds a person's data and
 * that person asks for erasure, the Art. 17 path (`WM_Sentinel_Exporter::erase_personal_data()`)
 * is the one that rewrites it — per person, on request, which is what the right actually is.
 *
 * Rows in `WM_Sentinel_Logger::ALWAYS_RECORDED` are skipped: the genesis entry and the erasure
 * records are the chain's own scaffolding.
 *
 * @package WM_Activity_Sentinel
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Sentinel_Retention {

	/** The daily cron hook. */
	public const CRON_HOOK = 'wm_sentinel_apply_retention';

	/** The chained record that lets the audit accept the rows this pass changed. */
	public const RECORD_EVENT = 'retention_pseudonymized';

	/** Value written over a neutralised name. */
	public const NEUTRAL_NAME = '[Aufbewahrungsfrist abgelaufen]';

	/**
	 * Most rows one pass will touch.
	 *
	 * A first run on a log that has been growing for years would otherwise build one record
	 * holding a fingerprint per row and one UPDATE per row inside a single request. The cap makes
	 * the pass repeatable instead: the next run takes the next batch, and `remaining` says so.
	 */
	public const BATCH_LIMIT = 500;

	/** Registers the daily event; the schedule follows the setting the way the FIM's does. */
	public static function init(): void {
		add_action( self::CRON_HOOK, [ self::class, 'run_scheduled' ] );
		self::sync_schedule();
	}

	/**
	 * A retention of 0 means "keep everything", so the event is unscheduled rather than left
	 * running against a threshold that never matches.
	 */
	public static function sync_schedule(): void {
		$days      = self::configured_days();
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( $days > 0 && false === $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
			return;
		}

		if ( 0 === $days && false !== $scheduled ) {
			wp_unschedule_event( (int) $scheduled, self::CRON_HOOK );
		}
	}

	/** Clears the event; called from deactivation. */
	public static function unschedule(): void {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $scheduled ) {
			wp_unschedule_event( (int) $scheduled, self::CRON_HOOK );
		}
	}

	/**
	 * The configured window in days, clamped to what the settings screen accepts.
	 *
	 * The form offers 30 to 3650. A stored 0 (or anything below 30 written with `wp option
	 * update`) is read as "keep everything" rather than as "pseudonymise everything", because the
	 * default when a value is missing or unreadable is never the destructive one.
	 */
	public static function configured_days(): int {
		$days = (int) get_option( 'wm_sentinel_retention_days', 90 );
		if ( $days < 30 ) {
			return 0;
		}
		return min( 3650, $days );
	}

	/** The cron callback: does nothing at all while retention is off. */
	public static function run_scheduled(): void {
		if ( 0 === self::configured_days() ) {
			return;
		}
		self::apply();
	}

	/**
	 * Neutralises the identifying fields of rows past the window.
	 *
	 * @param bool $dry_run When true, measures and reports without writing anything.
	 * @return array{applied:int,candidates:int,remaining:int,record_id:int|null,cutoff:string,reason:string|null}
	 */
	public static function apply( bool $dry_run = false ): array {
		global $wpdb;

		$days = self::configured_days();
		$none = [
			'applied'    => 0,
			'candidates' => 0,
			'remaining'  => 0,
			'record_id'  => null,
			'cutoff'     => '',
			'reason'     => null,
		];

		if ( 0 === $days ) {
			return array_merge( $none, [ 'reason' => 'retention_off' ] );
		}

		$cutoff          = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$none['cutoff']  = $cutoff;
		$logs_table      = WM_Sentinel_Database::logs_table();
		$always_recorded = WM_Sentinel_Logger::ALWAYS_RECORDED;

		// Candidates: older than the cutoff, not part of the chain's own scaffolding, and still
		// carrying something to neutralise. The last condition is what makes the pass idempotent —
		// a second run finds nothing and writes no record.
		$placeholders = implode( ', ', array_fill( 0, count( $always_recorded ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$logs_table}
				  WHERE created_at < %s
				    AND event_type NOT IN ( {$placeholders} )
				    AND ( user_name <> %s OR user_email <> %s OR ip_hmac <> %s )
				  ORDER BY log_id ASC
				  LIMIT %d",
				array_merge(
					[ $cutoff ],
					$always_recorded,
					[ self::NEUTRAL_NAME, '', '', self::BATCH_LIMIT + 1 ]
				)
			)
		);
		// phpcs:enable

		$rows      = is_array( $rows ) ? $rows : [];
		$remaining = count( $rows ) > self::BATCH_LIMIT ? 1 : 0;
		$rows      = array_slice( $rows, 0, self::BATCH_LIMIT );

		if ( [] === $rows ) {
			return array_merge( $none, [ 'cutoff' => $cutoff ] );
		}

		$planned = [];
		foreach ( $rows as $row ) {
			$after              = clone $row;
			$after->user_name   = self::NEUTRAL_NAME;
			$after->user_email  = '';
			$after->ip_hmac     = '';

			$planned[ (int) $row->log_id ] = [
				'changes'  => [
					'user_name'  => self::NEUTRAL_NAME,
					'user_email' => '',
					'ip_hmac'    => '',
				],
				'residual' => WM_Sentinel_Logger::compute_residual_hash( $after ),
			];
		}

		if ( $dry_run ) {
			return [
				'applied'    => 0,
				'candidates' => count( $planned ),
				'remaining'  => $remaining,
				'record_id'  => null,
				'cutoff'     => $cutoff,
				'reason'     => 'dry_run',
			];
		}

		// The record goes first, exactly as in the Art. 17 path: if an UPDATE below fails, that row
		// keeps its original values and still verifies normally. If the record cannot be written,
		// nothing is changed at all — a pseudonymised row without its fingerprint would read as
		// tampering for ever.
		$record_id = WM_Sentinel_Logger::log(
			0,
			self::RECORD_EVENT,
			'privacy',
			null,
			'notice',
			[
				'retention_days'     => $days,
				'cutoff'             => $cutoff,
				'pseudonymized_rows' => count( $planned ),
				'residual_hashes'    => array_map( static fn( array $p ): string => $p['residual'], $planned ),
			]
		);

		if ( ! $record_id ) {
			return [
				'applied'    => 0,
				'candidates' => count( $planned ),
				'remaining'  => $remaining,
				'record_id'  => null,
				'cutoff'     => $cutoff,
				'reason'     => 'record_not_written',
			];
		}

		$applied = 0;
		foreach ( $planned as $log_id => $plan ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this plugin's own table.
			if ( false !== $wpdb->update( $logs_table, $plan['changes'], [ 'log_id' => $log_id ] ) ) {
				$applied++;
			}
		}

		return [
			'applied'    => $applied,
			'candidates' => count( $planned ),
			'remaining'  => $remaining,
			'record_id'  => (int) $record_id,
			'cutoff'     => $cutoff,
			'reason'     => null,
		];
	}
}

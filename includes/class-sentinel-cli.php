<?php
/**
 * WP-CLI Commands for WM Activity Sentinel
 *
 * Until 2026-09-15 `demodaten --import` and `--reset` called WM_Sentinel_Seeder::seed() and
 * ::factory_reset(), methods that do not exist (fatal error), and `audit` read the keys
 * failed_log_id and error, which verify_chain() never returns, so a broken chain was reported
 * with an empty id and reason.
 *
 * The docblocks are the commands' synopses: WP-CLI refuses a flag the OPTIONS section does not
 * list before the method runs. Until 1.4.0 `demodaten` did not list --yes, so the flag never
 * reached WP_CLI::confirm() and `--reset --yes` stopped with "unknown --yes parameter".
 *
 * @package WM_Activity_Sentinel
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage Activity Sentinel audit logs and chain verification.
 */
class WM_Sentinel_CLI_Command {

	/**
	 * Apply the configured retention window to the log.
	 *
	 * Rows older than `wm_sentinel_retention_days` have their identifying fields neutralised —
	 * user_name, user_email and ip_hmac. Nothing is deleted: the log is a hash chain, and removing
	 * rows would make the audit report the site's own retention policy as tampering. Event type,
	 * time, severity, object and context stay, and the changed rows keep verifying through the
	 * chained record this pass writes.
	 *
	 * The daily event does the same thing; this is for running it now, or for seeing what it would
	 * do before letting it.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Count what a pass would change and write nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sentinel retention --dry-run
	 *     wp sentinel retention
	 *
	 * @subcommand retention
	 */
	public function retention( $args, $assoc_args ) {
		$dry_run = (bool) ( $assoc_args['dry-run'] ?? false );
		$days    = WM_Sentinel_Retention::configured_days();

		if ( 0 === $days ) {
			WP_CLI::warning( 'Aufbewahrungsfrist ist auf "alles behalten" gesetzt (unter 30 Tagen). Es wird nichts verändert.' );
			return;
		}

		$result = WM_Sentinel_Retention::apply( $dry_run );

		WP_CLI::line( sprintf( 'Frist: %d Tage — Stichtag %s (UTC)', $days, $result['cutoff'] ) );

		if ( 'record_not_written' === $result['reason'] ) {
			WP_CLI::error( sprintf( 'Der Nachweis konnte nicht in die Kette geschrieben werden; es wurden %d Zeilen NICHT verändert.', $result['candidates'] ) );
		}

		if ( 0 === $result['candidates'] ) {
			WP_CLI::success( 'Keine Zeile ist älter als die Frist und trägt noch personenbezogene Felder.' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( '%d Zeile(n) würden pseudonymisiert.%s', $result['candidates'], $result['remaining'] ? ' Weitere warten auf den nächsten Durchlauf.' : '' ) );
			return;
		}

		WP_CLI::success( sprintf(
			'%d von %d Zeile(n) pseudonymisiert, Nachweis #%d.%s',
			$result['applied'],
			$result['candidates'],
			(int) $result['record_id'],
			$result['remaining'] ? ' Weitere warten auf den nächsten Durchlauf.' : ''
		) );
	}

	/**
	 * Verify the hash chain from the genesis block to the recorded tip.
	 *
	 * Exits with status 1 when the chain is broken.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sentinel audit
	 *
	 * @subcommand audit
	 */
	public function audit( $args, $assoc_args ) {
		WP_CLI::line( 'Prüfe die Log-Kette ...' );
		$result = WM_Sentinel_Integrity::verify_chain();

		$key_source = (string) ( $result['key_source'] ?? 'wp-config' );
		if ( 'wp-config-placeholder' === $key_source ) {
			WP_CLI::warning( 'AUTH_KEY in der wp-config.php ist noch der Platzhalter aus wp-config-sample.php. Der Kettenschlüssel ist damit öffentlich bekannt; jeder kann die Kette neu signieren.' );
		} elseif ( 'wp-config' !== $key_source ) {
			WP_CLI::warning( 'AUTH_KEY ist nicht in der wp-config.php definiert; der Kettenschlüssel liegt in der Datenbank.' );
		}

		if ( $result['valid'] ) {
			$suffix = ! empty( $result['pseudonymized'] )
				? sprintf( ' %d davon pseudonymisiert und über einen Löschnachweis anerkannt.', (int) $result['pseudonymized'] )
				: '';
			WP_CLI::success( sprintf( 'Kette intakt: %d Einträge bis zum verzeichneten Kettenende geprüft.%s', (int) $result['total_verified'], $suffix ) );
			return;
		}

		$where = null !== $result['broken_at_id'] ? sprintf( 'bei Log-ID #%d', (int) $result['broken_at_id'] ) : 'am Kettenende';
		WP_CLI::error( sprintf( 'Kette gebrochen %s: %s', $where, (string) $result['error_reason'] ) );
	}

	/**
	 * List recent log entries.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum number of entries (default: 20).
	 *
	 * [--severity=<level>]
	 * : Filter by severity (info, notice, warning, critical).
	 *
	 * ## EXAMPLES
	 *
	 *     wp sentinel list --limit=10
	 *     wp sentinel list --severity=critical
	 *
	 * @subcommand list
	 */
	public function list( $args, $assoc_args ) {
		$limit    = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 20;
		$severity = (string) ( $assoc_args['severity'] ?? '' );

		$logs = WM_Sentinel_Database::get_logs( [
			'limit'    => $limit,
			'offset'   => 0,
			'severity' => $severity,
		] );

		if ( empty( $logs ) ) {
			WP_CLI::line( 'Keine Log-Einträge gefunden.' );
			return;
		}

		$items = [];
		foreach ( $logs as $log ) {
			$items[] = [
				'ID'         => $log->log_id,
				'Zeit (UTC)' => $log->created_at,
				'Grad'       => strtoupper( $log->severity ),
				'Ereignis'   => $log->event_type,
				'Objekt'     => $log->object_type ?: '-',
				'Akteur'     => $log->user_name ?: 'System',
				'Hash'       => substr( (string) $log->entry_hash, 0, 12 ) . '...',
			];
		}

		WP_CLI\Utils\format_items( 'table', $items, [ 'ID', 'Zeit (UTC)', 'Grad', 'Ereignis', 'Objekt', 'Akteur', 'Hash' ] );
	}

	/**
	 * Compare the WordPress tree against the official checksum catalogue and the sealed suite baseline.
	 *
	 * Exits with status 1 when the scan finds a modified core file, an unexpected executable inside
	 * wp-admin or wp-includes, or a baseline that no longer matches its seal.
	 *
	 * ## OPTIONS
	 *
	 * [--baseline]
	 * : Capture the WM Suite baseline instead of scanning, and seal its fingerprint into the chain.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sentinel fim
	 *     wp sentinel fim --baseline
	 *
	 * @subcommand fim
	 */
	public function fim( $args, $assoc_args ) {
		if ( isset( $assoc_args['baseline'] ) ) {
			$sealed = WM_Sentinel_FIM::seal_suite_baseline( WP_PLUGIN_DIR . '/' );
			if ( null !== $sealed['error'] ) {
				WP_CLI::error( (string) $sealed['error'] );
			}
			if ( false === $sealed['log_id'] ) {
				WP_CLI::error( 'Die Basislinie wurde gespeichert, aber der Nachweis konnte nicht in die Kette geschrieben werden. Ohne Ketteneintrag ist eine spätere Änderung der Basislinie nicht nachweisbar.' );
			}
			WP_CLI::success( sprintf( 'Basislinie über %d Dateien erfasst und in der Kette versiegelt (Fingerabdruck %s ...).', $sealed['files'], substr( $sealed['fingerprint'], 0, 16 ) ) );
			return;
		}

		WP_CLI::line( 'Prüfe die Dateien gegen den offiziellen Katalog von api.wordpress.org ...' );
		$report = WM_Sentinel_FIM::run( 'cli' );

		$core  = $report['core'];
		$suite = $report['suite'];

		if ( null !== $core['error'] ) {
			WP_CLI::warning( (string) $core['error'] );
		} else {
			WP_CLI::line( sprintf( '%d Kerndateien geprüft (WordPress %s, %s).', (int) $core['checked'], (string) $report['version'], (string) $report['locale'] ) );
		}

		foreach ( [
			'Verändert (Kern)'                      => $core['modified'],
			'Fehlend (Kern)'                        => $core['missing'],
			'Unerwartet in wp-admin / wp-includes'  => $core['unexpected'],
			'Verändert (WM-Suite-Plugins)'      => $suite['modified'],
			'Fehlend (WM-Suite-Plugins)'        => $suite['missing'],
			'Neu (WM-Suite-Plugins)'            => $suite['added'],
		] as $label => $paths ) {
			if ( empty( $paths ) ) {
				continue;
			}
			WP_CLI::line( sprintf( '%s: %d', $label, count( (array) $paths ) ) );
			foreach ( array_slice( (array) $paths, 0, 20 ) as $path ) {
				WP_CLI::line( '  - ' . $path );
			}
		}

		if ( 'broken' === $suite['seal'] ) {
			WP_CLI::error( 'Die gespeicherte Basislinie stimmt nicht mehr mit dem Fingerabdruck überein, der in der Kette versiegelt wurde: Die Basislinie wurde nachträglich verändert.' );
		}

		if ( 'critical' === $report['severity'] ) {
			WP_CLI::error( sprintf( '%d Abweichungen gefunden. Protokolliert als Ketteneintrag #%s.', (int) $report['findings'], (string) ( $report['log_id'] ?: '-' ) ) );
		}

		if ( 'unsealed' === $suite['seal'] ) {
			WP_CLI::warning( 'Für die WM-Suite-Plugins existiert noch keine Basislinie. Erfassen Sie sie mit "wp sentinel fim --baseline".' );
		}

		WP_CLI::success( sprintf( '%d Abweichungen. Protokolliert als Ketteneintrag #%s.', (int) $report['findings'], (string) ( $report['log_id'] ?: '-' ) ) );
	}

	/**
	 * Demo data and factory reset.
	 *
	 * ## OPTIONS
	 *
	 * [--import]
	 * : Append 15 demo entries (marked "_is_demo") to the chain.
	 *
	 * [--delete]
	 * : Remove demo entries. Refused while real entries exist; otherwise the whole log is reset.
	 *
	 * [--reset]
	 * : Empty both tables, start a new chain and restore every setting to its default.
	 *
	 * [--yes]
	 * : Answer yes to the confirmation of --reset.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sentinel demodaten --import
	 *     wp sentinel demodaten --delete
	 *     wp sentinel demodaten --reset
	 *     wp sentinel demodaten --reset --yes
	 *
	 * @subcommand demodaten
	 */
	public function demodaten( $args, $assoc_args ) {
		if ( isset( $assoc_args['import'] ) ) {
			$count = WM_Sentinel_Seeder::seed_demo_data( 15 );
			if ( 0 === $count ) {
				WP_CLI::warning( 'Keine Demo-Einträge geschrieben: Protokollierungsstufe oder Schalter lassen die Demo-Ereignisse nicht zu.' );
				return;
			}
			WP_CLI::success( "{$count} von 15 Demo-Einträgen an die Kette angehängt (ohne Alarm-Mails und Webhooks)." );
		} elseif ( isset( $assoc_args['delete'] ) ) {
			$count = WM_Sentinel_Seeder::delete_demo_data();
			if ( -1 === $count ) {
				WP_CLI::error(
					'Es liegen bereits echte Protokolleinträge vor. Eine fortlaufende Kette kann keinen Eintrag ' .
					'in der Mitte verlieren, und sie nachträglich neu zu signieren würde die Manipulationserkennung ' .
					'aufheben. Nutzen Sie --reset, wenn das gesamte Protokoll verworfen werden soll.'
				);
			}
			if ( 0 === $count ) {
				WP_CLI::success( 'Keine Demo-Einträge vorhanden; das Protokoll ist unverändert.' );
				return;
			}
			WP_CLI::success( "{$count} Demo-Einträge gelöscht. Das Protokoll wurde zurückgesetzt, die Kette beginnt beim Genesis-Block." );
		} elseif ( isset( $assoc_args['reset'] ) ) {
			WP_CLI::confirm( 'Alle Protokolleinträge und Alarme löschen und alle Einstellungen zurücksetzen?', $assoc_args );
			WM_Sentinel_Seeder::reset_to_factory_defaults();
			WP_CLI::success( 'Protokoll geleert, neue Kette gestartet, Einstellungen zurückgesetzt.' );
		} else {
			WP_CLI::error( 'Bitte geben Sie eine Option an: --import, --delete oder --reset.' );
		}
	}
}

WP_CLI::add_command( 'sentinel', 'WM_Sentinel_CLI_Command' );

<?php
/**
 * WM Activity Sentinel — falsifiers for the tamper-evidence claims.
 *
 * Until 2026-09-14 the plugin advertised "revisionssicher", "Manipulationssicher" and "jede nachträgliche
 * Manipulation ist mathematisch nachweisbar". Two measured findings on 2026-08-27 said otherwise,
 * and both are covered here:
 *
 *   1. Deleting the NEWEST rows left verify_chain() reporting valid=true. A chain with its tail
 *      cut off is internally perfect — which is exactly the edit an intruder makes.
 *   2. WM_Sentinel_Database::rebuild_chain(), reachable from the settings screen and from
 *      WP-CLI, re-signed every surviving row. Edit a row by hand, audit goes red, press
 *      "Demodaten löschen", audit goes green with the edit still in place.
 *
 * Mutation checks:
 *   - delete the tip comparison in WM_Sentinel_Integrity::verify_chain() → cases 2 and 5 fail.
 *   - make delete_demo_data() return 0 instead of -1 → case 3 fails.
 *
 * WARNING: this file TRUNCATES the log table, like the sibling test-sentinel-chain.php. Run it on
 * a scratch install, never against a real audit trail. It refuses to run without WM_SENTINEL_SCRATCH_INSTALL=1.
 *
 * Run: WM_SENTINEL_SCRATCH_INSTALL=1 wp eval-file wp-content/plugins/wm-activity-sentinel/tests/test-sentinel-tamper-evidence.php --allow-root
 *
 * @package WM_Activity_Sentinel
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must run inside WordPress via WP-CLI.\n";
	exit( 1 );
}

// The docblock warning did not stop runs against demo installs (2026-09-15, wm-hinschg-whistleblower); a gate does.
if ( '1' !== getenv( 'WM_SENTINEL_SCRATCH_INSTALL' ) ) {
	echo "Refusing to run: this test truncates the activity log (wm_activity_logs). On a scratch install: WM_SENTINEL_SCRATCH_INSTALL=1 wp eval-file ...\n";
	exit( 1 );
}

// eval-file runs the file inside a function, so `global` would not bind for the helper below.
$GLOBALS['wm_t_passed'] = 0;
$GLOBALS['wm_t_failed'] = 0;

/**
 * Assert helper.
 *
 * @param bool   $condition Condition.
 * @param string $label     Description.
 * @return void
 */
function wm_t_assert( $condition, $label ) {
	if ( $condition ) {
		++$GLOBALS['wm_t_passed'];
		echo "  [PASS] {$label}\n";
	} else {
		++$GLOBALS['wm_t_failed'];
		echo "  [FAIL] {$label}\n";
	}
}

global $wpdb;
$logs_table = WM_Sentinel_Database::logs_table();

echo "\n=== SENTINEL TAMPER-EVIDENCE TEST ===\n\n";

/**
 * Fresh chain: genesis plus $n real events.
 *
 * @param int $n Events.
 * @return void
 */
function wm_t_fixture( $n = 4 ) {
	global $wpdb;
	$t = WM_Sentinel_Database::logs_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "TRUNCATE TABLE {$t}" );
	delete_option( WM_Sentinel_Database::TIP_OPTION );
	WM_Sentinel_Database::ensure_genesis_block();
	for ( $i = 1; $i <= $n; $i++ ) {
		WM_Sentinel_Logger::log( 1, "tamper_probe_{$i}", 'plugin', null, 'critical', array( 'n' => $i ) );
	}
}

// --- Case 1: an untouched chain verifies ----------------------------------
wm_t_fixture();
$r = WM_Sentinel_Integrity::verify_chain();
wm_t_assert( true === $r['valid'], 'an untouched chain verifies' );
wm_t_assert( 5 === $r['total_verified'], 'genesis plus four events were checked (got ' . $r['total_verified'] . ')' );

// --- Case 2: deleting the NEWEST rows is detected --------------------------
wm_t_fixture();
$max = (int) $wpdb->get_var( "SELECT MAX(log_id) FROM {$logs_table}" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$logs_table} WHERE log_id > %d", $max - 2 ) );
$r = WM_Sentinel_Integrity::verify_chain();
wm_t_assert( false === $r['valid'], 'deleting the two newest entries is detected' );
wm_t_assert( false !== strpos( (string) $r['error_reason'], 'verzeichneten Kettenstand' ), 'the reason names the recorded chain state' );

// --- Case 3: the laundering primitive is gone -----------------------------
wm_t_assert(
	! method_exists( 'WM_Sentinel_Database', 'rebuild_chain' ),
	'WM_Sentinel_Database::rebuild_chain() no longer exists'
);

// --- Case 4: an edited row is still detected (regression) ------------------
wm_t_fixture();
$victim = (int) $wpdb->get_var( "SELECT log_id FROM {$logs_table} WHERE event_type = 'tamper_probe_2'" );
$wpdb->query( $wpdb->prepare( "UPDATE {$logs_table} SET user_name = 'nicht-ich' WHERE log_id = %d", $victim ) );
$r = WM_Sentinel_Integrity::verify_chain();
wm_t_assert( false === $r['valid'], 'an edited row is detected' );
wm_t_assert( $victim === (int) $r['broken_at_id'], 'the edited row is the one reported' );

// --- Case 5: deleting demo data cannot launder that edit -------------------
// This is the exact sequence that used to clear the evidence.
WM_Sentinel_Seeder::seed_demo_data( 3 );
$refused = WM_Sentinel_Seeder::delete_demo_data();
$r       = WM_Sentinel_Integrity::verify_chain();
$name    = $wpdb->get_var( $wpdb->prepare( "SELECT user_name FROM {$logs_table} WHERE log_id = %d", $victim ) );
wm_t_assert( -1 === $refused, 'demo deletion refuses while real entries exist' );
wm_t_assert( 'nicht-ich' === $name, 'the tampered row was left exactly as it was' );
wm_t_assert( false === $r['valid'], 'the audit still reports the tampering afterwards' );

// --- Case 6: wiping the whole table is detected ----------------------------
wm_t_fixture();
$wpdb->query( "DELETE FROM {$logs_table}" );
$r = WM_Sentinel_Integrity::verify_chain();
wm_t_assert( false === $r['valid'], 'emptying the log table is detected, not read as innocent' );

// --- Case 7: demo purge is allowed on a log that holds nothing else --------
$wpdb->query( "TRUNCATE TABLE {$logs_table}" );
delete_option( WM_Sentinel_Database::TIP_OPTION );
WM_Sentinel_Database::ensure_genesis_block();
WM_Sentinel_Seeder::seed_demo_data( 3 );
$deleted = WM_Sentinel_Seeder::delete_demo_data();
$r       = WM_Sentinel_Integrity::verify_chain();
wm_t_assert( $deleted > 0, 'demo rows can still be removed while the log holds nothing else' );
wm_t_assert( true === $r['valid'], 'the chain restarts cleanly after that reset' );

// --- Case 8: the key is not a literal shipped in the plugin ----------------
wm_t_assert(
	'wp-config' === WM_Sentinel_Logger::chain_key_source(),
	'the chain key comes from wp-config.php on this install'
);
// Strip comments before grepping. A plain search matches the docblock that documents the old
// fallback, so the first version of this assertion failed on its own documentation — the same
// class of broken instrument the fixes in this file are about.
$logger_code = implode(
	"\n",
	array_filter(
		file( WM_SENTINEL_PATH . 'includes/class-sentinel-logger.php', FILE_IGNORE_NEW_LINES ),
		static function ( $line ) {
			$t = ltrim( $line );
			return '' !== $t && 0 !== strpos( $t, '*' ) && 0 !== strpos( $t, '//' ) && 0 !== strpos( $t, '/*' );
		}
	)
);
wm_t_assert(
	false === strpos( $logger_code, 'wm_sentinel_auth_key_2026' ),
	'no fallback key literal is shipped in executable logger code'
);

// --- Teardown -------------------------------------------------------------
$wpdb->query( "TRUNCATE TABLE {$logs_table}" );
delete_option( WM_Sentinel_Database::TIP_OPTION );
WM_Sentinel_Database::ensure_genesis_block();

echo "\n=== RESULT: {$GLOBALS['wm_t_passed']} passed, {$GLOBALS['wm_t_failed']} failed ===\n\n";

// The result was printed and the file exited 0 either way (until 2026-09-15).
exit( $GLOBALS['wm_t_failed'] > 0 ? 1 : 0 );

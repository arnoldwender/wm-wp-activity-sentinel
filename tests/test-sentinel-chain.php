<?php
/**
 * WM Activity Sentinel — Cryptographic Hash Chaining & Tamper Detection Test Suite
 *
 * WARNING: truncates the log table. Refuses to run without WM_SENTINEL_SCRATCH_INSTALL=1.
 *
 * Run via WP-CLI: WM_SENTINEL_SCRATCH_INSTALL=1 wp eval-file tests/test-sentinel-chain.php --allow-root
 */

if ( ! defined( 'WM_SENTINEL_TESTING_MODE' ) ) {
	define( 'WM_SENTINEL_TESTING_MODE', true );
}

if ( ! defined( 'ABSPATH' ) ) {
	echo "ABSPATH not defined. Must be run within WordPress context via WP-CLI.\n";
	exit( 1 );
}

// The docblock warning did not stop runs against demo installs (2026-09-15, wm-hinschg-whistleblower); a gate does.
if ( '1' !== getenv( 'WM_SENTINEL_SCRATCH_INSTALL' ) ) {
	echo "Refusing to run: this test truncates the activity log (wm_activity_logs). On a scratch install: WM_SENTINEL_SCRATCH_INSTALL=1 wp eval-file ...\n";
	exit( 1 );
}

echo "\n===============================================================\n";
echo "WM ACTIVITY SENTINEL — CRYPTOGRAPHIC HASH CHAIN & INTEGRITY TEST\n";
echo "Environment: " . PHP_OS . " | PHP " . PHP_VERSION . " | WP " . get_bloginfo( 'version' ) . "\n";
echo "===============================================================\n\n";

$pass_count = 0;
$total_tests = 5;

global $wpdb;
$logs_table = WM_Sentinel_Database::logs_table();

// Reset test database table and settings
$wpdb->query( "TRUNCATE TABLE {$logs_table}" );
update_option( 'wm_sentinel_logging_intensity', 'all' );
update_option( 'wm_sentinel_log_plugins', 1 );
update_option( 'wm_sentinel_log_content', 1 );
WM_Sentinel_Database::ensure_genesis_block();

// -------------------------------------------------------------
// TEST 1: Verify Genesis Block Initialization
// -------------------------------------------------------------
echo "[TEST 1/{$total_tests}] Genesis Block Initialization...\n";
$genesis = $wpdb->get_row( "SELECT * FROM {$logs_table} WHERE log_id = 1" );

if ( $genesis && 'GENESIS' === $genesis->prev_hash && 64 === strlen( $genesis->entry_hash ) ) {
	echo "  -> PASS: Genesis block #1 verified with entry_hash: " . substr( $genesis->entry_hash, 0, 16 ) . "...\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Genesis block not initialized correctly.\n";
}

// -------------------------------------------------------------
// TEST 2: Sequential Hash Chaining (Blocks 2, 3, 4)
// -------------------------------------------------------------
echo "\n[TEST 2/{$total_tests}] Sequential HMAC-SHA256 Hash Chaining...\n";
$log2_id = WM_Sentinel_Logger::log( 1, 'test_event_alpha', 'plugin', null, 'info', [ 'action' => 'first' ] );
$log3_id = WM_Sentinel_Logger::log( 1, 'test_event_beta', 'file', null, 'warning', [ 'action' => 'second' ] );
$log4_id = WM_Sentinel_Logger::log( 1, 'test_event_gamma', 'export', null, 'critical', [ 'action' => 'third' ] );

$log2 = $wpdb->get_row( "SELECT * FROM {$logs_table} WHERE log_id = {$log2_id}" );
$log3 = $wpdb->get_row( "SELECT * FROM {$logs_table} WHERE log_id = {$log3_id}" );
$log4 = $wpdb->get_row( "SELECT * FROM {$logs_table} WHERE log_id = {$log4_id}" );

if ( $log2 && $log3 && $log4 &&
	 $log2->prev_hash === $genesis->entry_hash &&
	 $log3->prev_hash === $log2->entry_hash &&
	 $log4->prev_hash === $log3->entry_hash ) {
	echo "  -> PASS: Blocks #2, #3, #4 correctly chained with predecessor entry hashes.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Hash chaining sequence broken on sequential insertions.\n";
}

// -------------------------------------------------------------
// TEST 3: Cryptographic Chain Auditor (100% Pass on Intact Chain)
// -------------------------------------------------------------
echo "\n[TEST 3/{$total_tests}] Full Chain Verification on Intact Log Trail...\n";
$audit_intact = WM_Sentinel_Integrity::verify_chain();

if ( true === $audit_intact['valid'] && 4 === $audit_intact['total_verified'] && null === $audit_intact['broken_at_id'] ) {
	echo "  -> PASS: Auditor successfully verified all 4 blocks without error.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Auditor returned unexpected failure on intact chain.\n";
}

// -------------------------------------------------------------
// TEST 4: Tamper Detection (Simulating SQL Payload Manipulation)
// -------------------------------------------------------------
echo "\n[TEST 4/{$total_tests}] Detecting Database Manipulation (Row Tampering)...\n";
// Attacker attempts to modify block #3 context directly in database
$wpdb->update(
	$logs_table,
	[ 'context' => '{"tampered":true}' ],
	[ 'log_id' => $log3_id ]
);

$audit_tampered = WM_Sentinel_Integrity::verify_chain();

if ( false === $audit_tampered['valid'] && $log3_id === $audit_tampered['broken_at_id'] ) {
	echo "  -> PASS: Tampering at Log-ID #{$log3_id} detected immediately: " . substr( $audit_tampered['error_reason'], 0, 80 ) . "...\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Auditor failed to catch database row payload manipulation.\n";
}

// Revert block #3 to continue test
$wpdb->update(
	$logs_table,
	[ 'context' => $log3->context ],
	[ 'log_id' => $log3_id ]
);

// -------------------------------------------------------------
// TEST 5: Deletion Detection (Simulating Row Removal)
// -------------------------------------------------------------
echo "\n[TEST 5/{$total_tests}] Detecting Row Deletion (Broken Link in Chain)...\n";
// Attacker deletes block #2
$wpdb->delete( $logs_table, [ 'log_id' => $log2_id ] );

$audit_deleted = WM_Sentinel_Integrity::verify_chain();

if ( false === $audit_deleted['valid'] && $log3_id === $audit_deleted['broken_at_id'] ) {
	echo "  -> PASS: Deletion of preceding block detected as broken link at Log-ID #{$log3_id}.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Auditor failed to detect missing predecessor row.\n";
}

// Cleanup and reset
$wpdb->query( "TRUNCATE TABLE {$logs_table}" );
WM_Sentinel_Database::ensure_genesis_block();

echo "\n-------------------------------------------------------------\n";
if ( $pass_count === $total_tests ) {
	echo "VERDICT: PASS ({$pass_count}/{$total_tests} Hash Chain & Tamper Tests Successful)\n";
} else {
	echo "VERDICT: FAIL ({$pass_count}/{$total_tests} Tests Passed)\n";
}
echo "-------------------------------------------------------------\n\n";

// The verdict was printed and the file exited 0 either way (until 2026-09-15).
exit( $pass_count === $total_tests ? 0 : 1 );

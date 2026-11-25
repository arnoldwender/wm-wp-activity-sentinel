<?php
/**
 * WM Activity Sentinel — WordPress privacy tools, telemetry route and CLI methods on a real install.
 *
 * Run: wp eval-file wp-content/plugins/wm-activity-sentinel/tests/test-sentinel-privacy-tools.php --allow-root
 *
 * Non-destructive: it creates two temporary users (addresses @wm-sentinel-it.test), appends entries
 * to the existing chain, pseudonymises only the rows of its own address through WordPress's
 * personal-data eraser, and deletes the users at the end. The chain must verify before and after.
 * No strict_types declaration: wp eval-file runs the file as evaluated code.
 *
 * @package WM_Activity_Sentinel
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must run inside WordPress via WP-CLI.\n";
	exit( 1 );
}

// The docblock warning did not stop runs against demo installs (2026-09-15, wm-hinschg-whistleblower); a gate does.
if ( '1' !== getenv( 'WM_SENTINEL_SCRATCH_INSTALL' ) ) {
	echo "Refusing to run: this test creates temporary users and writes erasure records to the activity log. On a scratch install: WM_SENTINEL_SCRATCH_INSTALL=1 wp eval-file ...\n";
	exit( 1 );
}

// eval-file runs this file inside a method: counters live in $GLOBALS explicitly.
$GLOBALS['wm_pt_pass'] = 0;
$GLOBALS['wm_pt_fail'] = 0;

function wm_pt_assert( bool $ok, string $label, string $detail = '' ): void {
	echo ( $ok ? '  [PASS] ' : '  [FAIL] ' ) . $label . ( ! $ok && '' !== $detail ? " — {$detail}" : '' ) . "\n";
	$ok ? $GLOBALS['wm_pt_pass']++ : $GLOBALS['wm_pt_fail']++;
}

global $wpdb;
$table   = WM_Sentinel_Database::logs_table();
$suffix  = wp_rand( 1000, 9999 );
$email   = "erika-{$suffix}@wm-sentinel-it.test";
$sub     = "sub-{$suffix}@wm-sentinel-it.test";
$created = [];

echo "\n=== SENTINEL PRIVACY TOOLS, TELEMETRY AND CLI (WordPress) ===\n\n";

try {
	$before = WM_Sentinel_Integrity::verify_chain();
	wm_pt_assert( true === $before['valid'], 'the chain verifies before the test', (string) $before['error_reason'] );

	// Fixture: a user whose address appears as actor and in the context of another entry.
	$uid = wp_insert_user( [ 'user_login' => "erika{$suffix}", 'user_email' => $email, 'user_pass' => wp_generate_password(), 'role' => 'editor', 'display_name' => 'Erika IT' ] );
	$created[] = $uid;
	WM_Sentinel_Logger::log( $uid, 'plugin_activated', 'plugin', null, 'notice', [ 'plugin' => 'fixture/fixture.php' ] );
	WM_Sentinel_Logger::log( 1, 'user_created', 'user', $uid, 'notice', [ 'new_user_email' => $email ] );
	$mentioning = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_email = %s OR context LIKE %s', $table, $email, '%' . $wpdb->esc_like( $email ) . '%' ) );
	wm_pt_assert( $mentioning >= 2, 'fixture rows mention the address', (string) $mentioning );

	// WordPress's own tool registry.
	$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );
	$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', [] );
	$export    = call_user_func( $exporters['wm-activity-sentinel']['callback'], $email, 1 );
	wm_pt_assert( count( $export['data'] ) === $mentioning, 'the registered exporter runs and returns every row mentioning the address (fatal error until 2026-09-15)', count( $export['data'] ) . ' vs ' . $mentioning );

	$erase = call_user_func( $erasers['wm-activity-sentinel']['callback'], $email, 1 );
	wm_pt_assert( true === $erase['items_removed'], 'the registered eraser reports removed items', wp_json_encode( $erase ) );
	$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_email = %s OR context LIKE %s', $table, $email, '%' . $wpdb->esc_like( $email ) . '%' ) );
	wm_pt_assert( 0 === $left, 'no row mentions the address afterwards', (string) $left );

	$after = WM_Sentinel_Integrity::verify_chain();
	wm_pt_assert( true === $after['valid'] && (int) $after['pseudonymized'] >= 2, 'the chain still verifies and counts the pseudonymised rows (reported as tampering until 2026-09-15)', wp_json_encode( $after ) );

	// Telemetry as a subscriber through the real REST server.
	$sid = wp_insert_user( [ 'user_login' => "sub{$suffix}", 'user_email' => $sub, 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
	$created[] = $sid;
	wp_set_current_user( $sid );
	$count_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	$req = new WP_REST_Request( 'POST', '/wm-sentinel/v1/telemetry' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( [ 'events' => [
		[ 'event_type' => 'user_role_changed', 'category' => 'navigation', 'severity' => 'critical', 'metadata' => [ 'role' => 'administrator' ] ],
		[ 'event_type' => 'link_clicked', 'category' => 'navigation', 'severity' => 'critical', 'metadata' => [ 'element_text' => 'IT probe' ] ],
	] ] ) );
	$res  = rest_do_request( $req );
	$data = $res->get_data();
	$last = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY log_id DESC LIMIT 1', $table ) );
	wm_pt_assert( 200 === $res->get_status() && 1 === (int) ( $data['logged'] ?? -1 ) && 1 === (int) ( $data['refused'] ?? -1 ), 'a subscriber cannot forge a role change through telemetry; a click is accepted', wp_json_encode( $data ) );
	wm_pt_assert( $last && 'link_clicked' === $last->event_type && 'info' === $last->severity && $count_before + 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'the accepted entry is severity info' );
	wp_set_current_user( 0 );
	$anon = rest_do_request( $req );
	wm_pt_assert( in_array( $anon->get_status(), [ 401, 403 ], true ), 'a visitor without session is refused', (string) $anon->get_status() );
	wp_set_current_user( 1 );

	// CLI methods and hooks the earlier code referenced.
	wm_pt_assert( method_exists( 'WM_Sentinel_Seeder', 'seed_demo_data' ) && method_exists( 'WM_Sentinel_Seeder', 'reset_to_factory_defaults' ), 'the seeder methods the CLI calls exist' );
	wm_pt_assert( false !== has_action( 'wp_create_application_password' ) && false !== has_action( 'wp_ajax_edit-theme-plugin-file' ) && false === has_action( 'application_password_created' ), 'the sensors listen to hooks WordPress fires' );
} catch ( Throwable $e ) {
	wm_pt_assert( false, 'no exception', get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	foreach ( $created as $id ) {
		if ( $id && ! is_wp_error( $id ) ) {
			wp_delete_user( (int) $id );
		}
	}
}

$final = WM_Sentinel_Integrity::verify_chain();
wm_pt_assert( true === $final['valid'], 'the chain verifies after cleanup', (string) $final['error_reason'] );

echo "\n=== RESULT: {$GLOBALS['wm_pt_pass']} passed, {$GLOBALS['wm_pt_fail']} failed ===\n\n";
exit( $GLOBALS['wm_pt_fail'] > 0 ? 1 : 0 );

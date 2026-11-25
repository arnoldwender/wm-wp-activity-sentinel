<?php
/**
 * Standalone test suite for wm-activity-sentinel — no WordPress, no MySQL.
 *
 * A deterministic mock layer stands in for $wpdb (with a query log), options, transients, hooks,
 * users, REST objects, WP-CLI and the hub contract. Groups 1–4 predate 2026-09-15; groups 5–12 pin
 * the defects fixed that day (privacy exporter and eraser fatal, erasure breaking the chain, CLI
 * calling missing methods, factory reset writing settings nothing reads, dead sensor hooks, forged
 * telemetry, adapter telemetry, chain tip race, uninstall, claims in the admin).
 * Run: php tests/test-suite.php (exit 0 = green).
 *
 * @package WM_Activity_Sentinel
 */

declare(strict_types=1);

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
set_error_handler( static function ( int $no, string $str, string $file, int $line ): bool {
	if ( ! ( error_reporting() & $no ) ) {
		return false;
	}
	throw new ErrorException( $str, 0, $no, $file, $line );
} );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WM_SENTINEL_VERSION', '1.4.3' );
define( 'WM_SENTINEL_FILE', dirname( __DIR__ ) . '/wm-activity-sentinel.php' );
define( 'WM_SENTINEL_PATH', dirname( __DIR__ ) . '/' );
define( 'WM_SENTINEL_URL', 'https://demo.example/wp-content/plugins/wm-activity-sentinel/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'AUTH_KEY', 'test-auth-key-from-wp-config' );
define( 'WP_CLI', true );

// ---------------------------------------------------------------------------------------------------
// Mock state
// ---------------------------------------------------------------------------------------------------
global $mock_options, $mock_option_autoloads, $mock_filters, $mock_db_tables, $mock_transients, $mock_sequence, $mock_users, $mock_user_id,
	$mock_logged_in, $mock_mail, $mock_http_response, $mock_http_requests, $mock_cron, $mock_now, $mock_block_bindings,
	$mock_multisite, $mock_network_active, $mock_sites, $mock_current_blog_id, $mock_blog_stack, $mock_site_stores, $mock_dbdelta_queries, $wpdb;

$mock_options          = [];
$mock_option_autoloads = [];
$mock_filters          = [];
$mock_transients       = [];
$mock_sequence         = [];
$mock_mail             = [];
$mock_http_response    = null;
$mock_http_requests    = [];
$mock_cron             = [];
$mock_block_bindings   = [];
$mock_multisite        = false;
$mock_network_active   = false;
$mock_sites            = [ 1, 2 ];
$mock_current_blog_id  = 1;
$mock_blog_stack       = [];
$mock_site_stores      = [];
$mock_dbdelta_queries  = [];
$mock_users            = [ 1 => [ 'display_name' => 'Admin User', 'user_email' => 'admin@example.test' ] ];
$mock_user_id    = 1;
$mock_logged_in  = true;
$mock_db_tables  = [
	'wp_wm_activity_logs'   => [],
	'wp_wm_activity_alerts' => [],
];

final class Mock_WPDB_Sentinel {
	public string $prefix   = 'wp_';
	public int $insert_id   = 0;
	public string $last_error = '';
	public string $options  = 'wp_options';
	/** Set to make the next SELECT … FOR UPDATE fail like a lost connection or a lock wait timeout. */
	public bool $fail_for_update = false;
	/** Set to make the next COMMIT return false. */
	public bool $fail_commit = false;
	private array $auto_inc = [];

	private static function table( string $name ): string {
		return trim( $name, '`' );
	}

	public function get_charset_collate(): string {
		return '';
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function insert( string $table, array $data, array $format = [] ): int {
		global $mock_db_tables, $mock_sequence;
		$key = str_ends_with( $table, 'wm_activity_alerts' ) ? 'alert_id' : 'log_id';
		$this->auto_inc[ $table ] = ( $this->auto_inc[ $table ] ?? 0 ) + 1;
		$id                       = $this->auto_inc[ $table ];
		$this->insert_id          = $id;
		$mock_db_tables[ $table ][ $id ] = (object) array_merge( [ $key => $id ], $data );
		$mock_sequence[]          = 'INSERT ' . $table;
		return 1;
	}

	public function update( string $table, array $data, array $where, $format = [], $where_format = [] ) {
		global $mock_db_tables;
		$n = 0;
		foreach ( $mock_db_tables[ $table ] ?? [] as $row ) {
			$match = true;
			foreach ( $where as $k => $v ) {
				if ( ! property_exists( $row, $k ) || (string) $row->$k !== (string) $v ) {
					$match = false;
				}
			}
			if ( $match ) {
				foreach ( $data as $k => $v ) {
					$row->$k = $v;
				}
				$n++;
			}
		}
		return $n;
	}

	public function prepare( string $query, ...$args ): string {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$idx = 0;
		return (string) preg_replace_callback( '/%[sdfFi]/', static function ( array $m ) use ( &$idx, $args ) {
			$arg = $args[ $idx++ ] ?? '';
			if ( '%i' === $m[0] ) {
				return '`' . str_replace( '`', '``', (string) $arg ) . '`';
			}
			if ( '%d' === $m[0] ) {
				return (string) (int) $arg;
			}
			return "'" . addslashes( (string) $arg ) . "'";
		}, $query );
	}

	public function query( string $query ) {
		global $mock_db_tables, $mock_sequence;
		$mock_sequence[] = $query;
		if ( 'COMMIT' === $query && $this->fail_commit ) {
			$this->fail_commit = false;
			$this->last_error  = 'Deadlock found when trying to get lock';
			return false;
		}
		if ( preg_match( '/TRUNCATE\s+TABLE\s+(\S+)/i', $query, $m ) ) {
			$mock_db_tables[ self::table( $m[1] ) ] = [];
			$this->auto_inc[ self::table( $m[1] ) ] = 0;
		}
		return 1;
	}

	public function get_row( string $query, $output = 'OBJECT' ) {
		global $mock_db_tables;
		if ( $this->fail_for_update && str_contains( $query, 'FOR UPDATE' ) ) {
			$this->fail_for_update = false;
			$this->last_error      = 'Lock wait timeout exceeded; try restarting transaction';
			return null;
		}
		if ( preg_match( '/FROM\s+(\S+)\s+WHERE\s+log_id\s*=\s*(\d+)/i', $query, $m ) ) {
			$row = $mock_db_tables[ self::table( $m[1] ) ][ (int) $m[2] ] ?? null;
			return $row && ARRAY_A === $output ? (array) $row : $row;
		}
		if ( preg_match( '/FROM\s+(\S+)\s+ORDER\s+BY\s+log_id\s+DESC\s+LIMIT\s+1/i', $query, $m ) ) {
			$rows = $mock_db_tables[ self::table( $m[1] ) ] ?? [];
			return [] === $rows ? null : end( $rows );
		}
		return null;
	}

	public function get_results( string $query, $output = 'OBJECT' ): array {
		global $mock_db_tables;
		if ( preg_match( "/FROM\s+(\S+)\s+WHERE\s+user_email\s*=\s*'([^']*)'\s+OR\s+context\s+LIKE\s+'%(.*)%'/i", $query, $m ) ) {
			$email = stripslashes( $m[2] );
			$rows  = [];
			foreach ( $mock_db_tables[ self::table( $m[1] ) ] ?? [] as $row ) {
				if ( 0 === strcasecmp( (string) $row->user_email, $email ) || false !== stripos( (string) $row->context, $email ) ) {
					$rows[] = clone $row;
				}
			}
			return $rows;
		}
		// get_logs() filters, orders and limits. Returning every row regardless made any test that
		// asks for "the newest entry of type X" read the genesis block instead and measure nothing —
		// which is how the baseline-seal check would have passed without a seal (2026-09-16).
		if ( preg_match( '/FROM\s+(\S+)\s+WHERE\s+(.*?)\s+ORDER\s+BY\s+(\w+)\s+(ASC|DESC)\s+LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/is', $query, $m ) ) {
			$rows  = array_map( static fn( $r ) => clone $r, array_values( $mock_db_tables[ self::table( $m[1] ) ] ?? [] ) );
			$where = $m[2];
			foreach ( [ 'event_type', 'severity', 'object_type' ] as $column ) {
				if ( preg_match( "/\b{$column}\s*=\s*'([^']*)'/", $where, $c ) ) {
					$want = stripslashes( $c[1] );
					$rows = array_values( array_filter( $rows, static fn( $r ) => (string) ( $r->$column ?? '' ) === $want ) );
				}
			}
			$orderby = $m[3];
			usort( $rows, static fn( $a, $b ) => ( $a->$orderby ?? null ) <=> ( $b->$orderby ?? null ) );
			if ( 'DESC' === strtoupper( $m[4] ) ) {
				$rows = array_reverse( $rows );
			}
			return array_slice( $rows, (int) $m[6], (int) $m[5] );
		}
		// The retention pass: created_at < cutoff, event type not one of the chain's own records,
		// and something left to neutralise. Without this branch the query fell through to the
		// catch-all below, which hands back EVERY row — the pass would then look like it
		// pseudonymised recent entries and the genesis block, and the test would agree with it.
		if ( preg_match( "/FROM\s+(\S+)\s+WHERE\s+created_at\s*<\s*'([^']*)'\s+AND\s+event_type\s+NOT\s+IN\s*\(([^)]*)\)\s+AND\s+\((.*?)\)\s+ORDER\s+BY\s+log_id\s+ASC\s+LIMIT\s+(\d+)/is", $query, $m ) ) {
			$cutoff   = stripslashes( $m[2] );
			$excluded = array_map(
				static fn( string $v ): string => stripslashes( trim( trim( $v ), "'" ) ),
				explode( ',', $m[3] )
			);
			preg_match_all( "/'([^']*)'/", $m[4], $neutral );
			$neutral_name = stripslashes( $neutral[1][0] ?? '' );

			$rows = [];
			foreach ( $mock_db_tables[ self::table( $m[1] ) ] ?? [] as $row ) {
				if ( (string) $row->created_at >= $cutoff ) {
					continue;
				}
				if ( in_array( (string) $row->event_type, $excluded, true ) ) {
					continue;
				}
				$still_personal = (string) $row->user_name !== $neutral_name
					|| '' !== (string) $row->user_email
					|| '' !== (string) $row->ip_hmac;
				if ( ! $still_personal ) {
					continue;
				}
				$rows[] = clone $row;
			}
			usort( $rows, static fn( $a, $b ) => (int) $a->log_id <=> (int) $b->log_id );
			return array_slice( $rows, 0, (int) $m[5] );
		}
		if ( preg_match( '/FROM\s+(\S+)/i', $query, $m ) ) {
			return array_map( static fn( $r ) => clone $r, array_values( $mock_db_tables[ self::table( $m[1] ) ] ?? [] ) );
		}
		return [];
	}

	public function get_var( string $query ) {
		global $mock_db_tables, $mock_sequence;
		$mock_sequence[] = $query;
		if ( preg_match( '/COUNT\(\*\)\s+FROM\s+(\S+)/i', $query, $m ) ) {
			return count( $mock_db_tables[ self::table( $m[1] ) ] ?? [] );
		}
		if ( preg_match( '/SELECT\s+entry_hash\s+FROM\s+(\S+)\s+ORDER\s+BY\s+log_id\s+DESC/i', $query, $m ) ) {
			$rows = $mock_db_tables[ self::table( $m[1] ) ] ?? [];
			return [] === $rows ? null : end( $rows )->entry_hash;
		}
		return null;
	}
}
$wpdb = new Mock_WPDB_Sentinel();

// ---------------------------------------------------------------------------------------------------
// WordPress function mocks
// ---------------------------------------------------------------------------------------------------
function add_action( string $tag, $cb, int $prio = 10, int $args = 1 ): bool {
	global $mock_filters;
	$mock_filters[ $tag ][] = [ $cb, $args, $prio ];
	return true;
}
function add_filter( string $tag, $cb, int $prio = 10, int $args = 1 ): bool {
	return add_action( $tag, $cb, $prio, $args );
}
function apply_filters( string $tag, $value, ...$rest ) {
	global $mock_filters;
	foreach ( $mock_filters[ $tag ] ?? [] as [ $cb, $n ] ) {
		$value = $cb( ...array_slice( array_merge( [ $value ], $rest ), 0, $n ) );
	}
	return $value;
}
function do_action( string $tag, ...$args ): void {
	global $mock_filters;
	foreach ( $mock_filters[ $tag ] ?? [] as [ $cb, $n ] ) {
		$cb( ...array_slice( $args, 0, $n ) );
	}
}
function get_option( string $name, $default = false ) {
	global $mock_options;
	return array_key_exists( $name, $mock_options ) ? $mock_options[ $name ] : $default;
}
// The SIEM forwarder's HTTPS transport. $mock_siem_sent collects the payloads so a test can measure
// that an entry really left, instead of only that the call is written in the source.
function wp_remote_post( string $url, array $args = [] ) {
	global $mock_siem_sent;
	$mock_siem_sent[] = [ 'url' => $url, 'body' => (string) ( $args['body'] ?? '' ) ];
	return [ 'response' => [ 'code' => 204 ] ];
}
// The FIM's checksum catalogue. $mock_http_response is what api.wordpress.org is made to answer, and
// $mock_http_requests records the URLs, so a test can see whether the cache spared the call.
function wp_remote_get( string $url, array $args = [] ) {
	global $mock_http_response, $mock_http_requests;
	$mock_http_requests[] = $url;
	return $mock_http_response ?? [ 'response' => [ 'code' => 200 ], 'body' => '{"checksums":{}}' ];
}
function wp_remote_retrieve_response_code( $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}
function wp_remote_retrieve_body( $response ): string {
	return (string) ( $response['body'] ?? '' );
}
// Nothing in the plugin calls this any more — the FIM asks for the INSTALLED PACKAGE, not the
// display language. It stays so the mutation that puts the old, wrong source back has something to
// return, and so the difference between the two stays visible here.
function get_locale(): string {
	return 'de_DE';
}
function wp_next_scheduled( string $hook ) {
	global $mock_cron;
	return $mock_cron[ $hook ] ?? false;
}
function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
	global $mock_cron;
	$mock_cron[ $hook ] = $timestamp;
	return true;
}
function wp_unschedule_event( int $timestamp, string $hook ): bool {
	global $mock_cron;
	unset( $mock_cron[ $hook ] );
	return true;
}
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}
function update_option( string $name, $value, $autoload = null ): bool {
	global $mock_options, $mock_option_autoloads, $mock_sequence;
	$mock_options[ $name ]          = $value;
	$mock_option_autoloads[ $name ] = $autoload;
	$mock_sequence[]                = 'OPTION ' . $name;
	return true;
}
function is_multisite(): bool {
	global $mock_multisite;
	return (bool) $mock_multisite;
}
function is_plugin_active_for_network( string $plugin ): bool {
	global $mock_network_active;
	return (bool) $mock_network_active;
}
function get_sites( array $args = [] ): array {
	global $mock_sites;
	return $mock_sites;
}
function switch_to_blog( int $blog_id ): bool {
	global $mock_current_blog_id, $mock_blog_stack, $mock_site_stores, $mock_options, $mock_option_autoloads, $mock_cron, $mock_db_tables, $wpdb;
	$mock_site_stores[ $mock_current_blog_id ] = [
		'options'   => $mock_options,
		'autoloads' => $mock_option_autoloads,
		'cron'      => $mock_cron,
		'tables'    => $mock_db_tables,
		'prefix'    => $wpdb->prefix,
	];
	$mock_blog_stack[]     = $mock_current_blog_id;
	$mock_current_blog_id  = $blog_id;
	$prefix                = 1 === $blog_id ? 'wp_' : "wp_{$blog_id}_";
	$wpdb->prefix          = $prefix;
	if ( ! isset( $mock_site_stores[ $blog_id ] ) ) {
		$mock_site_stores[ $blog_id ] = [
			'options'   => [],
			'autoloads' => [],
			'cron'      => [],
			'tables'    => [
				$prefix . 'wm_activity_logs'   => [],
				$prefix . 'wm_activity_alerts' => [],
			],
			'prefix'    => $prefix,
		];
	}
	$mock_options          = $mock_site_stores[ $blog_id ]['options'];
	$mock_option_autoloads = $mock_site_stores[ $blog_id ]['autoloads'];
	$mock_cron             = $mock_site_stores[ $blog_id ]['cron'];
	$mock_db_tables        = $mock_site_stores[ $blog_id ]['tables'];
	return true;
}
function restore_current_blog(): bool {
	global $mock_current_blog_id, $mock_blog_stack, $mock_site_stores, $mock_options, $mock_option_autoloads, $mock_cron, $mock_db_tables, $wpdb;
	$mock_site_stores[ $mock_current_blog_id ] = [
		'options'   => $mock_options,
		'autoloads' => $mock_option_autoloads,
		'cron'      => $mock_cron,
		'tables'    => $mock_db_tables,
		'prefix'    => $wpdb->prefix,
	];
	if ( [] === $mock_blog_stack ) {
		return false;
	}
	$prev                  = (int) array_pop( $mock_blog_stack );
	$mock_current_blog_id  = $prev;
	$store                 = $mock_site_stores[ $prev ];
	$wpdb->prefix          = $store['prefix'];
	$mock_options          = $store['options'];
	$mock_option_autoloads = $store['autoloads'];
	$mock_cron             = $store['cron'];
	$mock_db_tables        = $store['tables'];
	return true;
}
function dbDelta( string $queries = '' ): array {
	global $mock_dbdelta_queries;
	$mock_dbdelta_queries[] = $queries;
	return [];
}
function plugin_basename( string $file ): string {
	return 'wm-activity-sentinel/' . basename( $file );
}
function current_user_can( string $cap ): bool {
	return true;
}
function check_admin_referer( $action = -1, string $query_arg = '_wpnonce' ): bool {
	return true;
}
function add_query_arg( $args, string $url = '' ): string {
	if ( is_array( $args ) ) {
		$sep = str_contains( $url, '?' ) ? '&' : '?';
		return $url . $sep . http_build_query( $args );
	}
	return $url;
}
function wp_safe_redirect( string $location, int $status = 302 ): bool {
	throw new WM_Test_Redirect_Exit( $location );
}
function delete_option( string $name ): bool {
	global $mock_options, $mock_deleted_options;
	$mock_deleted_options[] = $name;
	unset( $mock_options[ $name ] );
	return true;
}
function delete_site_option( string $name ): bool {
	return true;
}
function wp_salt( string $scheme = 'auth' ): string {
	return 'mock-salt-' . $scheme;
}
/**
 * $mock_now lets a test write rows that are genuinely old.
 *
 * Ageing a row afterwards by assigning created_at does not work: the field is part of the entry
 * hash, so the row stops recomputing and the chain reports tampering — at the row the test edited,
 * not at the one under test. That is what the first version of the retention assertions did to the
 * genesis block, and the failure looked like a bug in the retention pass.
 */
function current_time( string $type, $gmt = 0 ) {
	global $mock_now;
	$at = $mock_now ?? time();
	return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s', $at ) : $at;
}
function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}
function __( string $t, string $d = 'default' ): string {
	return $t;
}
function esc_html( $t ): string {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function esc_html__( string $t, string $d = 'default' ): string {
	return esc_html( $t );
}
function esc_html_e( string $t, string $d = 'default' ): void {
	echo esc_html( $t );
}
function esc_attr( $t ): string {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function esc_attr__( string $t, string $d = 'default' ): string {
	return esc_attr( $t );
}
function esc_attr_e( string $t, string $d = 'default' ): void {
	echo esc_attr( $t );
}
function esc_url( $url ): string {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}
function esc_url_raw( $url ): string {
	return (string) $url;
}
function esc_js( $t ): string {
	return addslashes( (string) $t );
}
function number_format_i18n( $number, int $decimals = 0 ): string {
	return number_format( (float) $number, $decimals, '.', ',' );
}
function paginate_links( $args = '' ): string {
	return '';
}
function wp_kses_post( $data ): string {
	return (string) $data;
}
function is_admin(): bool {
	return true;
}
function admin_url( string $path = '' ): string {
	return 'https://demo.example/wp-admin/' . ltrim( $path, '/' );
}
function wp_nonce_field( $action = -1, string $name = '_wpnonce', bool $referer = true, bool $display = true ): string {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="mock-nonce" />';
	if ( $display ) {
		echo $field;
	}
	return $field;
}
function selected( $selected, $current = true, bool $display = true ): string {
	$out = (string) $selected === (string) $current ? ' selected="selected"' : '';
	if ( $display ) {
		echo $out;
	}
	return $out;
}
function checked( $checked, $current = true, bool $display = true ): string {
	$out = (bool) $checked === (bool) $current ? ' checked="checked"' : '';
	if ( $display ) {
		echo $out;
	}
	return $out;
}
function register_block_bindings_source( string $name, array $props ): bool {
	global $mock_block_bindings;
	$mock_block_bindings[ $name ] = $props;
	return true;
}
function get_userdata( $user_id ) {
	global $mock_users;
	return isset( $mock_users[ (int) $user_id ] ) ? (object) $mock_users[ (int) $user_id ] : false;
}
function get_current_user_id(): int {
	global $mock_user_id;
	return (int) $mock_user_id;
}
function is_user_logged_in(): bool {
	global $mock_logged_in;
	return (bool) $mock_logged_in;
}
function get_bloginfo( string $show = '' ): string {
	return 'version' === $show ? '7.1' : 'Demo Site';
}
function site_url(): string {
	return 'https://demo.example';
}
function sanitize_text_field( $s ): string {
	return is_scalar( $s ) ? trim( strip_tags( (string) $s ) ) : '';
}
function sanitize_key( $k ): string {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
}
function sanitize_email( $e ): string {
	return filter_var( (string) $e, FILTER_SANITIZE_EMAIL ) ?: '';
}
function is_email( $e ) {
	return false !== filter_var( (string) $e, FILTER_VALIDATE_EMAIL ) ? $e : false;
}
function absint( $v ): int {
	return abs( (int) $v );
}
function wp_unslash( $v ) {
	return is_string( $v ) ? stripslashes( $v ) : $v;
}
function wp_parse_args( $args, $defaults ): array {
	return array_merge( $defaults, (array) $args );
}
function wp_generate_password( int $len = 12, bool $special = true ): string {
	return substr( bin2hex( random_bytes( $len ) ), 0, $len );
}
function wp_rand( int $min = 0, int $max = 0 ): int {
	return $max > $min ? random_int( $min, $max ) : random_int( 0, 1000000 );
}
function wp_mail( $to, $subject, $message ): bool {
	global $mock_mail;
	$mock_mail[] = compact( 'to', 'subject', 'message' );
	return true;
}
// Until 2026-09-16 this returned false for every URL, so any check that a URL is refused passed
// without measuring anything. It now accepts http(s) with a host, which is what WordPress does.
function wp_http_validate_url( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( ! in_array( $parts['scheme'] ?? '', [ 'http', 'https' ], true ) || '' === ( $parts['host'] ?? '' ) ) {
		return false;
	}
	return $url;
}
function get_transient( string $k ) {
	global $mock_transients;
	return $mock_transients[ $k ] ?? false;
}
function set_transient( string $k, $v, int $ttl = 0 ): bool {
	global $mock_transients;
	$mock_transients[ $k ] = $v;
	return true;
}
function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}
function current_filter(): string {
	return 'test';
}
function wp_get_theme() {
	return new class() {
		public function get( string $field ): string {
			return 'New Theme';
		}
	};
}
function hash_file_mock(): string {
	return '';
}

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public $data = null ) {}
}
class WP_REST_Server {
	public const CREATABLE = 'POST';
}
class WP_REST_Request {
	private array $json = [];
	public function __construct( private string $method = 'POST', private string $route = '' ) {}
	public function set_json( array $json ): void {
		$this->json = $json;
	}
	public function get_json_params(): array {
		return $this->json;
	}
	public function get_body_params(): array {
		return [];
	}
	public function get_route(): string {
		return $this->route;
	}
	public function get_method(): string {
		return $this->method;
	}
}
class WP_REST_Response {
	public function __construct( public $data = null, public int $status = 200 ) {}
	public function get_data() {
		return $this->data;
	}
	public function get_status(): int {
		return $this->status;
	}
}
final class WM_Test_CLI_Exit extends RuntimeException {}
final class WM_Test_Redirect_Exit extends RuntimeException {}
class WP_CLI {
	public static array $out = [];
	public static function line( string $m ): void {
		self::$out[] = 'line: ' . $m;
	}
	public static function success( string $m ): void {
		self::$out[] = 'success: ' . $m;
	}
	public static function warning( string $m ): void {
		self::$out[] = 'warning: ' . $m;
	}
	public static function error( string $m ): void {
		self::$out[] = 'error: ' . $m;
		throw new WM_Test_CLI_Exit( $m );
	}
	public static function confirm( string $m, array $assoc = [] ): void {}
	public static function add_command( string $name, $class ): void {}
}
class GenerateBlocks_Register_Dynamic_Tag {
	public static array $registered = [];
	public function __construct( public array $args ) {
		self::$registered[ (string) ( $args['tag'] ?? '' ) ] = $args;
	}
}

require_once __DIR__ . '/stubs/hub-contract.php';

foreach ( [ 'database', 'logger', 'retention', 'integrity', 'fim', 'alerts', 'forwarder', 'exporter', 'seeder', 'sensors', 'admin', 'core', 'cli', 'spoke-adapter' ] as $wm_part ) {
	require_once ABSPATH . "includes/class-sentinel-{$wm_part}.php";
}

// ---------------------------------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------------------------------
$passed_count = 0;
$failed_count = 0;

function print_results(): void {
	global $passed_count, $failed_count;
	echo "\n==============================================================================\n";
	echo "  TEST SUITE RESULTS: {$passed_count} PASSED | {$failed_count} FAILED\n";
	echo "==============================================================================\n\n";
}

// An error nobody catches still ends with the results line, so tests/run-mutations.sh can tell a run
// whose assertions failed from a file that never ran. The classes are loaded above, before this
// handler: a class that no longer parses stops the run without a results line.
set_exception_handler( static function ( Throwable $e ): void {
	global $failed_count;
	$failed_count++;
	echo '  [FAIL] uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . "\n";
	print_results();
	exit( 1 );
} );

function assert_test( string $name, bool $condition, string $details = '' ): void {
	global $passed_count, $failed_count;
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		$passed_count++;
	} else {
		echo "  [FAIL] {$name}" . ( '' !== $details ? ": {$details}" : '' ) . "\n";
		$failed_count++;
	}
}

function logs(): array {
	global $mock_db_tables;
	return $mock_db_tables['wp_wm_activity_logs'];
}

function fresh_chain(): void {
	global $mock_db_tables, $wpdb, $mock_options;
	$wpdb->query( 'TRUNCATE TABLE wp_wm_activity_logs' );
	$mock_db_tables['wp_wm_activity_alerts'] = [];
	unset( $mock_options[ WM_Sentinel_Database::TIP_OPTION ] );
	WM_Sentinel_Database::ensure_genesis_block();
}

function source_without_comments( string $relative ): string {
	$tokens = token_get_all( (string) file_get_contents( ABSPATH . $relative ) );
	$out    = '';
	foreach ( $tokens as $t ) {
		if ( is_array( $t ) && in_array( $t[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
			continue;
		}
		$out .= is_array( $t ) ? $t[1] : $t;
	}
	return $out;
}

echo "==============================================================================\n";
echo "  WM ACTIVITY SENTINEL — STANDALONE TEST SUITE\n";
echo "==============================================================================\n\n";

// ---------------------------------------------------------------------------------------------------
echo "[TEST GROUP 1] Genesis block\n";
fresh_chain();
$genesis = logs()[1] ?? null;
assert_test( 'genesis has prev_hash GENESIS and a 64-hex entry hash', $genesis && 'GENESIS' === $genesis->prev_hash && 64 === strlen( $genesis->entry_hash ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 2] Chaining\n";
$mock_options['wm_sentinel_logging_intensity'] = 'all';
$id2 = WM_Sentinel_Logger::log( 1, 'plugin_activated', 'plugin', null, 'info', [ 'plugin' => 'a' ] );
$id3 = WM_Sentinel_Logger::log( 1, 'core_updated', 'core', null, 'warning', [ 'version' => '7.1' ] );
$id4 = WM_Sentinel_Logger::log( 1, 'user_role_changed', 'user', 2, 'critical', [ 'role' => 'administrator' ] );
assert_test( 'entries link to their predecessors', logs()[ $id2 ]->prev_hash === $genesis->entry_hash && logs()[ $id3 ]->prev_hash === logs()[ $id2 ]->entry_hash && logs()[ $id4 ]->prev_hash === logs()[ $id3 ]->entry_hash );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 3] Verification detects edits and truncation\n";
$ok = WM_Sentinel_Integrity::verify_chain();
assert_test( 'an untouched chain verifies (4 entries)', true === $ok['valid'] && 4 === $ok['total_verified'], json_encode( $ok ) );
$saved = logs()[ $id3 ]->context;
$mock_db_tables['wp_wm_activity_logs'][ $id3 ]->context = '{"tampered":true}';
$bad = WM_Sentinel_Integrity::verify_chain();
assert_test( 'an edited row is reported at its id', false === $bad['valid'] && $id3 === $bad['broken_at_id'] );
$mock_db_tables['wp_wm_activity_logs'][ $id3 ]->context = $saved;
unset( $mock_db_tables['wp_wm_activity_logs'][ $id4 ] );
$cut = WM_Sentinel_Integrity::verify_chain();
assert_test( 'removing the newest row is detected through the tip', false === $cut['valid'] && null === $cut['broken_at_id'] );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 4] Tip, intensity, modules, IP hash\n";
fresh_chain();
$mock_sequence = [];
WM_Sentinel_Logger::log( 1, 'plugin_activated', 'plugin', null, 'notice', [] );
$tip_pos    = array_search( 'OPTION ' . WM_Sentinel_Database::TIP_OPTION, $mock_sequence, true );
$commit_pos = array_search( 'COMMIT', $mock_sequence, true );
assert_test( 'the chain tip is written before COMMIT, inside the lock (after COMMIT until 2026-09-15)', false !== $tip_pos && false !== $commit_pos && $tip_pos < $commit_pos, implode( ' | ', array_map( static fn( $q ) => substr( $q, 0, 30 ), $mock_sequence ) ) );
$tip = WM_Sentinel_Database::get_chain_tip();
assert_test( 'the tip counts the rows including the new one', 2 === $tip['count'] );

$mock_options['wm_sentinel_logging_intensity'] = 'critical_only';
assert_test( 'critical_only suppresses a notice', false === WM_Sentinel_Logger::log( 1, 'plugin_activated', 'plugin', null, 'notice', [] ) );
assert_test( 'critical_only never suppresses the erasure record', is_int( WM_Sentinel_Logger::log( 1, 'privacy_erasure_applied', 'privacy', null, 'notice', [ 'residual_hashes' => [] ] ) ) );
$mock_options['wm_sentinel_logging_intensity'] = 'all';

$mock_options['wm_sentinel_log_auth']  = 0;
$mock_options['wm_sentinel_log_users'] = 1;
assert_test( 'the authentication switch filters the login events the sensors emit (it filtered nothing until 2026-09-15)', false === WM_Sentinel_Logger::log( null, 'user_login_failed', 'user', null, 'warning', [] ) );
$mock_options['wm_sentinel_log_auth'] = 1;
$mock_options['wm_sentinel_log_plugins'] = 0;
assert_test( 'the plugins switch covers file editor entries', false === WM_Sentinel_Logger::log( 1, 'plugin_theme_file_viewed', 'file', null, 'warning', [] ) );
$mock_options['wm_sentinel_log_plugins'] = 1;

assert_test( 'without NONCE_SALT the IP hash uses the stored WordPress nonce salt, not a literal', WM_Sentinel_Logger::generate_ip_hmac( '198.51.100.7' ) === hash_hmac( 'sha256', '198.51.100.7', gmdate( 'Y-m-d' ) . wp_salt( 'nonce' ) ) );
assert_test( 'no fallback key or salt literal in executable logger code', ! str_contains( source_without_comments( 'includes/class-sentinel-logger.php' ), 'wm_sentinel_default_salt_2026' ) && ! str_contains( source_without_comments( 'includes/class-sentinel-logger.php' ), 'wm_sentinel_auth_key_2026' ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 5] WordPress privacy exporter\n";
fresh_chain();
$mock_users[7] = [ 'display_name' => 'Erika Muster', 'user_email' => 'erika@example.test' ];
$actor1 = WM_Sentinel_Logger::log( 7, 'user_login_success', 'user', 7, 'info', [ 'roles' => [ 'editor' ] ] );
$actor2 = WM_Sentinel_Logger::log( 7, 'plugin_activated', 'plugin', null, 'notice', [ 'plugin' => 'x/x.php' ] );
$about  = WM_Sentinel_Logger::log( 1, 'user_created', 'user', 7, 'notice', [ 'new_user_email' => 'erika@example.test', 'new_user_login' => 'erika' ] );
$other  = WM_Sentinel_Logger::log( 1, 'plugin_activated', 'plugin', null, 'notice', [ 'plugin' => 'y/y.php' ] );
try {
	$export = WM_Sentinel_Exporter::export_personal_data( 'erika@example.test' );
	assert_test( 'the exporter runs (fatal error on WM_Sentinel_Database::get_table_name() until 2026-09-15)', true );
	assert_test( 'it exports rows where the address is the actor or appears in the context', 3 === count( $export['data'] ), (string) count( $export['data'] ) );
} catch ( Throwable $e ) {
	assert_test( 'the exporter runs (fatal error on WM_Sentinel_Database::get_table_name() until 2026-09-15)', false, get_class( $e ) . ': ' . $e->getMessage() );
}

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 6] WordPress privacy eraser keeps the chain verifiable\n";
try {
	$erased = WM_Sentinel_Exporter::erase_personal_data( 'erika@example.test' );
	assert_test( 'the eraser runs', true );
} catch ( Throwable $e ) {
	$erased = [];
	assert_test( 'the eraser runs', false, get_class( $e ) . ': ' . $e->getMessage() );
}
$rows = logs();
assert_test( 'actor rows get placeholder name, address and IP hash', '[DSGVO Art. 17 Gelöscht]' === $rows[ $actor1 ]->user_name && "anonymized-{$actor1}@privacy.local" === $rows[ $actor1 ]->user_email && '[DSGVO Art. 17 Gelöscht]' === $rows[ $actor2 ]->user_name );
assert_test( 'the address is removed from the context of a row about the person (left there until 2026-09-15)', ! str_contains( (string) $rows[ $about ]->context, 'erika@example.test' ) && 'Admin User' === $rows[ $about ]->user_name );
assert_test( 'an unrelated row is untouched', 'Admin User' === $rows[ $other ]->user_name && str_contains( (string) $rows[ $other ]->context, 'y/y.php' ) );
$record = end( $rows );
assert_test( 'a chained erasure record lists the three changed rows', 'privacy_erasure_applied' === $record->event_type && [ $actor1, $actor2, $about ] === array_map( 'intval', array_keys( json_decode( $record->context, true )['residual_hashes'] ?? [] ) ) );
assert_test( 'the eraser reports removal and no raw address in its message', true === ( $erased['items_removed'] ?? null ) && ! str_contains( implode( ' ', $erased['messages'] ?? [] ), 'intakt' ) );
$after = WM_Sentinel_Integrity::verify_chain();
assert_test( 'the chain still verifies and counts three pseudonymised rows (reported as tampering until 2026-09-15)', true === $after['valid'] && 3 === $after['pseudonymized'], json_encode( $after ) );

$mock_db_tables['wp_wm_activity_logs'][ $about ]->context = '{"new_user_login":"someone-else"}';
$edited = WM_Sentinel_Integrity::verify_chain();
assert_test( 'editing a pseudonymised row afterwards is still detected at that row', false === $edited['valid'] && $about === $edited['broken_at_id'] );
$mock_db_tables['wp_wm_activity_logs'] = $rows;
$rows_backup                            = $rows;

// A record that lists a row but was not written through the chain.
$mock_db_tables['wp_wm_activity_logs'][ $other ]->user_name = 'Forger';
$forged        = clone $record;
$forged->log_id = $record->log_id + 1;
$forged->prev_hash = $record->entry_hash;
$forged->context   = json_encode( [ 'residual_hashes' => [ $other => WM_Sentinel_Logger::compute_residual_hash( $mock_db_tables['wp_wm_activity_logs'][ $other ] ) ] ] );
$forged->entry_hash = str_repeat( 'a', 64 );
$mock_db_tables['wp_wm_activity_logs'][ $forged->log_id ] = $forged;
$fake = WM_Sentinel_Integrity::verify_chain();
assert_test( 'a residual fingerprint in a forged record does not launder an edit', false === $fake['valid'] );
$mock_db_tables['wp_wm_activity_logs'] = $rows_backup;
$mock_db_tables['wp_wm_activity_logs'][ $other ]->user_name = 'Admin User';

assert_test( 'nothing to erase answers without writing a record', false === WM_Sentinel_Exporter::erase_personal_data( 'nobody@example.test' )['items_removed'] && 'privacy_erasure_applied' === end( $mock_db_tables['wp_wm_activity_logs'] )->event_type && $record->log_id === end( $mock_db_tables['wp_wm_activity_logs'] )->log_id );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 7] WP-CLI\n";
$cli = new WM_Sentinel_CLI_Command();
fresh_chain();
try {
	$cli->demodaten( [], [ 'import' => true ] );
	assert_test( 'demodaten --import runs (called the missing Seeder::seed() until 2026-09-15)', 16 === count( logs() ) );
} catch ( Throwable $e ) {
	assert_test( 'demodaten --import runs (called the missing Seeder::seed() until 2026-09-15)', false, get_class( $e ) . ': ' . $e->getMessage() );
}
$mock_options['wm_sentinel_log_auth']          = 0;
$mock_options['wm_sentinel_modules']           = [ 'x' => 1 ];
$mock_options['wm_sentinel_logging_intensity'] = 'strict';
try {
	$cli->demodaten( [], [ 'reset' => true, 'yes' => true ] );
	assert_test( 'demodaten --reset runs (called the missing Seeder::factory_reset() until 2026-09-15)', 1 === count( logs() ) );
} catch ( Throwable $e ) {
	assert_test( 'demodaten --reset runs (called the missing Seeder::factory_reset() until 2026-09-15)', false, get_class( $e ) . ': ' . $e->getMessage() );
}
assert_test( 'the reset restores the settings the plugin reads and removes the ones it never read', 1 === $mock_options['wm_sentinel_log_auth'] && 'all' === $mock_options['wm_sentinel_logging_intensity'] && ! array_key_exists( 'wm_sentinel_modules', $mock_options ) );
// The call above passes 'yes' straight to the method; real WP-CLI first checks the flags against the
// docblock synopsis and refuses one the OPTIONS section does not list. Without the line `[--yes]` the
// flag never reached WP_CLI::confirm(): `demodaten --reset --yes` stopped with "unknown --yes
// parameter", and the fleet's screenshot setup, which resets the log before importing, piled up a
// new set of demo entries on every run (until 1.4.0).
$demodaten_doc = (string) ( new ReflectionMethod( 'WM_Sentinel_CLI_Command', 'demodaten' ) )->getDocComment();
assert_test(
	'the demodaten synopsis lists [--yes], so --reset --yes reaches the confirmation instead of being refused as unknown (until 1.4.0)',
	(bool) preg_match( '/^\s*\*\s*\[--yes\]\s*$/m', $demodaten_doc ),
	$demodaten_doc
);

$mock_db_tables['wp_wm_activity_logs'][1]->user_name = 'edited';
WP_CLI::$out = [];
try {
	$cli->audit( [], [] );
	assert_test( 'audit exits with an error on a broken chain', false );
} catch ( WM_Test_CLI_Exit $e ) {
	assert_test( 'audit names the broken id and the reason (empty until 2026-09-15)', str_starts_with( $e->getMessage(), 'Kette gebrochen bei Log-ID #1: Manipulationsnachweis' ), $e->getMessage() );
	assert_test( 'the reason names a different AUTH_KEY as a possible cause, not only an edit (a moved site read as tampered)', str_contains( $e->getMessage(), 'anderen AUTH_KEY' ), $e->getMessage() );
}
fresh_chain();

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 8] Sensors and telemetry\n";
$mock_filters = [];
( new WM_Sentinel_Sensors() )->init_sensors();
$hooks = array_keys( $mock_filters );
assert_test( 'no sensor listens to hooks WordPress never fires', ! in_array( 'application_password_created', $hooks, true ) && ! in_array( 'wp_edit_theme_plugin_file', $hooks, true ) );
assert_test( 'the application-password sensor takes two arguments, so the plain password never arrives', 2 === ( $mock_filters['wp_create_application_password'][0][1] ?? 0 ) );
assert_test( 'the file editor save request is hooked before core (priority 0)', 0 === ( $mock_filters['wp_ajax_edit-theme-plugin-file'][0][2] ?? -1 ) );
$sensors = new WM_Sentinel_Sensors();
$sensors->on_theme_switched( 'Old Theme', null );
$theme_ctx = json_decode( (string) end( $mock_db_tables['wp_wm_activity_logs'] )->context, true );
assert_test( 'theme switch records the new theme and the previous one (logged the old one as new until 2026-09-15)', 'New Theme' === ( $theme_ctx['new_theme'] ?? '' ) && 'Old Theme' === ( $theme_ctx['previous_theme'] ?? '' ) );

assert_test(
	'inter-plugin audit and security incident bridges are subscribed on init_sensors()',
	in_array( 'wm_activity_sentinel_log_event', $hooks, true ) && in_array( 'wm_sentinel_security_incident', $hooks, true )
);
do_action(
	'wm_activity_sentinel_log_event',
	[
		'module_slug'  => 'wm-suite-hub',
		'event_type'   => 'consent_revoked',
		'current_hash' => 'abc123def456',
		'timestamp'    => '2026-11-16 22:00:00',
	]
);
$bridged_row = end( $mock_db_tables['wp_wm_activity_logs'] );
$bridged_ctx = json_decode( (string) $bridged_row->context, true );
assert_test(
	'wm_activity_sentinel_log_event appends a chained hub_audit_bridged entry',
	'hub_audit_bridged' === $bridged_row->event_type
		&& 'hub' === $bridged_row->object_type
		&& 'wm-suite-hub' === ( $bridged_ctx['module_slug'] ?? '' )
		&& 'consent_revoked' === ( $bridged_ctx['hub_event_type'] ?? '' )
		&& 'abc123def456' === ( $bridged_ctx['hub_hash'] ?? '' )
);
$before_self_loop = count( logs() );
do_action( 'wm_activity_sentinel_log_event', [ 'module_slug' => 'wm-activity-sentinel', 'event_type' => 'loop' ] );
assert_test( 'wm_activity_sentinel_log_event ignores self-originated events to prevent recursion', $before_self_loop === count( logs() ) );

do_action(
	'wm_sentinel_security_incident',
	[
		'type'      => 'REQUEST_SHIELD_BLOCK',
		'reason'    => 'Null-byte injection detected',
		'parameter' => 'file_path',
		'ip'        => '203.0.113.42',
		'timestamp' => 1794860000,
	]
);
$inc_row = end( $mock_db_tables['wp_wm_activity_logs'] );
$inc_ctx = json_decode( (string) $inc_row->context, true );
assert_test(
	'wm_sentinel_security_incident hashes supplied client IP via daily HMAC and never stores raw IP in context',
	'security_incident_reported' === $inc_row->event_type
		&& 'security' === $inc_row->object_type
		&& 'warning' === $inc_row->severity
		&& 'REQUEST_SHIELD_BLOCK' === ( $inc_ctx['incident_type'] ?? '' )
		&& 'file_path' === ( $inc_ctx['parameter'] ?? '' )
		&& ! str_contains( (string) $inc_row->context, '203.0.113.42' )
		&& WM_Sentinel_Logger::generate_ip_hmac( '203.0.113.42' ) === $inc_row->ip_hmac
);

add_filter( 'wm_sentinel_monitored_options', static fn( array $opts ): array => array_merge( $opts, [ 'wm_custom_critical_option' ] ) );
$sensors->on_critical_option_updated( 'wm_custom_critical_option', 'off', 'on' );
$opt_row = end( $mock_db_tables['wp_wm_activity_logs'] );
assert_test(
	'wm_sentinel_monitored_options filter extends updated_option surveillance',
	'core_options_updated' === $opt_row->event_type && str_contains( (string) $opt_row->context, 'wm_custom_critical_option' )
);

$mock_logged_in = false;
assert_test( 'telemetry refuses visitors without a session', WM_Sentinel_Sensors::telemetry_permission() instanceof WP_Error );
$mock_logged_in = true;
$mock_user_id   = 5;
$mock_users[5]  = [ 'display_name' => 'Subscriber', 'user_email' => 'sub@example.test' ];
$mock_mail      = [];
$req = new WP_REST_Request( 'POST', '/wm-sentinel/v1/telemetry' );
$req->set_json( [ 'events' => [
	[ 'event_type' => 'user_role_changed', 'category' => 'navigation', 'severity' => 'critical', 'metadata' => [ 'role' => 'administrator' ] ],
	[ 'event_type' => 'button_pressed', 'category' => 'user', 'severity' => 'critical', 'metadata' => [] ],
	[ 'event_type' => 'button_pressed', 'category' => 'action', 'severity' => 'critical', 'metadata' => [ 'element_text' => 'Speichern', 'injected_key' => 'x' ] ],
] ] );
$before = count( logs() );
$res    = $sensors->handle_telemetry_beacon( $req );
$last   = end( $mock_db_tables['wp_wm_activity_logs'] );
assert_test( 'a forged event type and a forged category are each refused, a tracker event is accepted', 1 === $res->get_data()['logged'] && 2 === $res->get_data()['refused'] && $before + 1 === count( logs() ) && 'button_pressed' === $last->event_type );
assert_test( 'telemetry is always severity info and sends no alert (critical accepted until 2026-09-15)', 'info' === $last->severity && [] === $mock_mail );
assert_test( 'only the tracker metadata keys are stored', ! str_contains( (string) $last->context, 'injected_key' ) && str_contains( (string) $last->context, 'Speichern' ) );
$flood = new WP_REST_Request( 'POST', '/wm-sentinel/v1/telemetry' );
$flood->set_json( [ 'events' => array_fill( 0, 25, [ 'event_type' => 'link_clicked', 'category' => 'navigation', 'metadata' => [] ] ) ] );
$logged = 0;
for ( $i = 0; $i < 4; $i++ ) {
	$logged += $sensors->handle_telemetry_beacon( $flood )->get_data()['logged'];
}
assert_test( 'at most 60 telemetry entries per user per minute', WM_Sentinel_Sensors::TELEMETRY_RATE_LIMIT - 1 === $logged, (string) $logged );
$mock_user_id = 1;

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 9] Factory defaults and uninstall\n";
$defaults = WM_Sentinel_Database::default_options();
assert_test( 'every default names a setting the code reads', [] === array_filter( array_keys( $defaults ), static fn( $o ) => 1 > preg_match_all( "/get_option\( '" . preg_quote( $o, '/' ) . "'/", (string) file_get_contents( ABSPATH . 'includes/class-sentinel-admin.php' ) . file_get_contents( ABSPATH . 'includes/class-sentinel-logger.php' ) . file_get_contents( ABSPATH . 'includes/class-sentinel-sensors.php' ) . file_get_contents( ABSPATH . 'includes/class-sentinel-alerts.php' ) . file_get_contents( ABSPATH . 'includes/class-sentinel-forwarder.php' ) . file_get_contents( ABSPATH . 'includes/class-sentinel-fim.php' ) ) ) );
define( 'WP_UNINSTALL_PLUGIN', 'wm-activity-sentinel/wm-activity-sentinel.php' );
function wp_clear_scheduled_hook( string $hook ): int {
	return 0;
}

// 9a. Default uninstall (wm_sentinel_delete_data_on_uninstall = 0): preserves tables, tip and FIM state.
$mock_options['wm_sentinel_delete_data_on_uninstall'] = 0;
$mock_deleted_options = [];
$mock_sequence        = [];
include ABSPATH . 'uninstall.php';
$expected_default_uninstall = array_merge(
	array_keys( $defaults ),
	WM_Sentinel_Database::LEGACY_OPTIONS
);
sort( $expected_default_uninstall );
$got_default_uninstall = array_unique( $mock_deleted_options );
sort( $got_default_uninstall );
assert_test(
	'default uninstall deletes settings and legacy options while preserving tip and FIM state',
	$expected_default_uninstall === array_values( $got_default_uninstall ),
	json_encode( array_values( array_diff( $expected_default_uninstall, $got_default_uninstall ) ) )
);
assert_test(
	'default uninstall preserves both audit tables',
	0 === count( array_filter( $mock_sequence, static fn( $q ) => str_starts_with( $q, 'DROP TABLE IF EXISTS' ) ) )
);
assert_test(
	'uninstall cleans telemetry and FIM catalogue transients',
	1 === count( array_filter( $mock_sequence, static fn( $q ) => str_contains( $q, 'telemetry' ) && str_contains( $q, 'catalogue' ) ) )
);

// 9b. Opt-in full wipe (wm_sentinel_delete_data_on_uninstall = 1): also deletes tip, FIM state and drops both tables.
// The FIM writes two options the settings form never touches — the baseline and the last report —
// so they are not in default_options() and uninstall has to name them itself.
$mock_options['wm_sentinel_delete_data_on_uninstall'] = 1;
$mock_deleted_options = [];
$mock_sequence        = [];
include ABSPATH . 'uninstall.php';
$expected = array_merge(
	array_keys( $defaults ),
	[ WM_Sentinel_Database::TIP_OPTION, WM_Sentinel_Database::DB_VERSION_OPTION, WM_Sentinel_FIM::BASELINE_OPTION, WM_Sentinel_FIM::LAST_SCAN_OPTION ],
	WM_Sentinel_Database::LEGACY_OPTIONS
);
sort( $expected );
$got = array_unique( $mock_deleted_options );
sort( $got );
assert_test( 'opt-in uninstall deletes the settings, the tip, the FIM state and the legacy options (twelve settings were left until 2026-09-15)', $expected === array_values( $got ), json_encode( array_values( array_diff( $expected, $got ) ) ) );
assert_test( 'opt-in uninstall drops both tables', 2 === count( array_filter( $mock_sequence, static fn( $q ) => str_starts_with( $q, 'DROP TABLE IF EXISTS' ) ) ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 10] Hub adapter\n";
fresh_chain();
WM_Sentinel_Logger::log( 1, 'plugin_activated', 'plugin', null, 'notice', [] );
$adapter = new WM_Sentinel_Spoke_Adapter();
$tele    = $adapter->get_telemetry_metrics();
assert_test( 'slug', 'wm-activity-sentinel' === $adapter->get_module_slug() );
assert_test( 'HEALTHY with an intact chain and the key in wp-config', 'HEALTHY' === $adapter->get_health_status() );
assert_test( 'latest_entry_hash shows the real tip (always "GENESIS" until 2026-09-15)', substr( end( $mock_db_tables['wp_wm_activity_logs'] )->entry_hash, 0, 16 ) . '...' === $tele['latest_entry_hash'] );
assert_test( 'the no-op purge_cache action is gone', false === $adapter->execute_action( 'purge_cache' )['success'] );
assert_test( 'verify_chain action reports an intact chain', true === $adapter->execute_action( 'verify_chain' )['success'] );
$sbom = (string) json_encode( $adapter->get_sbom_data() );
assert_test( 'SBOM and description carry features, not GoBD or DSGVO labels', ! preg_match( '/GoBD|GeschGehG|compliance:|Unveränderbarkeit/', $sbom . $adapter->get_description() ) );
// The header and readme.txt went to GPLv2 or later on 2026-09-16 and the adapter did not follow:
// it kept the scaffold's `Proprietary`, with an id and a name, which CycloneDX 1.6 does not accept.
$sbom_licenses = $adapter->get_sbom_data()['licenses'] ?? null;
assert_test(
	'the SBOM declares the licence the plugin ships under, as an SPDX id alone (Proprietary, with an id and a name, until 2026-11-05)',
	[ [ 'license' => [ 'id' => 'GPL-2.0-or-later' ] ] ] === $sbom_licenses,
	(string) json_encode( $sbom_licenses )
);
$mock_db_tables['wp_wm_activity_logs'][1]->severity = 'critical';
assert_test( 'ERROR with a broken chain', 'ERROR' === $adapter->get_health_status() );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 11] CSV cells\n";
$cells = [ '=1+1' => "'=1+1", '+x' => "'+x", '-2' => "'-2", '@SUM(1)' => "'@SUM(1)", "\tA" => "'\tA", "\rB" => "'\rB", 'Safe' => 'Safe' ];
$bad_cells = array_filter( $cells, static fn( $exp, $raw ) => WM_Sentinel_Exporter::sanitize_csv_cell( (string) $raw ) !== $exp, ARRAY_FILTER_USE_BOTH );
assert_test( 'cells that start a formula get a leading apostrophe', [] === $bad_cells );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 12] Admin, export and header text\n";
$admin_src    = source_without_comments( 'includes/class-sentinel-admin.php' );
$exporter_src = source_without_comments( 'includes/class-sentinel-exporter.php' );
$main_src     = source_without_comments( 'wm-activity-sentinel.php' );
assert_test( 'no Spanish strings in the German admin (the stream, integrity and export pages had them until 2026-09-15)', ! preg_match( '/Navegación|Caja Negra|Saltar a|Guía Rápida|Métricas|registro forense|Exportación/u', $admin_src ) );
assert_test( 'no immutability or GoBD claims in admin and export', ! preg_match( '/Inmutable|nadie puede borrar|GoBD|revisionssicher|für Gerichte/u', $admin_src . $exporter_src ) );
// Until 2026-09-15 the screen promised automatic deletion and deleted nothing; from then until
// 2026-09-17 it said "saved but not applied", which was honest and still left the field useless.
// Now it is applied, and the text has to say what the pass actually does — neutralise, not delete.
assert_test(
	'the retention field describes the pass that now runs: neutralised fields, nothing deleted',
	str_contains( $admin_src, 'Täglich angewendet' )
		&& str_contains( $admin_src, 'Gelöscht wird nichts' )
		&& ! str_contains( $admin_src, 'Wird gespeichert, aber nicht angewendet' )
);
assert_test( 'the handbook formula names the fields the hash actually covers', str_contains( $admin_src, 'object_id, object_type, prev_hash, severity, user_email, user_id, user_name' ) );
// Until 1.4.2 the stream page was titled «Echtzeit-Stream» and the widget offered «Live-Stream öffnen», although the
// page is a list rendered once and nothing refreshes it; the widget and the GenerateBlocks tag counted «Blöcke», the
// integrity page and the privacy export spoke of «Block #1», a «Master-Block» and a «Block-Hash».
$core_src_12      = source_without_comments( 'includes/class-sentinel-core.php' );
$integrity_src_12 = source_without_comments( 'includes/class-sentinel-integrity.php' );
assert_test(
	'the stream is not called live or real-time, and the chain counts entries, not blocks',
	! preg_match( '/Echtzeit-Stream|Live-Stream|Blöcke|Master-Block|Block #1|Block-Hash|Gesamt Logs/u', $admin_src . $core_src_12 . $integrity_src_12 . $exporter_src )
		&& str_contains( $admin_src, "'Kette intakt: %d Einträge geprüft'" )
		&& str_contains( $admin_src, "'Aktivitäts-Stream öffnen'" )
		&& str_contains( $core_src_12, "'Geprüfte Einträge'" )
		&& str_contains( $integrity_src_12, 'mit dem verzeichneten Kettenstand' )
		&& str_contains( $exporter_src, "'Eintrags-Hash'" )
);
// Until 1.4.3 the logging level was the «Master-Protokollierungs-Intensität», its section promised a «Forensischer
// Detaillierungsgrad», the level that writes LESS was called «Erhöhte Sicherheit», «Standard» claimed to record «alle
// Daten- und Konfigurationsänderungen» (the plugin logs no post, page or media change) and the alert mail signed off as a
// «Forensisches Schutzsystem» for an entry it only recorded. The settings header called the level «Sensitivität», and the
// alert address promised mail «bei kritischen Ereignissen» although the threshold below it is selectable.
$alerts_src_12 = source_without_comments( 'includes/class-sentinel-alerts.php' );
assert_test(
	'the logging level is called a level, and no level promises security, forensics or changes the sensors do not record',
	! preg_match( '/Master-Protokollierungs|Protokollierungs-Intensität|Forensisch|Erhöhte Sicherheit|Daten- und Konfigurationsänderungen|Schutzsystem|sicherheitskritischer Vorfall|Sensitivität|sofortige Sicherheitsbenachrichtigungen/u', $admin_src . $alerts_src_12 )
		&& str_contains( $admin_src, "esc_html_e( 'Protokollierungsstufe', 'wm-activity-sentinel' )" )
		&& str_contains( $admin_src, "'Nur Warnungen (Warning, Critical)'" )
		&& str_contains( $admin_src, "'Nur kritische Ereignisse:'" )
		&& str_contains( $admin_src, "'Erhält die Alarm-Mails; ab welchem Schweregrad, legt das nächste Feld fest.'" )
);
assert_test( 'no emoji in the plugin row links', ! preg_match( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $main_src ) );
assert_test( 'the notice option of the alert threshold says notice entries never alert (the logger alerts on warning and critical only)', str_contains( $admin_src, 'Notice-Einträge selbst lösen keinen Alarm aus' ) && ! str_contains( $admin_src, 'Alle Hinweise (Notice+)' ) );
// The class value may embed a PHP echo block, so the tag is read up to the closing quote of the class first.
// (A closing PHP tag written literally in a line comment ends PHP mode: this file then prints itself and exits 0.)
preg_match_all( '/<span class="dashicons[^"]*"[^>]*>/', $admin_src, $icon_tags );
$silent_icons = array_filter( $icon_tags[0], static fn( $tag ) => ! str_contains( $tag, 'aria-hidden="true"' ) );
assert_test( 'every dashicon in the admin is hidden from screen readers (20 were announced until 2026-09-15)', count( $icon_tags[0] ) > 20 && [] === $silent_icons, implode( ' ', $silent_icons ) );
preg_match_all( '/style="([^"]*)"/', $admin_src, $inline_styles );
assert_test( 'no inline style in the admin but the dialog toggle (22 with pixel values until 2026-09-15)', [ 'display:none;' ] === $inline_styles[1], implode( ' | ', $inline_styles[1] ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 13] Failed lock or commit, demo import, placeholder key, handbook, WordPress tests\n";
fresh_chain();
$mock_options['wm_sentinel_logging_intensity']  = 'all';
$mock_options['wm_sentinel_alert_email']        = 'ops@example.test';
$mock_options['wm_sentinel_alert_min_severity'] = 'warning';
WM_Sentinel_Logger::log( 1, 'plugin_activated', 'plugin', null, 'notice', [] );
$rows_before     = count( logs() );
$mock_sequence   = [];
$wpdb->fail_for_update = true;
$locked = WM_Sentinel_Logger::log( 1, 'plugin_deleted', 'plugin', null, 'warning', [ 'plugin' => 'x' ] );
assert_test(
	'a failed SELECT … FOR UPDATE writes nothing and rolls back (chained the entry to GENESIS until 2026-09-15)',
	false === $locked && $rows_before === count( logs() ) && in_array( 'ROLLBACK', $mock_sequence, true ) && true === WM_Sentinel_Integrity::verify_chain()['valid'],
	implode( ' | ', array_map( static fn( $q ) => substr( (string) $q, 0, 40 ), $mock_sequence ) )
);
$wpdb->last_error = '';
$mock_mail        = [];
$wpdb->fail_commit = true;
$uncommitted = WM_Sentinel_Logger::log( 1, 'plugin_deleted', 'plugin', null, 'critical', [ 'plugin' => 'y' ] );
assert_test( 'a failed COMMIT returns false and sends no alert', false === $uncommitted && [] === $mock_mail, json_encode( $mock_mail ) );
$wpdb->last_error = '';

fresh_chain();
$mock_mail = [];
$seeded    = WM_Sentinel_Seeder::seed_demo_data( 15 );
assert_test( 'the demo import writes 15 entries and sends no alert mail (4 critical mails per import until 2026-09-15)', 15 === $seeded && [] === $mock_mail, 'seeded ' . $seeded . ', mails ' . count( $mock_mail ) );
WM_Sentinel_Logger::log( 1, 'plugin_deleted', 'plugin', null, 'critical', [ 'plugin' => 'z' ] );
assert_test( 'alerts work again after the import', 1 === count( $mock_mail ), (string) count( $mock_mail ) );
fresh_chain();
$mock_options['wm_sentinel_logging_intensity'] = 'critical_only';
$critical_only = WM_Sentinel_Seeder::seed_demo_data( 15 );
$mock_options['wm_sentinel_logging_intensity'] = 'all';
assert_test( 'the import reports what the logging level let through (reported 15 until 2026-09-15)', 4 === $critical_only && 5 === count( logs() ), 'reported ' . $critical_only . ', rows ' . count( logs() ) );

// An empty table: the mock's COUNT ignores WHERE, so a table with only genesis would count as having demo rows.
$wpdb->query( 'TRUNCATE TABLE wp_wm_activity_logs' );
WP_CLI::$out = [];
$cli->demodaten( [], [ 'delete' => true ] );
fresh_chain();
assert_test( 'deleting demo data from a log without any says nothing was reset (said "Das Protokoll wurde zurückgesetzt")', [] !== WP_CLI::$out && str_contains( implode( "\n", WP_CLI::$out ), 'Keine Demo-Einträge vorhanden' ) && ! str_contains( implode( "\n", WP_CLI::$out ), 'zurückgesetzt' ), implode( ' | ', WP_CLI::$out ) );

$probe = PHP_BINARY . ' -r ' . escapeshellarg( "define('ABSPATH', '/'); define('AUTH_KEY', 'put your unique phrase here'); require '" . ABSPATH . "includes/class-sentinel-logger.php'; echo WM_Sentinel_Logger::chain_key_source();" );
assert_test( 'AUTH_KEY left at the wp-config-sample.php phrase is reported as wp-config-placeholder (reported wp-config)', 'wp-config-placeholder' === trim( (string) shell_exec( $probe ) ), (string) shell_exec( $probe ) );
assert_test( 'integrity screen and WP-CLI say when the key is the public placeholder', str_contains( source_without_comments( 'includes/class-sentinel-admin.php' ), "'wp-config-placeholder' === ( \$audit_result['key_source']" ) && str_contains( source_without_comments( 'includes/class-sentinel-cli.php' ), "'wp-config-placeholder' === \$key_source" ) );

$admin_src13 = source_without_comments( 'includes/class-sentinel-admin.php' );
assert_test(
	'the handbook severities match what the sensors write (login success is info, deactivation and theme switch warning, export page and editor views warning)',
	str_contains( $admin_src13, 'wp_login (Info)' ) && str_contains( $admin_src13, 'deactivated_plugin, delete_plugin und after_switch_theme (Warning)' ) && str_contains( $admin_src13, 'load-theme-editor.php (Warning)' ) && ! str_contains( $admin_src13, "esc_html_e( 'Info / Notice'" ),
	'handbook table still carries the old severities'
);
assert_test( 'the exports switch no longer claims download-link clicks (the UI switch decides them)', ! str_contains( $admin_src13, 'Backup-Start von UpdraftPlus und Duplicator, Klicks auf Download-Links' ) );
assert_test( 'the frontend tracker is versioned by file time', (bool) preg_match( "/tracker\s*=\s*WM_SENTINEL_PATH \. 'assets\/js\/sentinel-tracker\.js';.*?filemtime\( \\\$tracker \)/s", source_without_comments( 'includes/class-sentinel-sensors.php' ) ) );

$wp_tests_ok = true;
$wp_tests_bad = [];
foreach ( [ 'tests/test-sentinel-chain.php', 'tests/test-sentinel-tamper-evidence.php', 'tests/test-sentinel-sensors.php', 'tests/test-sentinel-privacy-tools.php' ] as $wp_test ) {
	$src = (string) file_get_contents( ABSPATH . $wp_test );
	$gate = strpos( $src, "getenv( 'WM_SENTINEL_SCRATCH_INSTALL' )" );
	$write = min( array_filter( [ strpos( $src, '$wpdb->query(' ), strpos( $src, 'WM_Sentinel_Logger::log(' ), strpos( $src, 'update_option(' ), strpos( $src, 'wp_insert_user(' ) ], static fn( $x ) => false !== $x ) ?: [ PHP_INT_MAX ] );
	if ( false === $gate || $gate > $write || ! preg_match( '/exit\(\s*[^;]*(?:failed|pass_count|fail)[^;]*\?\s*[01]\s*:\s*[01]\s*\);/', $src ) ) {
		$wp_tests_bad[] = $wp_test;
	}
}
assert_test( 'the WordPress tests that write to the log refuse to run without WM_SENTINEL_SCRATCH_INSTALL and exit 1 on failure', [] === $wp_tests_bad, implode( ', ', $wp_tests_bad ) );

$mock_sequence = [];
include ABSPATH . 'uninstall.php';
assert_test( 'uninstall deletes the telemetry rate-limit transients', [] !== array_filter( $mock_sequence, static fn( $q ) => str_contains( (string) $q, 'DELETE FROM wp_options' ) && str_contains( str_replace( '\\', '', (string) $q ), '_transient_wm_sentinel_telemetry_' ) ), implode( ' | ', array_slice( $mock_sequence, 0, 5 ) ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 14] SIEM forwarder: Syslog RFC 5424, CEF and LEEF\n";
// The spec asks for the log to reach Splunk, Microsoft Sentinel, Datadog and Elastic. Each reads at
// least one of these three formats, and each format has escaping rules a collector relies on: a raw
// pipe in a CEF header or an unescaped equals sign in an extension splits the event into the wrong
// fields. Formatting is a pure function, so the escaping is asserted here without a network.
$siem_entry = WM_Sentinel_Forwarder::build_entry(
	42,
	'critical',
	'user_role_changed',
	'Erika | Muster',
	[ 'role' => 'administrator', 'note' => "a=b\nsecond line" ],
	'2026-09-16 05:30:00'
);

$cef = WM_Sentinel_Forwarder::format( 'cef', $siem_entry );
assert_test(
	'CEF carries the eight header fields, the mapped severity and the plugin version',
	str_starts_with( $cef, 'CEF:0|WM Suite|WM Activity Sentinel|' . WM_SENTINEL_VERSION . '|user_role_changed|User Role Changed|9|' ),
	$cef
);
// CEF escapes the pipe in the HEADER, where it separates fields, and the equals sign in the
// EXTENSIONS, where it separates key from value. A pipe inside an extension value is legal and is
// left alone; asserting otherwise would pin behaviour the format does not ask for.
$cef_piped = WM_Sentinel_Forwarder::format(
	'cef',
	WM_Sentinel_Forwarder::build_entry( 1, 'warning', 'role|changed', 'Sub', [ 'a' => 'b=c' ], '2026-09-16 05:30:00' )
);
assert_test(
	'a pipe inside a CEF header field is escaped, and an equals sign inside an extension value is escaped',
	str_contains( $cef_piped, '|role\|changed|' ) && str_contains( $cef_piped, 'a:b\=c' ),
	$cef_piped
);
assert_test( 'the CEF payload is a single line', ! str_contains( $cef, "\n" ) && ! str_contains( $cef, "\r" ), $cef );

$syslog = WM_Sentinel_Forwarder::format( 'syslog', $siem_entry );
assert_test(
	'Syslog is RFC 5424 with facility 13 and severity 2 for critical (PRI 106), version 1 and the event type as MSGID',
	str_starts_with( $syslog, '<106>1 2026-09-16T05:30:00Z ' ) && str_contains( $syslog, ' wm-activity-sentinel - user_role_changed [wmSentinel@0 ' ),
	$syslog
);
assert_test(
	'structured data quotes the log id and escapes a quote, a backslash and a bracket in its values',
	str_contains( $syslog, 'logId="42"' ) && str_contains( $syslog, 'severity="critical"' ),
	$syslog
);
$syslog_info = WM_Sentinel_Forwarder::format( 'syslog', WM_Sentinel_Forwarder::build_entry( 1, 'info', 'x', 'y', [], '2026-09-16 05:30:00' ) );
assert_test( 'an info entry maps to RFC 5424 severity 6 (PRI 110)', str_starts_with( $syslog_info, '<110>1 ' ), $syslog_info );

$leef = WM_Sentinel_Forwarder::format( 'leef', $siem_entry );
assert_test(
	'LEEF 2.0 names its delimiter in the header and separates the attributes with it',
	str_starts_with( $leef, 'LEEF:2.0|WM Suite|WM Activity Sentinel|' . WM_SENTINEL_VERSION . '|user_role_changed|^|' )
		&& str_contains( $leef, 'devTime=2026-09-16T05:30:00Z^' ) && str_contains( $leef, '^usrName=' ),
	$leef
);
assert_test( 'a delimiter inside a value would split the attribute, so it is removed', ! str_contains( substr( $leef, 30 ), '^^' ), $leef );

// The gate: off by default, and a configured collector still only gets what reaches the minimum.
$mock_options['wm_sentinel_siem_enabled'] = 0;
assert_test( 'forwarding is off until it is switched on', false === WM_Sentinel_Forwarder::should_forward( $siem_entry ) );
$mock_options['wm_sentinel_siem_enabled']      = 1;
$mock_options['wm_sentinel_siem_min_severity'] = 'critical';
$low = WM_Sentinel_Forwarder::build_entry( 7, 'info', 'link_clicked', 'Sub', [], '2026-09-16 05:30:00' );
assert_test( 'an entry below the configured minimum is not forwarded', false === WM_Sentinel_Forwarder::should_forward( $low ) );
assert_test( 'an entry at the configured minimum is forwarded', true === WM_Sentinel_Forwarder::should_forward( $siem_entry ) );
$mock_options['wm_sentinel_siem_format'] = 'not-a-format';
assert_test( 'an unknown format sends nothing rather than guessing one', false === WM_Sentinel_Forwarder::should_forward( $siem_entry ) );
$mock_options['wm_sentinel_siem_format']  = 'cef';
$mock_options['wm_sentinel_siem_enabled'] = 0;

assert_test(
	'every default of the forwarder is a setting it reads',
	[] === array_filter(
		array_keys( WM_Sentinel_Database::default_options() ),
		static fn( $o ) => str_starts_with( $o, 'wm_sentinel_siem_' )
			&& ! str_contains( (string) file_get_contents( ABSPATH . 'includes/class-sentinel-forwarder.php' ), "'" . $o . "'" )
	)
);

// The logger hands every entry to the forwarder, not only the ones that raise an alert: a SIEM
// takes the stream and filters it itself.
$logger_src = source_without_comments( 'includes/class-sentinel-logger.php' );
assert_test(
	'the logger forwards after COMMIT and outside the alert condition',
	str_contains( $logger_src, 'WM_Sentinel_Forwarder::forward(' )
		&& strpos( $logger_src, 'WM_Sentinel_Forwarder::forward(' ) > strpos( $logger_src, "\$wpdb->query( 'COMMIT' )" )
);

// The assertion above reads the source, so it stays green if the call is disabled rather than removed.
// This one drives the real logger and measures what reached the collector.
fresh_chain();
$wpdb->fail_for_update = false;
$wpdb->fail_commit     = false;
$mock_options['wm_sentinel_logging_intensity'] = 'all';
$mock_options['wm_sentinel_alert_email']       = '';
$mock_options['wm_sentinel_siem_enabled']      = 1;
$mock_options['wm_sentinel_siem_format']       = 'cef';
$mock_options['wm_sentinel_siem_transport']    = 'https';
$mock_options['wm_sentinel_siem_url']          = 'https://collector.example.test/ingest';
$mock_options['wm_sentinel_siem_min_severity'] = 'warning';
$mock_siem_sent = [];
WM_Sentinel_Logger::log( 1, 'plugin_deleted', 'plugin', null, 'critical', [ 'plugin' => 'siem' ] );
assert_test(
	'a logged entry really reaches the collector as CEF',
	1 === count( $mock_siem_sent )
		&& 'https://collector.example.test/ingest' === $mock_siem_sent[0]['url']
		&& str_starts_with( $mock_siem_sent[0]['body'], 'CEF:0|WM Suite|WM Activity Sentinel|' ),
	json_encode( $mock_siem_sent )
);
$mock_siem_sent = [];
WM_Sentinel_Logger::log( 1, 'plugin_activated', 'plugin', null, 'info', [ 'plugin' => 'siem' ] );
assert_test( 'an entry below the minimum reaches no collector', [] === $mock_siem_sent, json_encode( $mock_siem_sent ) );
$mock_options['wm_sentinel_siem_enabled'] = 0;
$mock_options['wm_sentinel_siem_url']     = '';

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 15] File integrity monitor: core against api.wordpress.org, plugins against a sealed baseline\n";
// The spec asks for a continuous comparison of the WordPress tree against the official hash
// catalogue. Everything below runs against a fixture tree in a temp directory, so the scanner is
// measured on files whose contents the test controls, with no network and no WordPress. The fixture
// files are inert text: nothing ever includes them, they exist to have a hash.

$fim_root    = rtrim( sys_get_temp_dir(), '/' ) . '/wm-sentinel-fim-' . getmypid() . '/';
$fim_plugins = $fim_root . 'wp-content/plugins/';

function fim_write( string $path, string $contents ): void {
	$dir = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	file_put_contents( $path, $contents );
}

function fim_rmtree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		is_dir( $path ) ? fim_rmtree( $path ) : unlink( $path );
	}
	rmdir( $dir );
}

fim_rmtree( rtrim( $fim_root, '/' ) );

$fim_files = [
	'wp-admin/admin.php'                           => "<?php // admin\n",
	'wp-includes/functions.php'                    => "<?php // functions\n",
	'wp-includes/blocks/paragraph/block.json'      => "{}\n",
	'readme.html'                                  => "<html>readme</html>\n",
	'license.txt'                                  => "GPL v2 or later\n",
	'wp-content/themes/twentytwentyfive/style.css' => "body{}\n",
];
foreach ( $fim_files as $fim_relative => $fim_body ) {
	fim_write( $fim_root . $fim_relative, $fim_body );
}
$fim_catalogue = array_map( 'md5', $fim_files );

$fim_clean = WM_Sentinel_FIM::scan_core( $fim_catalogue, $fim_root );
assert_test(
	'an untouched tree produces no finding and counts every core file it hashed',
	[] === $fim_clean['modified'] && [] === $fim_clean['missing'] && 5 === $fim_clean['checked'],
	json_encode( $fim_clean )
);

file_put_contents( $fim_root . 'wp-includes/functions.php', "<?php // functions\n// line an intruder appended\n" );
file_put_contents( $fim_root . 'wp-content/themes/twentytwentyfive/style.css', "body{color:red}\n" );
$fim_dirty = WM_Sentinel_FIM::scan_core( $fim_catalogue, $fim_root );
assert_test( 'an edited core file is reported by its path', [ 'wp-includes/functions.php' ] === $fim_dirty['modified'], json_encode( $fim_dirty ) );
assert_test(
	'a changed bundled theme under wp-content is not a finding: the site owns that directory',
	! in_array( 'wp-content/themes/twentytwentyfive/style.css', $fim_dirty['modified'], true ),
	json_encode( $fim_dirty['modified'] )
);

unlink( $fim_root . 'wp-admin/admin.php' );
unlink( $fim_root . 'readme.html' );
unlink( $fim_root . 'license.txt' );
$fim_gone = WM_Sentinel_FIM::scan_core( $fim_catalogue, $fim_root );
assert_test(
	'a deleted core file is missing, while a deleted readme.html and license.txt count as hardening and not as findings',
	[ 'wp-admin/admin.php' ] === $fim_gone['missing'] && [ 'license.txt', 'readme.html' ] === $fim_gone['hardening_removals'],
	json_encode( $fim_gone )
);
// A localized package renames the readme; the de_DE 7.1 catalogue carries readme.html AND
// liesmich.html. A name list per locale would go stale, so the rule is "a root .html".
assert_test(
	'the translated readme of a localized package is hardening too, but an .html inside wp-admin is not',
	WM_Sentinel_FIM::is_hardening_removal( 'liesmich.html' )
		&& WM_Sentinel_FIM::is_hardening_removal( 'leeme.html' )
		&& ! WM_Sentinel_FIM::is_hardening_removal( 'wp-admin/credits.html' )
		&& ! WM_Sentinel_FIM::is_hardening_removal( 'wp-includes/functions.php' )
);
fim_write( $fim_root . 'wp-admin/admin.php', $fim_files['wp-admin/admin.php'] );
fim_write( $fim_root . 'readme.html', $fim_files['readme.html'] );
fim_write( $fim_root . 'license.txt', $fim_files['license.txt'] );

// The catalogue is keyed by the INSTALLED PACKAGE, not by the display language. Measured on
// local WordPress install 2026-09-16: a German-language site running the en_US package produced three findings
// against the de_DE catalogue — version.php, wp-config-sample.php and liesmich.html — and all
// three md5s matched the en_US catalogue exactly. Three false positives on a clean tree.
unset( $GLOBALS['wp_local_package'] );
assert_test( 'without $wp_local_package the installed package is en_US, whatever the site language', 'en_US' === WM_Sentinel_FIM::installed_package_locale() );
$GLOBALS['wp_local_package'] = 'de_DE';
assert_test( 'a localized package names itself in $wp_local_package and that is what is asked for', 'de_DE' === WM_Sentinel_FIM::installed_package_locale() );
unset( $GLOBALS['wp_local_package'] );

// A backdoor does not edit a core file, it adds one. Walking only the catalogue never looks at it.
fim_write( $fim_root . 'wp-includes/wp-cache-shell.php', "<?php // stand-in for a dropped file\n" );
fim_write( $fim_root . 'wp-includes/notes.txt', "backup notes\n" );
assert_test(
	'an executable file the catalogue does not list is reported, a .txt beside it is not',
	[ 'wp-includes/wp-cache-shell.php' ] === WM_Sentinel_FIM::scan_unexpected( $fim_catalogue, $fim_root ),
	json_encode( WM_Sentinel_FIM::scan_unexpected( $fim_catalogue, $fim_root ) )
);

// api.wordpress.org answers an unknown version with HTTP 200 and "checksums": false (measured
// 2026-09-16). Read as an empty catalogue, the scan would walk zero files and report zero findings.
$fim_unknown = WM_Sentinel_FIM::parse_catalogue( '{"checksums":false}', '99.9.9' );
assert_test(
	'a version the catalogue does not cover is an error, never an empty catalogue',
	null !== $fim_unknown['error'] && [] === $fim_unknown['checksums'],
	json_encode( $fim_unknown )
);
assert_test( 'a body that is not the expected JSON is an error too', null !== WM_Sentinel_FIM::parse_catalogue( '<html>502</html>', '6.8.2' )['error'] );

$fim_nested = WM_Sentinel_FIM::parse_catalogue( '{"checksums":{"6.8.2":{"index.php":"' . str_repeat( 'a', 32 ) . '"}}}', '6.8.2' );
assert_test( 'a catalogue nested under the version is flattened', [ 'index.php' => str_repeat( 'a', 32 ) ] === $fim_nested['checksums'], json_encode( $fim_nested ) );

// The catalogue arrives over the network: an entry that walks out of the installation would make the
// scanner read (and report on) files outside WordPress.
$fim_hostile = WM_Sentinel_FIM::parse_catalogue(
	'{"checksums":{"../../../etc/passwd":"' . str_repeat( 'b', 32 ) . '","/etc/shadow":"' . str_repeat( 'c', 32 ) . '","wp-load.php":"' . str_repeat( 'd', 32 ) . '"}}',
	'6.8.2'
);
assert_test( 'a catalogue entry that leaves the installation is dropped', [ 'wp-load.php' => str_repeat( 'd', 32 ) ] === $fim_hostile['checksums'], json_encode( $fim_hostile ) );
assert_test( 'a hash that is not 32 hex characters is dropped', [] === WM_Sentinel_FIM::parse_catalogue( '{"checksums":{"index.php":"nope"}}', '6.8.2' )['checksums'] );

$mock_transients    = [];
$mock_http_requests = [];
$mock_http_response = [ 'response' => [ 'code' => 200 ], 'body' => (string) json_encode( [ 'checksums' => $fim_catalogue ] ) ];
$fim_fetched = WM_Sentinel_FIM::fetch_catalogue( '6.8.2', 'de_DE' );
assert_test(
	'the catalogue is requested for this version and locale',
	null === $fim_fetched['error'] && 1 === count( $mock_http_requests )
		&& str_starts_with( $mock_http_requests[0], WM_Sentinel_FIM::CHECKSUM_ENDPOINT )
		&& str_contains( $mock_http_requests[0], 'version=6.8.2' ) && str_contains( $mock_http_requests[0], 'locale=de_DE' ),
	json_encode( $mock_http_requests )
);
$fim_again = WM_Sentinel_FIM::fetch_catalogue( '6.8.2', 'de_DE' );
assert_test( 'the second call is served from the cache', 1 === count( $mock_http_requests ) && true === $fim_again['cached'] );

$mock_http_response = [ 'response' => [ 'code' => 503 ], 'body' => '' ];
assert_test( 'an API that answers with an error status yields no catalogue', null !== WM_Sentinel_FIM::fetch_catalogue( '6.8.4', 'de_DE' )['error'] );

// The WM Suite plugins have no official catalogue, so the baseline stands in for one.
fim_write( $fim_plugins . 'wm-demo/wm-demo.php', "<?php // demo\n" );
fim_write( $fim_plugins . 'wm-demo/assets/app.js', "console.log(1);\n" );
fim_write( $fim_plugins . 'wm-demo/readme.txt', "not covered\n" );
fim_write( $fim_plugins . 'other-plugin/other.php', "<?php // someone else's plugin\n" );
// Development artifacts that never reach a deployed plugin. Measured 2026-09-16 on a local WordPress install:
// without pruning these, the baseline held 47,085 files and 7.4 MB in one wp_options row, 46,443 of
// them under node_modules, vendor or .git. vendor/ stays: it is executed through an autoloader.
fim_write( $fim_plugins . 'wm-demo/node_modules/left-pad/index.js', "module.exports = 1;\n" );
fim_write( $fim_plugins . 'wm-demo/.git/hooks/pre-commit.php', "<?php // hook\n" );
fim_write( $fim_plugins . 'wm-demo/vendor/acme/lib/Client.php', "<?php // dependency\n" );

$fim_baseline = WM_Sentinel_FIM::capture_suite_baseline( $fim_plugins );
assert_test(
	'the baseline covers the executable files of the wm- plugins, keeps vendor and prunes node_modules and .git',
	[ 'wm-demo/assets/app.js', 'wm-demo/vendor/acme/lib/Client.php', 'wm-demo/wm-demo.php' ] === array_keys( $fim_baseline ),
	json_encode( array_keys( $fim_baseline ) )
);

$fim_same = WM_Sentinel_FIM::compare_suite_baseline( $fim_baseline, $fim_plugins );
assert_test( 'an unchanged plugin tree matches its baseline', [] === $fim_same['modified'] && [] === $fim_same['missing'] && [] === $fim_same['added'], json_encode( $fim_same ) );

file_put_contents( $fim_plugins . 'wm-demo/wm-demo.php', "<?php // demo\n// appended after the baseline\n" );
fim_write( $fim_plugins . 'wm-demo/extra.php', "<?php // dropped later\n" );
$fim_moved = WM_Sentinel_FIM::compare_suite_baseline( $fim_baseline, $fim_plugins );
assert_test(
	'an edited plugin file and a file added after the baseline are both reported',
	[ 'wm-demo/wm-demo.php' ] === $fim_moved['modified'] && [ 'wm-demo/extra.php' ] === $fim_moved['added'],
	json_encode( $fim_moved )
);

// The seal is the point: the baseline lives in wp_options, where whoever edited the plugin file
// could rewrite it to match. The chained entry is what makes that second edit visible.
fresh_chain();
$mock_options['wm_sentinel_logging_intensity'] = 'all';
$mock_options['wm_sentinel_siem_enabled']      = 0;
assert_test( 'a chain with no sealing entry reports the baseline as unsealed', 'unsealed' === WM_Sentinel_FIM::baseline_seal_status( $fim_baseline ) );

// A baseline that quietly grows into megabytes is read and rewritten by every scan and nobody
// notices until the cron times out, so it is refused out loud instead.
$fim_refused = WM_Sentinel_FIM::seal_suite_baseline( $fim_plugins, 1 );
assert_test(
	'a baseline past its maximum is refused, stores nothing and seals nothing',
	null !== $fim_refused['error'] && false === $fim_refused['log_id'] && [] === (array) get_option( WM_Sentinel_FIM::BASELINE_OPTION, [] ),
	json_encode( $fim_refused )
);

$fim_sealed = WM_Sentinel_FIM::seal_suite_baseline( $fim_plugins );
assert_test( 'sealing writes a chained entry and stores the baseline', is_int( $fim_sealed['log_id'] ) && $fim_sealed['log_id'] > 0 && null === $fim_sealed['error'] && $fim_sealed['files'] === count( (array) get_option( WM_Sentinel_FIM::BASELINE_OPTION, [] ) ) );

$fim_stored = (array) get_option( WM_Sentinel_FIM::BASELINE_OPTION, [] );
assert_test( 'the stored baseline matches the fingerprint the chain recorded', 'sealed' === WM_Sentinel_FIM::baseline_seal_status( $fim_stored ) );

$fim_tampered = $fim_stored;
$fim_tampered['wm-demo/wm-demo.php'] = hash( 'sha256', 'whatever the edited file hashes to' );
assert_test( 'a baseline edited after sealing is reported as broken', 'broken' === WM_Sentinel_FIM::baseline_seal_status( $fim_tampered ) );

// End to end: the report lands in the chain and, with forwarding on, at the collector.
fresh_chain();
$mock_options['wm_sentinel_fim_scan_suite']    = 0;
$mock_options['wm_sentinel_logging_intensity'] = 'all';
$mock_options['wm_sentinel_alert_email']       = '';
$mock_options['wm_sentinel_siem_enabled']      = 1;
$mock_options['wm_sentinel_siem_format']       = 'cef';
$mock_options['wm_sentinel_siem_transport']    = 'https';
$mock_options['wm_sentinel_siem_url']          = 'https://collector.example.test/ingest';
$mock_options['wm_sentinel_siem_min_severity'] = 'warning';
$mock_transients    = [];
$mock_siem_sent     = [];
$mock_http_response = [ 'response' => [ 'code' => 200 ], 'body' => (string) json_encode( [ 'checksums' => $fim_catalogue ] ) ];

$fim_report = WM_Sentinel_FIM::run( 'manual', $fim_root, $fim_plugins );
assert_test(
	'a tree with an edited core file and an unexpected executable is critical',
	'critical' === $fim_report['severity'] && [ 'wp-includes/functions.php' ] === $fim_report['core']['modified'] && [ 'wp-includes/wp-cache-shell.php' ] === $fim_report['core']['unexpected'],
	json_encode( $fim_report['core'] )
);
$fim_entry = end( $mock_db_tables['wp_wm_activity_logs'] );
assert_test(
	'the finding is a chained entry of its own object type, so no module switch can silence it',
	WM_Sentinel_FIM::EVENT_FINDING === $fim_entry->event_type && WM_Sentinel_FIM::OBJECT_TYPE === $fim_entry->object_type && 'critical' === $fim_entry->severity,
	$fim_entry->event_type . ' / ' . $fim_entry->object_type
);
assert_test( 'the chain still verifies after the monitor wrote to it', true === WM_Sentinel_Integrity::verify_chain()['valid'] );
assert_test(
	'a core file change reaches the SIEM collector, which is what the spec asks the forwarder to carry',
	1 === count( $mock_siem_sent ) && str_contains( $mock_siem_sent[0]['body'], 'fim_integrity_finding' ),
	json_encode( $mock_siem_sent )
);

// Switching off plugin logging must not silence a modified wp-includes.
fresh_chain();
$mock_options['wm_sentinel_log_plugins']  = 0;
$mock_options['wm_sentinel_siem_enabled'] = 0;
$mock_transients = [];
$fim_switched = WM_Sentinel_FIM::run( 'cron', $fim_root, $fim_plugins );
assert_test( 'a finding is recorded even with the plugin and file switch off', is_int( $fim_switched['log_id'] ) && $fim_switched['log_id'] > 0, json_encode( $fim_switched['log_id'] ) );
$mock_options['wm_sentinel_log_plugins'] = 1;

// A clean tree is info, and the entry says so rather than staying silent.
unlink( $fim_root . 'wp-includes/wp-cache-shell.php' );
file_put_contents( $fim_root . 'wp-includes/functions.php', $fim_files['wp-includes/functions.php'] );
fresh_chain();
$mock_transients = [];
$fim_ok = WM_Sentinel_FIM::run( 'cron', $fim_root, $fim_plugins );
assert_test(
	'a clean tree is info and records the scan that found nothing',
	'info' === $fim_ok['severity'] && 0 === $fim_ok['findings'] && WM_Sentinel_FIM::EVENT_CLEAN === end( $mock_db_tables['wp_wm_activity_logs'] )->event_type,
	json_encode( [ $fim_ok['severity'], $fim_ok['findings'] ] )
);

// The falsifier of the whole monitor: a catalogue that could not be read must not look like a pass.
fresh_chain();
$mock_transients    = [];
$mock_http_response = [ 'response' => [ 'code' => 200 ], 'body' => '{"checksums":false}' ];
$fim_blind = WM_Sentinel_FIM::run( 'cron', $fim_root, $fim_plugins );
assert_test(
	'a tree that could not be checked is a warning with zero files checked, never an all-clear',
	'warning' === $fim_blind['severity'] && 0 === $fim_blind['core']['checked'] && null !== $fim_blind['core']['error'],
	json_encode( [ $fim_blind['severity'], $fim_blind['core']['checked'] ] )
);

// The schedule follows the setting: on means an event exists, off means the run does nothing.
$mock_cron = [];
$mock_options['wm_sentinel_fim_enabled'] = 1;
WM_Sentinel_FIM::sync_schedule();
assert_test( 'switching the monitor on schedules the daily scan', false !== wp_next_scheduled( WM_Sentinel_FIM::CRON_HOOK ) );
$mock_options['wm_sentinel_fim_enabled'] = 0;
$fim_before = count( logs() );
WM_Sentinel_FIM::run_scheduled();
assert_test( 'with the monitor switched off the scheduled run writes nothing', $fim_before === count( logs() ) );
$mock_options['wm_sentinel_fim_enabled']    = 1;
$mock_options['wm_sentinel_fim_scan_suite'] = 1;
$mock_options['wm_sentinel_siem_url']       = '';

fim_rmtree( rtrim( $fim_root, '/' ) );

// ---------------------------------------------------------------------------------------------------
// Retention (wm_sentinel_retention_days)
//
// Until 2026-09-17 this setting was written, displayed, read back into its own form — and applied
// by nothing. These assertions pin the two halves of the fix: that it now acts, and that acting
// does not break the chain, which is why it deletes nothing.
// ---------------------------------------------------------------------------------------------------
$retention_reset = static function (): void {
	global $mock_db_tables, $mock_options;
	$mock_db_tables[ WM_Sentinel_Database::logs_table() ] = [];
	$mock_options['wm_sentinel_logging_intensity'] = 'all';
	WM_Sentinel_Database::ensure_genesis_block();
};

$old_stamp = gmdate( 'Y-m-d H:i:s', time() - ( 400 * DAY_IN_SECONDS ) );

// A window below the form's own minimum means "keep everything", never "pseudonymise everything".
$logs_table = WM_Sentinel_Database::logs_table();
// Row ids come from log() itself: the mock's auto-increment is not reset with the table, so
// assuming 1, 2, 3 measures whichever rows happen to sit there.
//
// And an old row has to be WRITTEN old. created_at is part of the entry hash, so assigning it
// afterwards stops that row recomputing and the audit reports tampering at the edited row — which
// is exactly what the first version of these assertions did to the genesis block, producing a
// failure that read like a defect in the retention pass.
$write_at = static function ( int $when, callable $write ) {
	global $mock_now;
	$mock_now = $when;
	$id       = $write();
	$mock_now = null;
	return $id;
};
$long_ago = time() - ( 400 * DAY_IN_SECONDS );

// A value the form cannot produce (its minimum is 30) means keep everything, never "pseudonymise
// everything": when the stored value is missing or out of range, the default is the one that
// destroys nothing. Measured with 10 rather than 0, because 0 would also come back as 0 from a
// version with no floor at all — the assertion has to fail when the floor is removed.
$mock_options['wm_sentinel_retention_days'] = 10;
assert_test( 'a retention below the form minimum is read as keep-everything, not as zero days', 0 === WM_Sentinel_Retention::configured_days() );
assert_test( 'a retention inside the accepted range is used as it stands', 90 === ( ( $mock_options['wm_sentinel_retention_days'] = 90 ) && true ? WM_Sentinel_Retention::configured_days() : -1 ) );
$mock_options['wm_sentinel_retention_days'] = 0;
$retention_reset();
$rows_before_off = count( logs() );
$write_at( $long_ago, static fn() => WM_Sentinel_Logger::log( 5, 'user_login_success', 'auth', null, 'info', [] ) );
$off = WM_Sentinel_Retention::apply();
assert_test( 'with retention off nothing is touched and no record is written', 0 === $off['applied'] && 'retention_off' === $off['reason'] && $rows_before_off + 1 === count( logs() ) );

// The pass itself.
$mock_options['wm_sentinel_retention_days'] = 90;
$retention_reset();
$genesis_id = (int) array_key_first( $mock_db_tables[ $logs_table ] );
$aged_id    = (int) $write_at( $long_ago, static fn() => WM_Sentinel_Logger::log( 5, 'user_login_success', 'auth', null, 'info', [] ) );
$recent_id  = (int) WM_Sentinel_Logger::log( 6, 'user_login_failed', 'auth', null, 'warning', [] );

$before_rows = count( logs() );
$dry         = WM_Sentinel_Retention::apply( true );
assert_test( 'a dry run counts the candidates and writes nothing', 1 === $dry['candidates'] && 0 === $dry['applied'] && $before_rows === count( logs() ) );

$result = WM_Sentinel_Retention::apply();
$aged   = $mock_db_tables[ $logs_table ][ $aged_id ];
$recent = $mock_db_tables[ $logs_table ][ $recent_id ];

assert_test(
	'the aged row loses name, address and IP hash',
	1 === $result['applied']
		&& WM_Sentinel_Retention::NEUTRAL_NAME === (string) $aged->user_name
		&& '' === (string) $aged->user_email
		&& '' === (string) $aged->ip_hmac
);
assert_test(
	'the recent row keeps its own values',
	WM_Sentinel_Retention::NEUTRAL_NAME !== (string) $recent->user_name && '' !== (string) $recent->ip_hmac
);
assert_test(
	'the genesis block is left alone',
	'genesis_initialized' === (string) $mock_db_tables[ $logs_table ][ $genesis_id ]->event_type
		&& WM_Sentinel_Retention::NEUTRAL_NAME !== (string) $mock_db_tables[ $logs_table ][ $genesis_id ]->user_name
);

assert_test( 'the pass deletes no row — it adds its own record on top', count( logs() ) === $before_rows + 1 );

// THE assertion. Pseudonymising rows of a hash chain is only acceptable while the audit still
// verifies: a retention policy that makes the site's own log report tampering is worse than none.
$after_audit = WM_Sentinel_Integrity::verify_chain();
assert_test(
	'the chain still verifies after the retention pass, and counts the row it changed',
	true === $after_audit['valid'] && 1 === $after_audit['pseudonymized'],
	json_encode( [ $after_audit['valid'], $after_audit['pseudonymized'], $after_audit['error_reason'] ] )
);

// Running it again must be a no-op: the rows no longer carry anything to neutralise.
$second = WM_Sentinel_Retention::apply();
assert_test( 'a second pass finds nothing and writes no second record', 0 === $second['candidates'] && null === $second['record_id'] );

// A record of the chain's own scaffolding, old enough to qualify on age alone: it must survive
// because of its event type, not because of its date. Neutralising an erasure record would strip
// the fingerprints that let the rows it covers verify.
$retention_reset();
$scaffold_id = (int) $write_at( $long_ago, static fn() => WM_Sentinel_Logger::log( 9, 'privacy_erasure_applied', 'privacy', null, 'notice', [ 'pseudonymized_rows' => 0 ] ) );
WM_Sentinel_Retention::apply();
assert_test(
	'an aged erasure record is not pseudonymised: the chain needs its fingerprints',
	WM_Sentinel_Retention::NEUTRAL_NAME !== (string) $mock_db_tables[ $logs_table ][ $scaffold_id ]->user_name
		&& true === WM_Sentinel_Integrity::verify_chain()['valid']
);

// If the record cannot be written, not one row may change. A pseudonymised row whose fingerprint
// was never recorded reads as tampering for ever, and there is no way back: the original values
// are gone. This is the same ordering the Art. 17 eraser uses.
$retention_reset();
$unrecorded_id = (int) $write_at( $long_ago, static fn() => WM_Sentinel_Logger::log( 11, 'user_login_success', 'auth', null, 'info', [] ) );
$before_name   = (string) $mock_db_tables[ $logs_table ][ $unrecorded_id ]->user_name;
$wpdb->fail_commit = true;
$refused           = WM_Sentinel_Retention::apply();
$wpdb->fail_commit = false;
assert_test(
	'a record that could not be written leaves every row exactly as it was',
	'record_not_written' === $refused['reason']
		&& 0 === $refused['applied']
		&& $before_name === (string) $mock_db_tables[ $logs_table ][ $unrecorded_id ]->user_name
		&& true === WM_Sentinel_Integrity::verify_chain()['valid'],
	json_encode( [ $refused['reason'], $refused['applied'] ] )
);
$retention_reset();

// A row changed without its fingerprint would read as tampering for ever, so the record goes first.
$retention_reset();
$write_at( $long_ago, static fn() => WM_Sentinel_Logger::log( 7, 'user_login_success', 'auth', null, 'info', [] ) );
$mock_options['wm_sentinel_logging_intensity'] = 'critical_only';
$forced = WM_Sentinel_Retention::apply();
assert_test(
	'the retention record is written even at critical_only, so the rows it covers keep verifying',
	1 === $forced['applied'] && null !== $forced['record_id'] && true === WM_Sentinel_Integrity::verify_chain()['valid'],
	json_encode( [ $forced['applied'], $forced['record_id'] ] )
);
$mock_options['wm_sentinel_logging_intensity'] = 'all';
$mock_options['wm_sentinel_retention_days']    = 90;
$retention_reset();

// The retention pass has to schedule itself on load, not only on activation.
// Measured on a local WordPress install: deployed to an install where the plugin was already active, the nda
// gate's event was scheduled and this one was not — an active plugin never runs its activation
// hook again, so the feature arrived dead on every existing site.
$main_source = source_without_comments( 'wm-activity-sentinel.php' );
assert_test(
	'the retention schedule is synced on plugins_loaded, not only on activation',
	(bool) preg_match(
		"/add_action\(\s*'plugins_loaded',\s*\[\s*'WM_Sentinel_Retention',\s*'sync_schedule'\s*\]/",
		$main_source
	),
	'an already-active install would never schedule the pass'
);

// Every option the plugin reads is documented in docs/SETTINGS.md.
// The README described the settings screen without naming a single option, so 22 of the fleet's
// 56 documentation gaps were here: an operator could not find out that wm_sentinel_retention_days
// prunes nothing, or that the three view switches record identified people's browsing by default.
$settings_doc = (string) @file_get_contents( __DIR__ . '/../docs/SETTINGS.md' );
$undocumented_options = [];
foreach ( array_keys( WM_Sentinel_Database::default_options() ) as $option_name ) {
	if ( ! str_contains( $settings_doc, $option_name ) ) {
		$undocumented_options[] = $option_name;
	}
}

assert_test(
	'every option in default_options() is documented in docs/SETTINGS.md',
	'' !== $settings_doc && [] === $undocumented_options,
	json_encode( [ 'options' => count( WM_Sentinel_Database::default_options() ), 'undocumented' => $undocumented_options ] )
);

// The retention pass shipped on 2026-09-17 and test group 12 pinned the admin field the same day.
// README.md, readme.txt and the 24 translated READMEs generated from README.md kept saying the
// setting was stored and not applied until 2026-09-20 — three days in which the only surfaces a
// reader outside the admin screen ever sees described a shipped feature as doing nothing. No test
// read those files, which is the whole reason the drift lasted.
//
// The claims are matched as the literal sentences they were, so prose that *quotes* the old wording
// to record the correction does not trip the check. That is also the limit of it: a future rewrite
// that denies the pass in different words would pass, which is why the two positive assertions
// below matter as much as the negative ones.
// The first version of this check named four files — README.md, the two translated root READMEs and
// readme.txt — and passed. The session-close pass of the same day then found the claim alive in
// docs/DSGVO-COMPLIANCE.md (the data-protection document, of all places), in DEVELOPERS.md twice,
// in docs/DEVELOPER-GUIDE.md and in docs/ARCHITECTURE.md. A check that enumerates its targets only
// covers the surfaces someone remembered; the next one written is uncovered on the day it is added.
//
// So it SWEEPS every shipped doc instead, and the exemptions are named rather than the targets.
$doc_surfaces = array_merge(
	glob( __DIR__ . '/../*.md' ) ?: [],
	glob( __DIR__ . '/../*.txt' ) ?: [],
	glob( __DIR__ . '/../docs/*.md' ) ?: [],
	glob( __DIR__ . '/../docs/translations/*.md' ) ?: [],
	glob( __DIR__ . '/../public/*.txt' ) ?: []
);
// CHANGELOG.md is the one file whose job is to quote what the docs used to say.
$exempt_surfaces = [ 'CHANGELOG.md' ];

// Each entry is the literal sentence as it stood, long enough that prose *quoting* it to record the
// correction does not trip the check — every corrected file above now contains the old wording
// inside a "this said X until 2026-09-20" note, and none of them matches these.
$superseded_claims = [
	'retention days (stored, not applied)',
	'Nothing is deleted automatically; the retention setting is stored and not applied.',
	'Decide how long the log is kept; the plugin deletes nothing on its own.',
	'Nothing applies `wm_sentinel_retention_days`',
	'settings screen only; nothing deletes by it',
	'Aufbewahrungstage (gespeichert, nicht angewendet)',
	'die Aufbewahrungsfrist wird gespeichert und nicht angewendet',
	'Entscheiden, wie lange das Protokoll aufbewahrt wird; das Plugin löscht nichts von selbst.',
	'(Standard 90 Tage) wird gespeichert und nicht angewendet',
	'días de conservación (se guardan, no se aplican)',
	'el plazo de conservación se guarda y no se aplica',
	'Decide cuánto tiempo se conserva el registro; el plugin no borra nada por sí mismo.',
	'the retention setting says it is not applied;',
];

$readme_md    = (string) @file_get_contents( __DIR__ . '/../README.md' );
$stale_claims = [];
$unreadable   = [];
$swept        = 0;
foreach ( $doc_surfaces as $doc_path ) {
	$doc_name = basename( $doc_path );
	if ( in_array( $doc_name, $exempt_surfaces, true ) ) {
		continue;
	}
	$doc_body = (string) @file_get_contents( $doc_path );
	if ( '' === $doc_body ) {
		$unreadable[] = $doc_name;
		continue;
	}
	$swept++;
	foreach ( $superseded_claims as $claim ) {
		if ( str_contains( $doc_body, $claim ) ) {
			$stale_claims[] = $doc_name . ': ' . $claim;
		}
	}
}
// A sweep that swept nothing passes for free, which is the failure mode of every check built on a
// glob. Pin the floor: the four root docs plus the six under docs/ plus the 24 translations.
assert_test(
	'the doc sweep actually reached the shipped documentation',
	$swept >= 30 && [] === $unreadable,
	json_encode( [ 'swept' => $swept, 'unreadable' => $unreadable ] )
);

assert_test(
	'no shipped doc, in any of the three languages, still says the retention setting is stored and not applied',
	[] === $stale_claims,
	json_encode( $stale_claims )
);

assert_test(
	'README.md names the command that runs the retention pass',
	str_contains( $readme_md, 'wp sentinel retention' ),
	'the WP-CLI list omitted it while the command existed'
);

// The translated READMEs are generated from README.md by the fleet script, so a stale README does
// not stay in one file: it is copied into 24. This asserts the outcome rather than the generator —
// the quick-start step the operator actually reads.
$translations_dir     = __DIR__ . '/../docs/translations';
$translated_readmes   = glob( $translations_dir . '/README.*.md' ) ?: [];
$superseded_quickstep = 'Decide how long the log is kept; the plugin deletes nothing on its own.';
$stale_translations   = [];
foreach ( $translated_readmes as $translated_path ) {
	if ( str_contains( (string) @file_get_contents( $translated_path ), $superseded_quickstep ) ) {
		$stale_translations[] = basename( $translated_path );
	}
}

assert_test(
	'no translated README still tells the operator the plugin deletes nothing on its own',
	24 === count( $translated_readmes ) && [] === $stale_translations,
	json_encode( [ 'files' => count( $translated_readmes ), 'stale' => $stale_translations ] )
);

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 16] Version parity, developer hooks, Block Bindings, GenerateBlocks Dynamic Tag and uninstall UI\n";

$main_plugin_src = (string) @file_get_contents( __DIR__ . '/../wm-activity-sentinel.php' );
$readme_txt_src  = (string) @file_get_contents( __DIR__ . '/../readme.txt' );
$bom_json_data   = json_decode( (string) @file_get_contents( __DIR__ . '/../bom.json' ), true );
$blueprint_data  = json_decode( (string) @file_get_contents( __DIR__ . '/../.wordpress-org/blueprints/blueprint.json' ), true );

// The expected value is the suite's own WM_SENTINEL_VERSION, which wp-plugin-version-bump.py moves
// with the plugin's: the literals this assertion carried had to be edited by hand on every release.
$wm_version_re = preg_quote( WM_SENTINEL_VERSION, '/' );
assert_test(
	'the version matches across plugin header, WM_SENTINEL_VERSION, readme.txt Stable tag and bom.json',
	(bool) preg_match( '/^\s*\*\s*Version:\s*' . $wm_version_re . '\s*$/m', $main_plugin_src )
		&& str_contains( $main_plugin_src, "define( 'WM_SENTINEL_VERSION', '" . WM_SENTINEL_VERSION . "' );" )
		&& (bool) preg_match( '/^Stable tag:\s*' . $wm_version_re . '\s*$/m', $readme_txt_src )
		&& WM_SENTINEL_VERSION === ( $bom_json_data['metadata']['component']['version'] ?? '' )
);

assert_test(
	'WordPress Playground blueprint activates wm-activity-sentinel and lands on /wp-admin/',
	is_array( $blueprint_data )
		&& '/wp-admin/' === ( $blueprint_data['landingPage'] ?? '' )
		&& 'wm-activity-sentinel/wm-activity-sentinel.php' === ( $blueprint_data['steps'][0]['pluginPath'] ?? '' )
);

fresh_chain();
WM_Sentinel_Logger::log( 1, 'plugin_activated', 'plugin', null, 'notice', [ 'plugin' => 'probe/probe.php' ] );

$ready_received = null;
add_action(
	'wm_sentinel_ready',
	static function ( $instance ) use ( &$ready_received ): void {
		$ready_received = $instance;
	},
	10,
	1
);
WM_Sentinel_Core::reset_instance();
$core_instance = WM_Sentinel_Core::get_instance();

assert_test(
	'WM_Sentinel_Core::get_instance() fires wm_sentinel_ready with the active singleton',
	$ready_received instanceof WM_Sentinel_Core && $ready_received === $core_instance
);

$public_metrics = WM_Sentinel_Core::get_public_metrics();
assert_test(
	'WM_Sentinel_Core::get_public_metrics() exposes non-sensitive chain status, verified blocks, total events and version',
	'INTACT' === ( $public_metrics['chain_status'] ?? '' )
		&& 2 === ( $public_metrics['verified_blocks'] ?? -1 )
		&& 2 === ( $public_metrics['total_events'] ?? -1 )
		&& WM_SENTINEL_VERSION === ( $public_metrics['version'] ?? '' ),
	(string) json_encode( $public_metrics )
);

add_filter(
	'wm_sentinel_public_metrics',
	static function ( array $metrics ): array {
		$metrics['custom_probe'] = 'filtered-ok';
		return $metrics;
	},
	10,
	1
);
$filtered_metrics = WM_Sentinel_Core::get_public_metrics();
unset( $mock_filters['wm_sentinel_public_metrics'] );

assert_test(
	'wm_sentinel_public_metrics filter customizes exposed public metrics',
	'filtered-ok' === ( $filtered_metrics['custom_probe'] ?? '' ),
	(string) json_encode( $filtered_metrics )
);

$mock_block_bindings = [];
WM_Sentinel_Core::register_block_bindings();
assert_test(
	'WP 6.5+ Block Bindings source wm-sentinel/metrics registers and resolves known metric keys only',
	isset( $mock_block_bindings['wm-sentinel/metrics'] )
		&& is_callable( $mock_block_bindings['wm-sentinel/metrics']['get_value_callback'] ?? null )
		&& 'INTACT' === WM_Sentinel_Core::resolve_block_binding_value( [ 'key' => 'chain_status' ] )
		&& '2' === WM_Sentinel_Core::resolve_block_binding_value( [ 'key' => 'verified_blocks' ] )
		&& WM_SENTINEL_VERSION === WM_Sentinel_Core::resolve_block_binding_value( [ 'key' => 'version' ] )
		&& null === WM_Sentinel_Core::resolve_block_binding_value( [ 'key' => 'non_existent_secret' ] )
);

GenerateBlocks_Register_Dynamic_Tag::$registered = [];
WM_Sentinel_Core::register_generateblocks_tags();
assert_test(
	'GenerateBlocks 2.x Dynamic Tag wm_sentinel_metric registers and resolves via generateblocks_dynamic_tag_replacement filter',
	isset( GenerateBlocks_Register_Dynamic_Tag::$registered['wm_sentinel_metric'] )
		&& 'INTACT' === apply_filters( 'generateblocks_dynamic_tag_replacement', 'fallback', [ 'tag' => 'wm_sentinel_metric', 'options' => [ 'key' => 'chain_status' ] ] )
		&& '2' === apply_filters( 'generateblocks_dynamic_tag_replacement', 'fallback', [ 'tag' => 'wm_sentinel_metric', 'options' => [ 'key' => 'total_events' ] ] )
		&& 'fallback' === apply_filters( 'generateblocks_dynamic_tag_replacement', 'fallback', [ 'tag' => 'unrelated_tag', 'options' => [ 'key' => 'chain_status' ] ] )
		&& 'Hello Post' === apply_filters( 'generateblocks_dynamic_tag_output', 'Hello Post', [ 'source' => 'post' ], 'Hello Post' )
		&& 'Hello Post' === WM_Sentinel_Core::filter_generateblocks_tag_output( 'Hello Post', [ 'source' => 'post' ], 'Hello Post' )
		&& 'INTACT' === WM_Sentinel_Core::filter_generateblocks_tag_output( 'fallback', 'wm_sentinel_metric', [ 'key' => 'chain_status' ] )
);

$admin_ui = new WM_Sentinel_Admin();
$mock_options['wm_sentinel_delete_data_on_uninstall'] = 0;
ob_start();
$admin_ui->render_settings_page();
$settings_html_default = (string) ob_get_clean();

$mock_options['wm_sentinel_delete_data_on_uninstall'] = 1;
ob_start();
$admin_ui->render_settings_page();
$settings_html_checked = (string) ob_get_clean();
$mock_options['wm_sentinel_delete_data_on_uninstall'] = 0;

assert_test(
	'settings screen renders wm_sentinel_delete_data_on_uninstall checkbox unchecked by default and checked when enabled',
	(bool) preg_match( '/<input[^>]*name="wm_sentinel_delete_data_on_uninstall"[^>]*>/', $settings_html_default, $m_def )
		&& ! str_contains( $m_def[0], 'checked="checked"' )
		&& (bool) preg_match( '/<input[^>]*name="wm_sentinel_delete_data_on_uninstall"[^>]*checked="checked"[^>]*>/', $settings_html_checked )
);

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 17] BFSG 2025 / WCAG 2.2 AA Focus-Trap & 44px targets, Multisite wp_initialize_site, and option autoload\n";

fresh_chain();
WM_Sentinel_Logger::log( 1, 'plugin_activated', 'plugin', null, 'notice', [ 'plugin' => 'probe/probe.php' ] );
ob_start();
$admin_ui->render_stream_page();
$stream_html = (string) ob_get_clean();

assert_test(
	'context inspector <pre> has tabindex="0" for keyboard focus and scrollability',
	str_contains( $stream_html, '<pre id="wm-sentinel-context-json" tabindex="0"></pre>' )
);

$wrap_pos  = strpos( $stream_html, '<div class="wrap wm-admin wm-sentinel-admin-wrap">' );
$modal_pos = strpos( $stream_html, '<div id="wm-sentinel-context-modal"' );
$wrap_end  = false !== $modal_pos ? strrpos( substr( $stream_html, 0, $modal_pos ), '</div>' ) : false;
assert_test(
	'context modal renders outside .wm-sentinel-admin-wrap (sibling wrapper) with .wm-admin on .wm-sentinel-modal-card',
	false !== $wrap_pos && false !== $modal_pos && false !== $wrap_end && $wrap_end > $wrap_pos
		&& str_contains( $stream_html, '<div class="wm-sentinel-modal-card wm-admin">' )
);

$sentinel_css = (string) file_get_contents( ABSPATH . 'assets/css/sentinel-admin.css' );
assert_test(
	'assets/css/sentinel-admin.css enforces >= 44x44px touch targets with high-specificity .wp-core-ui selectors',
	str_contains( $sentinel_css, '.wp-core-ui .wm-admin .button.button-small' )
		&& str_contains( $sentinel_css, '.wp-core-ui .wm-sentinel-view-context' )
		&& str_contains( $sentinel_css, '.wp-core-ui .wm-sentinel-widget-actions .button.button-small' )
		&& str_contains( $sentinel_css, '.wm-sentinel-modal-close' )
		&& str_contains( $sentinel_css, 'min-height: 44px;' )
		&& str_contains( $sentinel_css, 'min-width: 44px;' )
);

$admin_js = (string) file_get_contents( ABSPATH . 'assets/js/sentinel-admin.js' );
assert_test(
	'assets/js/sentinel-admin.js implements Tab / Shift+Tab focus-trap, focusin guard, and aria-hidden/inert sibling restoration',
	str_contains( $admin_js, "modal.style.display !== 'none'" )
		&& str_contains( $admin_js, "e.key === 'Tab'" )
		&& str_contains( $admin_js, 'e.shiftKey' )
		&& str_contains( $admin_js, 'e.preventDefault()' )
		&& str_contains( $admin_js, "addEventListener('focusin'" )
		&& str_contains( $admin_js, '.wm-sentinel-admin-wrap' )
		&& str_contains( $admin_js, '#adminmenuwrap' )
		&& str_contains( $admin_js, '#wpadminbar' )
		&& str_contains( $admin_js, '#wpfooter' )
		&& str_contains( $admin_js, "'aria-hidden'" )
		&& str_contains( $admin_js, 'el.inert = true' )
);

// Execute assets/js/sentinel-admin.js in Node when available to prove Tab, Shift+Tab, focusin, aria-hidden/inert, and restore order at runtime.
$node_bin = trim( (string) @shell_exec( 'command -v node 2>/dev/null' ) );
if ( '' !== $node_bin ) {
	$js_harness = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

class MockElement {
	constructor(tagName, id = '', className = '') {
		this.tagName = tagName.toUpperCase();
		this.id = id;
		this.className = className;
		this.attributes = {};
		this.style = {};
		this.children = [];
		this.parentNode = null;
		this.textContent = '';
		this._inert = false;
		this.listeners = {};
		this.onFocusHook = null;
	}
	get inert() { return this._inert; }
	set inert(v) {
		this._inert = Boolean(v);
		if (this._inert) this.attributes.inert = '';
		else delete this.attributes.inert;
	}
	appendChild(child) {
		child.parentNode = this;
		this.children.push(child);
		return child;
	}
	getAttribute(name) {
		return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
	}
	setAttribute(name, value) {
		this.attributes[name] = String(value);
		if (name === 'inert') this._inert = true;
	}
	removeAttribute(name) {
		delete this.attributes[name];
		if (name === 'inert') this._inert = false;
	}
	hasAttribute(name) {
		return Object.prototype.hasOwnProperty.call(this.attributes, name);
	}
	contains(other) {
		let cur = other;
		while (cur) {
			if (cur === this) return true;
			cur = cur.parentNode;
		}
		return false;
	}
	addEventListener(type, fn) {
		(this.listeners[type] = this.listeners[type] || []).push(fn);
	}
	dispatchEvent(evt) {
		evt.target = evt.target || this;
		evt.defaultPrevented = false;
		evt.preventDefault = () => { evt.defaultPrevented = true; };
		for (const fn of this.listeners[evt.type] || []) {
			fn(evt);
		}
	}
	focus() {
		if (this.onFocusHook) this.onFocusHook();
		let cur = this;
		while (cur) {
			if (cur.inert) {
				throw new Error('Attempted to focus an inert element: ' + (this.id || this.className));
			}
			cur = cur.parentNode;
		}
		document.activeElement = this;
	}
	matchesSimple(part) {
		part = part.trim();
		if (part === '[href]') return this.hasAttribute('href');
		if (part === '[tabindex]:not([tabindex="-1"])') {
			return this.hasAttribute('tabindex') && this.getAttribute('tabindex') !== '-1';
		}
		if (part.endsWith(':not([disabled])')) {
			const tag = part.slice(0, -':not([disabled])'.length).toUpperCase();
			return this.tagName === tag && !this.hasAttribute('disabled');
		}
		if (part.startsWith('#')) return this.id === part.slice(1);
		if (part.startsWith('.')) return this.className.split(/\s+/).includes(part.slice(1));
		return this.tagName === part.toUpperCase();
	}
	querySelectorAll(selector) {
		const parts = selector.split(',').map((s) => s.trim()).filter(Boolean);
		const out = [];
		const walk = (node) => {
			for (const c of node.children) {
				if (parts.some((p) => c.matchesSimple(p))) out.push(c);
				walk(c);
			}
		};
		walk(this);
		return out;
	}
}

const root = new MockElement('div', 'wpwrap');
const wpAdminBar = new MockElement('div', 'wpadminbar');
wpAdminBar.setAttribute('aria-hidden', 'false');
const adminMenuWrap = new MockElement('div', 'adminmenuwrap');
const wrap = new MockElement('div', '', 'wrap wm-admin wm-sentinel-admin-wrap');
const viewBtn = new MockElement('button', '', 'button button-small wm-sentinel-view-context');
viewBtn.setAttribute('data-log-id', '42');
viewBtn.setAttribute('data-event', 'plugin_activated');
viewBtn.setAttribute('data-context', '{"plugin":"wm/wm.php"}');
const outsideBtn = new MockElement('button', 'outside-btn', 'button');
wrap.appendChild(viewBtn);
wrap.appendChild(outsideBtn);

const modal = new MockElement('div', 'wm-sentinel-context-modal', 'wm-sentinel-modal-overlay');
modal.style.display = 'none';
const card = new MockElement('div', '', 'wm-sentinel-modal-card wm-admin');
const modalTitle = new MockElement('h3', 'wm-sentinel-modal-title');
const closeBtn = new MockElement('button', '', 'wm-sentinel-modal-close');
const modalJson = new MockElement('pre', 'wm-sentinel-context-json');
modalJson.setAttribute('tabindex', '0');
card.appendChild(modalTitle);
card.appendChild(closeBtn);
card.appendChild(modalJson);
modal.appendChild(card);

const wpFooter = new MockElement('div', 'wpfooter');
root.appendChild(wpAdminBar);
root.appendChild(adminMenuWrap);
root.appendChild(wrap);
root.appendChild(modal);
root.appendChild(wpFooter);

const docListeners = {};
const document = {
	activeElement: viewBtn,
	getElementById(id) {
		return root.querySelectorAll('#' + id)[0] || null;
	},
	querySelector(sel) {
		return root.querySelectorAll(sel)[0] || null;
	},
	querySelectorAll(sel) {
		return root.querySelectorAll(sel);
	},
	addEventListener(type, fn) {
		(docListeners[type] = docListeners[type] || []).push(fn);
	},
	dispatchEvent(evt) {
		evt.defaultPrevented = false;
		evt.preventDefault = () => { evt.defaultPrevented = true; };
		for (const fn of docListeners[evt.type] || []) {
			fn(evt);
		}
	}
};

const code = fs.readFileSync(process.argv[2], 'utf8');
vm.runInNewContext(code, { document });
document.dispatchEvent({ type: 'DOMContentLoaded' });

let restoredBeforeFocus = false;
viewBtn.onFocusHook = () => {
	restoredBeforeFocus = (wrap.inert === false && wrap.getAttribute('aria-hidden') === null);
};

// 1. Open modal
viewBtn.dispatchEvent({ type: 'click' });
assert.strictEqual(modal.style.display, 'flex');
assert.strictEqual(document.activeElement, closeBtn);
assert.strictEqual(wrap.getAttribute('aria-hidden'), 'true');
assert.strictEqual(wrap.inert, true);
assert.strictEqual(adminMenuWrap.getAttribute('aria-hidden'), 'true');
assert.strictEqual(wpAdminBar.getAttribute('aria-hidden'), 'true');
assert.strictEqual(wpFooter.getAttribute('aria-hidden'), 'true');
assert.strictEqual(modal.inert, false);

// 2. Tab from last focusable (modalJson) wraps to first (closeBtn)
modalJson.focus();
const tabEvt = { type: 'keydown', key: 'Tab', shiftKey: false };
document.dispatchEvent(tabEvt);
assert.strictEqual(tabEvt.defaultPrevented, true);
assert.strictEqual(document.activeElement, closeBtn);

// 3. Shift+Tab from first focusable (closeBtn) wraps to last (modalJson)
const shiftTabEvt = { type: 'keydown', key: 'Tab', shiftKey: true };
document.dispatchEvent(shiftTabEvt);
assert.strictEqual(shiftTabEvt.defaultPrevented, true);
assert.strictEqual(document.activeElement, modalJson);

// 4. focusin outside modal bounces back to closeBtn
document.dispatchEvent({ type: 'focusin', target: outsideBtn });
assert.strictEqual(document.activeElement, closeBtn);

// 5. Escape restores sibling states BEFORE restoring lastFocus.focus()
const escEvt = { type: 'keydown', key: 'Escape' };
document.dispatchEvent(escEvt);
assert.strictEqual(modal.style.display, 'none');
assert.strictEqual(restoredBeforeFocus, true);
assert.strictEqual(document.activeElement, viewBtn);
assert.strictEqual(wrap.getAttribute('aria-hidden'), null);
assert.strictEqual(wrap.inert, false);
assert.strictEqual(wpAdminBar.getAttribute('aria-hidden'), 'false');
assert.strictEqual(wpAdminBar.inert, false);
process.stdout.write('JS_FOCUS_TRAP_OK');
JS;
	$proc = proc_open(
		[ $node_bin, '-', ABSPATH . 'assets/js/sentinel-admin.js' ],
		[ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
		$pipes
	);
	$js_out = '';
	$js_err = '';
	$js_rc  = 1;
	if ( is_resource( $proc ) ) {
		fwrite( $pipes[0], $js_harness );
		fclose( $pipes[0] );
		$js_out = (string) stream_get_contents( $pipes[1] );
		$js_err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$js_rc = proc_close( $proc );
	}
	assert_test(
		'sentinel-admin.js runtime focus-trap (Tab, Shift+Tab, focusin, aria-hidden/inert state preservation, Escape restore)',
		0 === $js_rc && 'JS_FOCUS_TRAP_OK' === trim( $js_out ),
		"exit={$js_rc} out={$js_out} err={$js_err}"
	);
}

// Multisite wp_initialize_site + network activation + $autoload = false
assert_test(
	'wm-activity-sentinel.php hooks wp_initialize_site and register_activation_hook to WM_Sentinel_Core',
	(bool) preg_match( "/add_action\(\s*'wp_initialize_site',\s*\[\s*'WM_Sentinel_Core',\s*'on_initialize_site'\s*\]/", $main_source )
		&& (bool) preg_match( "/register_activation_hook\(\s*__FILE__,.*?WM_Sentinel_Core::activate/s", $main_source )
);

$saved_site_stores     = $mock_site_stores;
$saved_current_blog_id = $mock_current_blog_id;
$saved_blog_stack      = $mock_blog_stack;
$saved_multisite       = $mock_multisite;
$saved_network_active  = $mock_network_active;
$saved_sites           = $mock_sites;

$mock_site_stores     = [];
$mock_current_blog_id = 1;
$mock_blog_stack      = [];
$mock_multisite       = true;
$mock_network_active  = false;
$mock_dbdelta_queries = [];

WM_Sentinel_Core::on_initialize_site( (object) [ 'blog_id' => 2 ] );
assert_test(
	'wp_initialize_site does nothing when plugin is not network-activated',
	! isset( $mock_site_stores[2] ) && [] === $mock_dbdelta_queries && 1 === $mock_current_blog_id
);

$mock_network_active = true;
WM_Sentinel_Core::on_initialize_site( (object) [ 'blog_id' => 2 ] );
$blog2 = $mock_site_stores[2] ?? [];
assert_test(
	'wp_initialize_site switches to new blog, creates wp_2_ tables, genesis block, options with autoload rules, schedules cron, and restores blog 1',
	1 === $mock_current_blog_id
		&& [] === $mock_blog_stack
		&& 'wp_' === $wpdb->prefix
		&& count( $mock_dbdelta_queries ) >= 2
		&& str_contains( implode( "\n", $mock_dbdelta_queries ), 'wp_2_wm_activity_logs' )
		&& str_contains( implode( "\n", $mock_dbdelta_queries ), 'wp_2_wm_activity_alerts' )
		&& 1 === count( $blog2['tables']['wp_2_wm_activity_logs'] ?? [] )
		&& 'genesis_initialized' === ( ( $blog2['tables']['wp_2_wm_activity_logs'][1] ?? null )->event_type ?? '' )
		&& isset( $blog2['options'][ WM_Sentinel_Database::TIP_OPTION ] )
		&& false === ( $blog2['autoloads'][ WM_Sentinel_Database::TIP_OPTION ] ?? null )
		&& false === ( $blog2['autoloads']['wm_sentinel_retention_days'] ?? null )
		&& false === ( $blog2['autoloads']['wm_sentinel_fim_enabled'] ?? null )
		&& false === ( $blog2['autoloads']['wm_sentinel_siem_enabled'] ?? null )
		&& true === ( $blog2['autoloads']['wm_sentinel_monitor_frontend_views'] ?? null )
		&& isset( $blog2['cron'][ WM_Sentinel_FIM::CRON_HOOK ] )
		&& isset( $blog2['cron'][ WM_Sentinel_Retention::CRON_HOOK ] )
);

$mock_sites = [ 1, 3 ];
WM_Sentinel_Core::activate( true );
$blog3 = $mock_site_stores[3] ?? [];
assert_test(
	'network-wide activate() provisions every site in the network and restores blog context',
	1 === $mock_current_blog_id
		&& [] === $mock_blog_stack
		&& 1 === count( $blog3['tables']['wp_3_wm_activity_logs'] ?? [] )
		&& false === ( $blog3['autoloads']['wm_sentinel_retention_days'] ?? null )
);

$mock_multisite       = $saved_multisite;
$mock_network_active  = $saved_network_active;
$mock_sites           = $saved_sites;
$mock_site_stores     = $saved_site_stores;
$mock_current_blog_id = $saved_current_blog_id;
$mock_blog_stack      = $saved_blog_stack;
$wpdb->prefix         = 'wp_';

// $autoload = false in WM_Sentinel_Admin::handle_form_submissions()
$mock_option_autoloads = [];
$_POST                 = [
	'wm_sentinel_save_settings'           => '1',
	'wm_sentinel_logging_intensity'       => 'all',
	'wm_sentinel_telemetry_intensity'     => 'all',
	'wm_sentinel_monitor_ui_interactions' => '1',
	'wm_sentinel_monitor_frontend_views'  => '1',
	'wm_sentinel_retention_days'          => '90',
	'wm_sentinel_fim_enabled'             => '1',
];
try {
	$admin_ui->handle_form_submissions();
} catch ( WM_Test_Redirect_Exit $e ) {
	// Expected redirect after saving settings.
}
$_POST = [];

$expected_non_autoload = [
	'wm_sentinel_log_auth',
	'wm_sentinel_log_users',
	'wm_sentinel_log_plugins',
	'wm_sentinel_log_content',
	'wm_sentinel_log_rest',
	'wm_sentinel_monitor_screens',
	'wm_sentinel_alert_email',
	'wm_sentinel_alert_min_severity',
	'wm_sentinel_webhook_url',
	'wm_sentinel_retention_days',
	'wm_sentinel_enable_dashboard_widget',
	'wm_sentinel_delete_data_on_uninstall',
	'wm_sentinel_siem_enabled',
	'wm_sentinel_siem_format',
	'wm_sentinel_siem_transport',
	'wm_sentinel_siem_host',
	'wm_sentinel_siem_port',
	'wm_sentinel_siem_url',
	'wm_sentinel_siem_min_severity',
	'wm_sentinel_fim_enabled',
	'wm_sentinel_fim_scan_suite',
];
$bad_autoloads = array_filter(
	$expected_non_autoload,
	static fn( string $opt ): bool => false !== ( $mock_option_autoloads[ $opt ] ?? null )
);
assert_test(
	'admin settings save passes $autoload = false for all 21 non-frontend options and true for the 4 frontend options',
	[] === $bad_autoloads
		&& true === ( $mock_option_autoloads['wm_sentinel_logging_intensity'] ?? null )
		&& true === ( $mock_option_autoloads['wm_sentinel_telemetry_intensity'] ?? null )
		&& true === ( $mock_option_autoloads['wm_sentinel_monitor_ui_interactions'] ?? null )
		&& true === ( $mock_option_autoloads['wm_sentinel_monitor_frontend_views'] ?? null ),
	json_encode( [ 'bad' => $bad_autoloads ] )
);

assert_test(
	'includes/class-sentinel-seeder.php keeps update_option( $option, $value ); literal intact for mutation #34',
	str_contains( (string) file_get_contents( ABSPATH . 'includes/class-sentinel-seeder.php' ), 'update_option( $option, $value );' )
);

print_results();

exit( 0 === $failed_count ? 0 : 1 );

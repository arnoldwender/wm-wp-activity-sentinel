<?php
/**
 * WM Suite Activity Sentinel — SIEM Forwarder
 *
 * Formats a log entry as Syslog RFC 5424, ArcSight CEF:0 or IBM LEEF:2.0 and hands it to a
 * collector over UDP, TCP or HTTPS. Splunk, Microsoft Sentinel, Datadog and Elastic all read at
 * least one of the three, so the plugin speaks their language instead of a private one.
 *
 * Formatting and sending are separate on purpose: `format()` is a pure function of the entry and
 * is what the standalone suite asserts, including the escaping rules each format defines. `send()`
 * touches the network and is never called from a test.
 *
 * @package WM_Activity_Sentinel
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Sentinel_Forwarder {

	/** Wire formats. */
	public const FORMATS = [ 'syslog', 'cef', 'leef' ];

	/** Transports. */
	public const TRANSPORTS = [ 'udp', 'tcp', 'https' ];

	/** Vendor and product as they appear in the CEF and LEEF headers. */
	public const VENDOR  = 'WM Suite';
	public const PRODUCT = 'WM Activity Sentinel';

	/** RFC 5424 facility 13 — "log audit". PRI = facility * 8 + severity. */
	private const SYSLOG_FACILITY = 13;

	/** Plugin severity to RFC 5424 severity. */
	private const SYSLOG_SEVERITY = [
		'info'     => 6, // Informational
		'notice'   => 5, // Notice
		'warning'  => 4, // Warning
		'critical' => 2, // Critical
	];

	/** Plugin severity to the 0–10 scale CEF and LEEF share. */
	private const EVENT_SEVERITY = [
		'info'     => 2,
		'notice'   => 4,
		'warning'  => 6,
		'critical' => 9,
	];

	/** Severity order, to compare an entry against the configured minimum. */
	private const SEVERITY_RANK = [
		'info'     => 1,
		'notice'   => 2,
		'warning'  => 3,
		'critical' => 4,
	];

	/** LEEF 2.0 names its attribute delimiter in the header; a caret cannot appear in our values. */
	private const LEEF_DELIMITER = '^';

	/** Seconds a collector has to accept the connection before the entry is given up on. */
	private const SOCKET_TIMEOUT = 2;

	/**
	 * Forward one entry, if forwarding is on and the entry reaches the configured severity.
	 *
	 * Never throws and never blocks the request that produced the entry: a collector that is down
	 * must not stop WordPress from saving a post. A failure returns false and is not retried.
	 *
	 * @param array<string, mixed> $entry Entry as built by build_entry().
	 * @return bool True when a payload was handed to the transport.
	 */
	public static function forward( array $entry ): bool {
		if ( ! self::should_forward( $entry ) ) {
			return false;
		}

		return self::send( self::format( (string) get_option( 'wm_sentinel_siem_format', 'cef' ), $entry ) );
	}

	/**
	 * Whether this entry is to be sent at all — the policy, with no network in it.
	 *
	 * Separate from forward() so the suite can tell "not sent because of the settings" from "not
	 * sent because the collector refused it": both used to return false from the same method, so a
	 * test could not see which one it had measured.
	 *
	 * @param array<string, mixed> $entry Entry from build_entry().
	 */
	public static function should_forward( array $entry ): bool {
		if ( ! (bool) get_option( 'wm_sentinel_siem_enabled', 0 ) ) {
			return false;
		}

		$minimum = (string) get_option( 'wm_sentinel_siem_min_severity', 'warning' );
		$rank    = self::SEVERITY_RANK[ (string) ( $entry['severity'] ?? 'info' ) ] ?? 1;
		if ( $rank < ( self::SEVERITY_RANK[ $minimum ] ?? 3 ) ) {
			return false;
		}

		return in_array( (string) get_option( 'wm_sentinel_siem_format', 'cef' ), self::FORMATS, true );
	}

	/**
	 * The fields every format prints, gathered in one place.
	 *
	 * @param array<string, mixed> $context Entry context.
	 * @return array<string, mixed>
	 */
	public static function build_entry(
		int $log_id,
		string $severity,
		string $event_type,
		string $user_name,
		array $context,
		string $timestamp
	): array {
		return [
			'log_id'     => $log_id,
			'severity'   => in_array( $severity, [ 'info', 'notice', 'warning', 'critical' ], true ) ? $severity : 'info',
			'event_type' => $event_type,
			'user_name'  => $user_name,
			'context'    => $context,
			'timestamp'  => $timestamp,
			'site'       => site_url(),
		];
	}

	/**
	 * One entry in the requested wire format.
	 *
	 * @param array<string, mixed> $entry Entry from build_entry().
	 */
	public static function format( string $format, array $entry ): string {
		return match ( $format ) {
			'syslog' => self::as_syslog( $entry ),
			'leef'   => self::as_leef( $entry ),
			default  => self::as_cef( $entry ),
		};
	}

	/**
	 * RFC 5424: <PRI>VERSION TIMESTAMP HOSTNAME APP-NAME PROCID MSGID STRUCTURED-DATA MSG
	 *
	 * @param array<string, mixed> $entry Entry.
	 */
	private static function as_syslog( array $entry ): string {
		$severity = (string) $entry['severity'];
		$priority = self::SYSLOG_FACILITY * 8 + ( self::SYSLOG_SEVERITY[ $severity ] ?? 6 );

		$structured = sprintf(
			'[wmSentinel@0 logId="%s" severity="%s" user="%s" site="%s"]',
			self::escape_sd( (string) $entry['log_id'] ),
			self::escape_sd( $severity ),
			self::escape_sd( (string) $entry['user_name'] ),
			self::escape_sd( (string) $entry['site'] )
		);

		return sprintf(
			'<%d>1 %s %s %s - %s %s %s',
			$priority,
			self::rfc3339( (string) $entry['timestamp'] ),
			self::hostname(),
			'wm-activity-sentinel',
			self::sanitise_token( (string) $entry['event_type'] ),
			$structured,
			self::one_line( self::context_as_text( (array) $entry['context'] ) )
		);
	}

	/**
	 * CEF:0|Vendor|Product|Version|SignatureID|Name|Severity|Extensions
	 *
	 * @param array<string, mixed> $entry Entry.
	 */
	private static function as_cef( array $entry ): string {
		$severity   = (string) $entry['severity'];
		$extensions = [
			'rt'           => self::milliseconds( (string) $entry['timestamp'] ),
			'suser'        => (string) $entry['user_name'],
			'externalId'   => (string) $entry['log_id'],
			'cs1Label'     => 'site',
			'cs1'          => (string) $entry['site'],
			'cs2Label'     => 'context',
			'cs2'          => self::context_as_text( (array) $entry['context'] ),
			'cat'          => $severity,
		];

		$pairs = [];
		foreach ( $extensions as $key => $value ) {
			$pairs[] = $key . '=' . self::escape_cef_extension( self::one_line( (string) $value ) );
		}

		return sprintf(
			'CEF:0|%s|%s|%s|%s|%s|%d|%s',
			self::escape_cef_header( self::VENDOR ),
			self::escape_cef_header( self::PRODUCT ),
			self::escape_cef_header( self::plugin_version() ),
			self::escape_cef_header( (string) $entry['event_type'] ),
			self::escape_cef_header( self::readable_name( (string) $entry['event_type'] ) ),
			self::EVENT_SEVERITY[ $severity ] ?? 2,
			implode( ' ', $pairs )
		);
	}

	/**
	 * LEEF:2.0|Vendor|Product|Version|EventID|Delimiter|attributes
	 *
	 * @param array<string, mixed> $entry Entry.
	 */
	private static function as_leef( array $entry ): string {
		$severity   = (string) $entry['severity'];
		$attributes = [
			'devTime'       => self::rfc3339( (string) $entry['timestamp'] ),
			'devTimeFormat' => 'yyyy-MM-dd\'T\'HH:mm:ss\'Z\'',
			'severity'      => (string) ( self::EVENT_SEVERITY[ $severity ] ?? 2 ),
			'cat'           => $severity,
			'usrName'       => (string) $entry['user_name'],
			'identSrc'      => (string) $entry['site'],
			'logId'         => (string) $entry['log_id'],
			'context'       => self::context_as_text( (array) $entry['context'] ),
		];

		$pairs = [];
		foreach ( $attributes as $key => $value ) {
			// The delimiter must not appear inside a value, or the collector splits the attribute.
			$pairs[] = $key . '=' . str_replace( self::LEEF_DELIMITER, ' ', self::one_line( $value ) );
		}

		return sprintf(
			'LEEF:2.0|%s|%s|%s|%s|%s|%s',
			self::VENDOR,
			self::PRODUCT,
			self::plugin_version(),
			self::sanitise_token( (string) $entry['event_type'] ),
			self::LEEF_DELIMITER,
			implode( self::LEEF_DELIMITER, $pairs )
		);
	}

	/**
	 * CEF header fields escape a backslash and the pipe that separates them.
	 */
	private static function escape_cef_header( string $value ): string {
		return str_replace( [ '\\', '|' ], [ '\\\\', '\\|' ], $value );
	}

	/**
	 * CEF extension values escape a backslash and the equals sign that separates key from value.
	 */
	private static function escape_cef_extension( string $value ): string {
		return str_replace( [ '\\', '=' ], [ '\\\\', '\\=' ], $value );
	}

	/**
	 * RFC 5424 structured data escapes a quote, a backslash and a closing bracket.
	 */
	private static function escape_sd( string $value ): string {
		return str_replace( [ '\\', '"', ']' ], [ '\\\\', '\\"', '\\]' ], self::one_line( $value ) );
	}

	/**
	 * A payload is one line: a newline inside a value would read as a second event.
	 */
	private static function one_line( string $value ): string {
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $value ) );
	}

	/**
	 * Event type as a syslog MSGID / LEEF EventID: printable ASCII without spaces.
	 */
	private static function sanitise_token( string $value ): string {
		$token = (string) preg_replace( '/[^A-Za-z0-9_.\-]/', '_', $value );
		return '' === $token ? 'event' : substr( $token, 0, 32 );
	}

	/**
	 * The context as `key=value` pairs, which every collector can parse further.
	 *
	 * @param array<string, mixed> $context Context.
	 */
	private static function context_as_text( array $context ): string {
		$parts = [];
		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
			$parts[] = (string) $key . ':' . ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value );
		}
		return implode( ', ', $parts );
	}

	/**
	 * A readable name for the CEF Name field.
	 */
	private static function readable_name( string $event_type ): string {
		return ucwords( str_replace( [ '_', '-' ], ' ', $event_type ) );
	}

	/**
	 * `Y-m-d H:i:s` (UTC, as the log stores it) as RFC 3339.
	 */
	private static function rfc3339( string $mysql_utc ): string {
		$time = strtotime( $mysql_utc . ' UTC' );
		return gmdate( 'Y-m-d\TH:i:s\Z', false === $time ? time() : $time );
	}

	/**
	 * The same instant in milliseconds, which is what CEF's `rt` expects.
	 */
	private static function milliseconds( string $mysql_utc ): string {
		$time = strtotime( $mysql_utc . ' UTC' );
		return (string) ( ( false === $time ? time() : $time ) * 1000 );
	}

	/**
	 * Host name for the syslog HOSTNAME field.
	 */
	private static function hostname(): string {
		$host = (string) wp_parse_url( site_url(), PHP_URL_HOST );
		return '' === $host ? 'localhost' : $host;
	}

	/**
	 * The plugin version, without assuming the constant is defined.
	 */
	private static function plugin_version(): string {
		return defined( 'WM_SENTINEL_VERSION' ) ? (string) WM_SENTINEL_VERSION : '0.0.0';
	}

	/**
	 * Hand the payload to the configured transport.
	 *
	 * UDP is fire-and-forget, which is what a syslog collector expects; TCP and HTTPS report
	 * whether the collector took the bytes. Nothing is retried and no error is surfaced to the
	 * request: the log entry is already stored, and forwarding is a copy.
	 */
	private static function send( string $payload ): bool {
		$transport = (string) get_option( 'wm_sentinel_siem_transport', 'https' );

		if ( 'https' === $transport ) {
			$url = (string) get_option( 'wm_sentinel_siem_url', '' );
			if ( '' === $url || ! wp_http_validate_url( $url ) ) {
				return false;
			}
			$response = wp_remote_post( $url, [
				'blocking' => false,
				'timeout'  => self::SOCKET_TIMEOUT,
				'headers'  => [ 'Content-Type' => 'text/plain; charset=utf-8' ],
				'body'     => $payload,
			] );
			return ! is_wp_error( $response );
		}

		if ( ! in_array( $transport, [ 'udp', 'tcp' ], true ) ) {
			return false;
		}

		$host = (string) get_option( 'wm_sentinel_siem_host', '' );
		$port = (int) get_option( 'wm_sentinel_siem_port', 514 );
		if ( '' === $host || $port < 1 || $port > 65535 ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a collector that is down must not raise a warning on a page load.
		$socket = @stream_socket_client(
			sprintf( '%s://%s:%d', $transport, $host, $port ),
			$error_number,
			$error_message,
			self::SOCKET_TIMEOUT,
			STREAM_CLIENT_CONNECT
		);

		if ( false === $socket ) {
			return false;
		}

		stream_set_timeout( $socket, self::SOCKET_TIMEOUT );
		// $socket is a UDP/TCP stream to a syslog collector, not a file. WP_Filesystem abstracts files
		// on the host (direct, FTP, SSH) and has no network-stream API, so the "use WP_Filesystem"
		// sniffs do not apply here; Plugin Check reported both as errors on 2026-09-17.
		$written = fwrite( $socket, $payload . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- network socket, see above.
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- network socket, see above.

		return false !== $written;
	}
}

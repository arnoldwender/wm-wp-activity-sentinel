<?php
/**
 * WM Suite Activity Sentinel — Real-Time Alert Dispatcher
 *
 * @package WM_Activity_Sentinel
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Sentinel_Alerts {

	/**
	 * Evaluate log severity and dispatch notifications
	 */
	public static function evaluate_and_dispatch(
		int $log_id,
		string $severity,
		string $event_type,
		string $user_name,
		array $context
	): void {
		$min_severity = get_option( 'wm_sentinel_alert_min_severity', 'critical' );
		$alert_email = get_option( 'wm_sentinel_alert_email', get_option( 'admin_email' ) );

		$severity_ranks = [
			'info'     => 1,
			'notice'   => 2,
			'warning'  => 3,
			'critical' => 4,
		];

		$current_rank = $severity_ranks[ $severity ] ?? 1;
		$threshold_rank = $severity_ranks[ $min_severity ] ?? 4;

		if ( $current_rank < $threshold_rank ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$subject = sprintf( '[%s ALARM] %s auf %s', strtoupper( $severity ), $event_type, $site_name );
		
		$message = "WM Activity Sentinel hat einen Eintrag mit dem Schweregrad " . strtoupper( $severity ) . " protokolliert.\n\n";
		$message .= "Ereignistyp: " . $event_type . "\n";
		$message .= "Schweregrad:  " . strtoupper( $severity ) . "\n";
		$message .= "Benutzer:     " . $user_name . "\n";
		$message .= "Zeitpunkt:    " . current_time( 'mysql', true ) . " UTC\n";
		$message .= "Log-ID:       #" . $log_id . "\n\n";
		$message .= "Kontext-Metadaten:\n" . wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n\n";
		$message .= "-- \nWM Activity Sentinel — Aktivitätsprotokoll";

		global $wpdb;
		$alerts_table = WM_Sentinel_Database::alerts_table();

		if ( ! empty( $alert_email ) && is_email( $alert_email ) ) {
			$sent = wp_mail( $alert_email, $subject, $message );
			
			$wpdb->insert(
				$alerts_table,
				[
					'log_id'        => $log_id,
					'severity'      => in_array( $severity, [ 'warning', 'critical' ], true ) ? $severity : 'critical',
					'alert_message' => $subject,
					'channel'       => 'email',
					'status'        => $sent ? 'sent' : 'failed',
					'created_at'    => current_time( 'mysql', true ),
				],
				[ '%d', '%s', '%s', '%s', '%s', '%s' ]
			);
		}

		// Webhook dispatch if configured
		$webhook_url = get_option( 'wm_sentinel_webhook_url', '' );
		if ( ! empty( $webhook_url ) && wp_http_validate_url( $webhook_url ) ) {
			wp_remote_post( $webhook_url, [
				'blocking' => false,
				'headers'  => [ 'Content-Type' => 'application/json' ],
				'body'     => (string) wp_json_encode( [
					'text'        => $subject,
					'log_id'      => $log_id,
					'event_type'  => $event_type,
					'severity'    => $severity,
					'user'        => $user_name,
					'site'        => site_url(),
					'timestamp'   => current_time( 'mysql', true ),
				] ),
			] );
		}
	}
}

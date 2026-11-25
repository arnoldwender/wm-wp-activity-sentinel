<?php
/**
 * WM Suite Activity Sentinel — Suite Hub Spoke Adapter
 *
 * Implements the WM Suite Hub contract (WM_Plugin_Module_Interface)
 * for centralized telemetry, HMAC hash-chain verification, and automated diagnostics.
 *
 * @package WM_Activity_Sentinel
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
	return;
}

use ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface;

final class WM_Sentinel_Spoke_Adapter implements WM_Plugin_Module_Interface {

	public function get_module_slug(): string {
		return 'wm-activity-sentinel';
	}

	public function get_name(): string {
		return 'WM Activity Sentinel';
	}

	public function get_version(): string {
		return defined( 'WM_SENTINEL_VERSION' ) ? WM_SENTINEL_VERSION : '1.4.3';
	}

	public function get_description(): string {
		return 'Aktivitätsprotokoll mit HMAC-SHA256-Hashkette, Kettenprüfung, Alarmen und Export. Manipulation wird nachweisbar, nicht verhindert.';
	}

	/**
	 * ERROR with a broken chain; DEGRADED when the chain key lives in the database (AUTH_KEY not in
	 * wp-config.php) or AUTH_KEY is the wp-config-sample.php placeholder, because then anyone with database
	 * access can re-sign the chain.
	 */
	public function get_health_status(): string {
		$audit = WM_Sentinel_Integrity::verify_chain();
		if ( ! $audit['valid'] ) {
			return 'ERROR';
		}
		if ( 'wp-config' !== ( $audit['key_source'] ?? 'wp-config' ) ) {
			return 'DEGRADED';
		}
		return 'HEALTHY';
	}

	public function get_sbom_data(): array {
		return [
			'bom-ref'     => 'pkg:wordpress/wm-activity-sentinel@' . $this->get_version(),
			'type'        => 'application',
			'name'        => $this->get_name(),
			'version'     => $this->get_version(),
			'description' => $this->get_description(),
			'scope'       => 'required',
			'hashes'      => [
				[
					'alg'     => 'SHA-256',
					'content' => hash_file( 'sha256', WM_SENTINEL_FILE ),
				],
			],
			// The licence the plugin ships under: the header, readme.txt and LICENSE say GPLv2 or later.
			// Until 2026-11-05 this declared a proprietary licence with an id and a name at once;
			// `Proprietary` is not an SPDX id, and CycloneDX takes an SPDX id or a name, not both.
			'licenses'    => [
				[
					'license' => [
						'id' => 'GPL-2.0-or-later',
					],
				],
			],
			// Features, not legal labels. Until 2026-09-15: "wm:compliance:GoBD Unveränderbarkeit" for a
			// log whose rows can be changed (the chain makes changes detectable, not impossible).
			'properties'  => [
				[
					'name'  => 'wm:features:chain',
					'value' => 'HMAC-SHA256 chain over every row, chain tip mirrored in wp_options, verification in admin, WP-CLI and hub',
				],
				[
					'name'  => 'wm:features:sensors',
					'value' => 'logins, users and roles, application passwords, plugins and themes, options, XML export, file editor, selected REST routes, admin and frontend page views and clicks of signed-in users',
				],
				[
					'name'  => 'wm:features:privacy',
					'value' => 'daily-keyed IP HMAC, WordPress personal data exporter and eraser with chained erasure record',
				],
			],
		];
	}

	public function get_dependencies(): array {
		return [];
	}

	public function get_settings_url(): ?string {
		return admin_url( 'admin.php?page=wm-sentinel-settings' );
	}

	public function get_telemetry_metrics(): array {
		$kpis = WM_Sentinel_Database::get_kpis();
		$tip = WM_Sentinel_Database::get_chain_tip();
		$audit = WM_Sentinel_Integrity::verify_chain();

		// Until 2026-09-15 latest_entry_hash read $tip['tip'], a key the tip never has, so the hub
		// always showed "GENESIS" (measured with 493 verified entries).
		return [
			'total_events'      => $kpis['total_logs'] ?? 0,
			'critical_24h'      => $kpis['critical_24h'] ?? 0,
			'chain_integrity'   => $audit['valid'] ? 'INTACT' : 'BROKEN',
			'chain_error'       => $audit['error_reason'] ?? null,
			'verified_blocks'   => $audit['total_verified'] ?? 0,
			'pseudonymized'     => (int) ( $audit['pseudonymized'] ?? 0 ),
			'chain_key_source'  => (string) ( $audit['key_source'] ?? '' ),
			'latest_entry_hash' => is_array( $tip ) && '' !== (string) $tip['hash'] ? substr( (string) $tip['hash'], 0, 16 ) . '...' : null,
		];
	}

	public function execute_action( string $action, array $params = [] ): array {
		switch ( $action ) {
			case 'self_test':
			case 'audit_chain':
			case 'verify_chain':
				$audit = WM_Sentinel_Integrity::verify_chain();
				return [
					'success' => $audit['valid'],
					'message' => $audit['valid']
						? sprintf( 'Kette intakt: %d Einträge geprüft.', (int) ( $audit['total_verified'] ?? 0 ) )
						: sprintf( 'Kette gebrochen bei Eintrag %s: %s', (string) ( $audit['broken_at_id'] ?? 'Kettenende' ), (string) ( $audit['error_reason'] ?? '' ) ),
				];

			// 'purge_cache' answered "Telemetrie-Puffer erfolgreich aktualisiert" without doing anything
			// until 2026-09-15; there is no cache, so it now falls through to the unknown action.
			default:
				return [
					'success' => false,
					'message' => sprintf( 'Unbekannte Spoke-Aktion "%s".', esc_html( $action ) ),
				];
		}
	}
}

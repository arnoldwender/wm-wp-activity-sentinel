=== WM Activity Sentinel ===
Contributors: arnoldwender
Tags: audit log, activity log, security, hash chain, siem
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.4.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Activity log with an HMAC-SHA256 hash chain: edits, deletions and truncation become detectable, not impossible.

== Description ==

WM Activity Sentinel writes an activity log in which every entry is linked to its predecessor by an HMAC-SHA256 hash keyed with AUTH_KEY from wp-config.php.

* **Sensors:** logins and failed logins, application passwords, new and deleted users, role changes, plugin activation, deactivation, deletion and updates, theme switches, nine selected options, the XML export and the export page, the plugin and theme file editor, UpdraftPlus and Duplicator backup hooks, calls to /wp/v2/plugins and unauthenticated calls to /wp/v2/users, admin screens and frontend pages opened by signed-in users, and clicks of signed-in users.
* **Verification:** the integrity screen, the dashboard widget, `wp sentinel audit` and the WM Suite Hub report the first edited or missing entry, a shortened chain and an emptied table.
* **Alerts:** plain-text mail and an optional JSON webhook for warning and critical entries at or above the chosen severity.
* **Export:** CSV with formula-safe cells and a JSON package with a SHA-256 checksum (not signed); WordPress personal data exporter and eraser.
* **SIEM forwarding (off by default):** every entry at or above the chosen severity as Syslog RFC 5424, CEF:0 or LEEF:2.0 over UDP, TCP or HTTPS, for Splunk, Microsoft Sentinel, Datadog, Elastic and QRadar.
* **File integrity monitor:** a daily comparison of the core files against the official catalogue at api.wordpress.org, including executable files in wp-admin or wp-includes that the catalogue does not list, plus a baseline of the WM Suite plugins whose fingerprint is sealed into the hash chain.
* **Developer hooks and Block Bindings:** fires `wm_sentinel_ready`, exposes `wm_sentinel_monitored_options` and `wm_sentinel_public_metrics`, subscribes to `wm_activity_sentinel_log_event` and `wm_sentinel_security_incident`, and registers WordPress 6.5+ Block Bindings (`wm-sentinel/metrics`) and GenerateBlocks 2.x Dynamic Tags (`wm_sentinel_metric`) for non-sensitive status and counters.
* **Opt-in uninstall:** preserves the chained audit tables, chain tip mirror and sealed FIM baseline by default (`wm_sentinel_delete_data_on_uninstall = 0`), removing them only when explicitly enabled under Settings.

Whoever can read wp-config.php can re-sign the chain. Nothing is deleted automatically. The plugin makes no legal assessment.

**No Support & Zero Liability ("AS IS"):** This plugin is provided free of charge as personal open-source software with **zero support** and **no private support**. Do not contact the author via email, `arnoldwender.com`, or direct messages. Report reproducible defects exclusively at `https://github.com/arnoldwender/wm-wp-activity-sentinel/issues` (no SLA or response guarantee). Under GPLv2+ Sections 11-12 it is distributed **"AS IS"** without warranty, legal advice, or author liability.

== Installation ==

1. Upload the `wm-activity-sentinel` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Make sure `AUTH_KEY` is defined in wp-config.php.
4. Open 'WM Sentinel' in the admin menu: Aktivitäts-Stream, Integritäts-Audit, Dateiintegrität, Export, Einstellungen, Handbuch.
5. On 'Dateiintegrität', capture the baseline of the WM Suite plugins once, and again after every update of one of them.

== Frequently Asked Questions ==

= Is technical or private support provided? =

No. This plugin comes with zero support (`0` support). Private inquiries sent via email, `arnoldwender.com`, or social/chat channels are not answered. Reproducible bugs belong solely on GitHub Issues (`https://github.com/arnoldwender/wm-wp-activity-sentinel/issues`) with no guarantee of reply or resolution. The software is provided **"AS IS"** at your sole risk with zero warranty or liability (see `SUPPORT.md` in the repository).

== Screenshots ==

1. Activity stream: logged events, newest first, each linked to the previous one by an HMAC-SHA256 chain
2. Integrity audit: the hash chain verified from the first entry to the recorded chain state
3. Settings: logging level, click depth and the monitored areas
4. Dashboard widget: chain status, counts of the last 24 hours and the latest entries

== Changelog ==

= 1.4.3 =
* Fixed: On the plugin's admin screens, which carry WordPress's help tab, the header was shorter than the page by the width of the «Hilfe» toggle (measured 1155 px of 1238 px): WordPress floats the toggle above the page and the header may not overlap it. The bundled family stylesheet, now identical to WM Suite Hub 1.2.5, starts the header below the toggle, so it spans the page.
* Fixed: the logging level is called «Protokollierungsstufe» instead of «Master-Protokollierungs-Intensität», the level that writes fewer entries is «Nur Warnungen» instead of «Erhöhte Sicherheit», and the overview no longer says «Standard» records every data and configuration change. The alert address no longer promises mail on critical events only; the threshold decides. The alert mail no longer calls each warning a security-critical incident or the plugin a forensic protection system.

= 1.4.2 =
* Fixed: The stream was called «Echtzeit-Stream» and «Live-Stream», although nothing refreshes the page. It is the «Aktivitäts-Stream».
* Fixed: Widget, GenerateBlocks tag, integrity page and the personal data export spoke of blocks, a «Master-Block» and a «Block-Hash». The log holds entries, and the recorded tip is the chain state kept outside the log table. The `verified_blocks` metric key stays.
* Fixed: A mismatch between the recorded chain state and the end of the chain is reported as newest entries removed or replaced, not removed alone.
* Fixed: Screenshot captions promised a real-time stream, a Merkle chain, File Integrity Monitoring views and retention, SIEM and uninstall controls the screenshots do not show; all four were taken again. The READMEs no longer call the AI-produced translations human-grade.

= 1.4.1 =
* Fixed: Seven catalogs (fi, fi_FI, ga, hr_HR, it_IT, pl_PL, sl_SI) still named the agency that 1.3.5 removed from the catalogs, in the sentence on the file integrity monitor's baseline. They name the WM Suite plugins now, as the German source string does.

= 1.4.0 =
* The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
* The `.wordpress-org/` banners show the current version.
* Fixed: `wp sentinel demodaten --reset --yes` stopped with "unknown --yes parameter": the flag was missing from the command synopsis, so WP-CLI refused it before the confirmation could read it, and a script could not reset the log without a prompt. The synopsis now lists `[--yes]`.
* Removed: The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

= 1.3.5 =
* Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.
* Added root `SUPPORT.md`, GitHub Issue templates, explicit zero-support & "AS IS" liability notices in READMEs and `readme.txt`, and normalized canonical GPLv2+ `arnoldwender.com` / `arnoldwender/wm-wp-activity-sentinel` links.
* Updated `.wordpress-org/` visual banners to personal `ARNOLD WENDER · OPEN SOURCE` branding.

= 1.3.4 =
* Added live `.wordpress-org/` visual kit assets (banner, icons, and live WordPress screenshots) and updated documentation links for v1.3.4.

= 1.3.3 =
* Point Plugin URI to the official plugin page.

= 1.3.2 =
* Fixed: keyboard Focus-Trap (`Tab` / `Shift+Tab`, `focusin` guard, sibling `aria-hidden`/`inert` isolation, `tabindex="0"` on `#wm-sentinel-context-json`) and high-specificity `.wp-core-ui` 44×44 px touch-target enforcement on `.button-small` context and dashboard widget buttons.
* Added: Multisite `wp_initialize_site` and network-wide activation provisioning (`WM_Sentinel_Core::on_initialize_site()`, `WM_Sentinel_Core::activate()`) plus `$autoload = false` for non-frontend internal options.

= 1.3.1 =
* Added: developer extensibility hooks (`wm_sentinel_ready` action, `wm_sentinel_public_metrics` filter), WordPress 6.5+ Block Bindings source (`wm-sentinel/metrics`) and GenerateBlocks 2.x Dynamic Tag (`wm_sentinel_metric`) exposing non-sensitive chain status and event counters.
* Added: CycloneDX 1.6 software bill of materials (`bom.json`), WordPress Playground blueprint (`.wordpress-org/blueprints/blueprint.json`), and complete gettext translations across all 28 supported locales.
* Hardened: parameterized all remaining `$wpdb` read and counter queries via `$wpdb->prepare()` with `%i` identifier placeholders, escaped KPI modifier class attributes, added `declare(strict_types=1);` to `WM_Sentinel_CLI_Command`, and guarded fallback error logging behind `WP_DEBUG`.
* Added: inter-plugin audit bridges (`wm_activity_sentinel_log_event` from WM Suite Hub and `wm_sentinel_security_incident` from RequestShield / external security modules, hashing any supplied client IP via daily-salted HMAC without storing raw IP in context) plus the `wm_sentinel_monitored_options` filter.
* Changed: uninstall preserves the chained audit tables, chain tip mirror and sealed FIM baseline by default, with an explicit opt-in toggle (`wm_sentinel_delete_data_on_uninstall`) in Settings to drop all data on plugin deletion.
* Added: the retention setting is applied. A daily pass neutralises name, e-mail address and IP identifier of entries past `wm_sentinel_retention_days` (default 90); the row, its chain position and its context stay, because removing a row from a hash chain would make the audit report the site's own retention policy as tampering. `wp sentinel retention [--dry-run]`.
* Added: forwarding to a SIEM in Syslog RFC 5424, CEF:0 and LEEF:2.0 over UDP, TCP or HTTPS, with its own switch and minimum severity, off by default.
* Added: a file integrity monitor comparing the tree against api.wordpress.org/core/checksums/1.0/ and the WM Suite plugins against a baseline sealed into the hash chain; `wp sentinel fim [--baseline]`.
* A version without a published catalogue is reported as not checked, never as clean.
* Fixed: the demo import sent real alert mails and webhooks and reported 15 entries whatever was written.
* Fixed: a failed row lock chained the new entry to GENESIS; a failed commit still alerted.
* Fixed: an AUTH_KEY left at the wp-config-sample.php phrase is reported as a public key.
* Fixed: messages after deleting no demo data, handbook severities, the exports switch description, frontend tracker cache version, telemetry transients on uninstall.
* The WordPress tests that write to the log need WM_SENTINEL_SCRATCH_INSTALL=1 and exit 1 on failure.
* The marketing banner is no longer part of the package.
* The WordPress personal data exporter and eraser work (fatal error before); erasures keep the chain verifiable through a chained erasure record.
* WP-CLI `demodaten --import` and `--reset` work; `audit` names the broken entry.
* Telemetry accepts only the tracker's own event types from signed-in users, always as info, at most 60 per user per minute (any signed-in account could forge critical entries before).
* Sensors listen to hooks WordPress fires; the authentication switch filters logins; the factory reset restores the settings.
* No Spanish strings, immutability or GoBD statements in the admin; decorative icons hidden from screen readers. (The retention field said "stored, not applied", which was honest on 2026-09-15 and superseded on 2026-09-17 by the entry at the top of this list.)
* Admin screens and dashboard widget on the WM Suite family sheets.

= 1.3.0 =
* Removed `rebuild_chain()`, which re-signed the log when demo data was deleted.
* The chain tip is mirrored outside the log table, so deleting the newest entries is detected.
* No fallback key literal in the source when AUTH_KEY is missing.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.4.3 =
Labels and stylesheet only: the logging level and its options are named for what they do, the alert mail says what it recorded, and the admin header spans the page next to the help tab. Stored values and settings do not change.

= 1.4.2 =
Text fixes in the admin screens, the privacy export and the documentation. No data or settings change.

= 1.4.1 =
Translation fix only: seven catalogs no longer name the agency. No data or settings change.

= 1.4.0 =
Hub users: update WM Suite Hub to 1.2.0 together with this plugin. The hub contract moved to a new PHP namespace; next to an older hub the plugin keeps working, but it is not listed in the cockpit and its admin page has no menu entry until the hub is updated.

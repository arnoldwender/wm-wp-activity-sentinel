#!/usr/bin/env bash
#
# tests/run-mutations.sh — checks that tests/test-suite.php defends each fix of 2026-09-15.
#
# For every mutation: copy the repository (tracked and untracked files, no .git) to a temp dir, undo
# one fix with a literal Perl substitution, run the standalone suite and require it to report at
# least one failed assertion. A mutation whose pattern no longer matches is reported as "not applied"
# and counts as a failure, so the list cannot rot silently when the code moves; so does a run that
# never reaches the results line. A control run on the unmutated copy goes first.
#
# Usage: bash tests/run-mutations.sh        (from the repository root)
# Exit:  0 when the control is green and every mutation applied and turned the suite red.

set -uo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d "${TMPDIR:-/tmp}/wm-sentinel-mutations.XXXXXX")
trap 'rm -rf "$WORK"' EXIT

# file | perl substitution (delimiter #) | description
MUTATIONS=(
  "includes/class-sentinel-exporter.php|s#WM_Sentinel_Database::logs_table\(\), \\\$email_address#WM_Sentinel_Database::get_table_name(), \\\$email_address#|privacy exporter calls the missing get_table_name()"
  "includes/class-sentinel-integrity.php|s#if \( \\\$accepted_as_pseudonymized \) \{#if ( false ) {#|pseudonymised rows no longer accepted"
  "includes/class-sentinel-exporter.php|s#if \( '' !== \\\$context && false !== stripos\( \\\$context, \\\$email_address \) \) \{#if ( false ) {#|address left in context"
  "includes/class-sentinel-exporter.php|s#'privacy_erasure_applied',\n\t\t\t'privacy',#'privacy_erasure_noted',\n\t\t\t'privacy',#|erasure record not recognised"
  "includes/class-sentinel-logger.php|s#\\\$always = in_array\( \\\$event_type, self::ALWAYS_RECORDED, true \);#\\\$always = false;#|erasure record suppressed by intensity"
  "includes/class-sentinel-logger.php|s#(\\\$log_id = \(int\) \\\$wpdb->insert_id;\n)#\$1\t\t\t\\\$wpdb->query( 'COMMIT' );\n#|chain tip written after COMMIT"
  "includes/class-sentinel-logger.php|s#'user_login_success', 'user_login_failed', ##|auth switch ignores the emitted login events"
  "includes/class-sentinel-logger.php|s#'option', 'file' \]#'option' ]#|file editor entries not covered by a switch"
  "includes/class-sentinel-logger.php|s#: wp_salt\( 'nonce' \);#: 'wm_sentinel_default_salt_2026';#|IP hash falls back to a shipped literal"
  "includes/class-sentinel-cli.php|s#WM_Sentinel_Seeder::seed_demo_data\( 15 \)#WM_Sentinel_Seeder::seed()#|CLI import calls missing seed()"
  "includes/class-sentinel-cli.php|s#WM_Sentinel_Seeder::reset_to_factory_defaults\(\);#WM_Sentinel_Seeder::factory_reset();#|CLI reset calls missing factory_reset()"
  "includes/class-sentinel-cli.php|s#\(int\) \\\$result\['broken_at_id'\] \)#(int) ( \\\$result['failed_log_id'] ?? 0 ) )#|CLI audit reads a key verify_chain never returns"
  "includes/class-sentinel-cli.php|s#\t \* \[--yes\]\n\t \* : [^\n]*\n\t \*\n##|the demodaten synopsis drops [--yes], so WP-CLI refuses --reset --yes as unknown"
  "includes/class-sentinel-seeder.php|s#update_option\( \\\$option, \\\$value \);##|factory reset leaves the real settings"
  "includes/class-sentinel-sensors.php|s#'wp_create_application_password'#'application_password_created'#|application password sensor on a hook that never fires"
  "includes/class-sentinel-sensors.php|s#'wp_ajax_edit-theme-plugin-file'#'wp_edit_theme_plugin_file'#|file editor sensor on a hook that never fires"
  "includes/class-sentinel-sensors.php|s#'new_theme'      => \(string\) wp_get_theme\(\)->get\( 'Name' \),#'new_theme'      => \\\$old_name,#|theme switch logs the old theme as new"
  "includes/class-sentinel-sensors.php|s#(public static function telemetry_permission\(\) \{\n\t\t)if \( is_user_logged_in\(\) \) \{#\$1if ( true ) {#|telemetry open to visitors"
  "includes/class-sentinel-sensors.php|s#! in_array\( \\\$event_type, self::TELEMETRY_EVENT_TYPES, true \) \|\|#false ||#|any telemetry event type accepted"
  "includes/class-sentinel-sensors.php|s#'info',\n\t\t\t\t\\\$clean_meta#(string) ( \\\$evt['severity'] ?? 'info' ),\n\t\t\t\t\\\$clean_meta#|telemetry severity taken from the request"
  "includes/class-sentinel-sensors.php|s#if \( \\\$used >= self::TELEMETRY_RATE_LIMIT \) \{#if ( false ) {#|no telemetry rate limit"
  "includes/class-sentinel-sensors.php|s#foreach \( self::TELEMETRY_METADATA_KEYS as \\\$key \) \{#foreach ( array_keys( \\\$raw_meta ) as \\\$key ) {#|any telemetry metadata key stored"
  "includes/class-sentinel-spoke-adapter.php|s#\\\$tip\['hash'\]#( \\\$tip['tip'] ?? '' )#g|adapter reads the tip under a missing key"
  "includes/class-sentinel-spoke-adapter.php|s#(\t\t\tdefault:\n)#\t\t\tcase 'purge_cache':\n\t\t\t\treturn [ 'success' => true, 'message' => 'ok' ];\n\n\$1#|no-op purge_cache action back"
  "includes/class-sentinel-spoke-adapter.php|s#'id' => 'GPL-2\.0-or-later',#'id'   => 'Proprietary',\n\t\t\t\t\t\t'name' => 'Proprietary Commercial License',#|the SBOM declares the proprietary licence again, with an id and a name at once"
  "includes/class-sentinel-spoke-adapter.php|s#('id' => 'GPL-2\.0-or-later',\n)#\$1\t\t\t\t\t\t'name' => 'GNU General Public License v2.0 or later',\n#|the SBOM licence carries an id and a name at once again, which CycloneDX 1.6 does not accept"
  "uninstall.php|s#\t\t'wm_sentinel_log_auth',\n##|uninstall leaves a real setting"
  "includes/class-sentinel-admin.php|s#Gelöscht wird nichts#Einträge werden automatisch gelöscht#|the retention field promises deletion again, which is what it did until 2026-09-15 and never performed"
  "includes/class-sentinel-admin.php|s#'Springen zu:'#'Saltar a:'#|Spanish string back in the German admin"
  "includes/class-sentinel-exporter.php|s#('verified_blocks'   => \\\$integrity\['total_verified'\],\n)#\$1\t\t\t\t'compliance_shield' => 'GoBD, GeschGehG',\n#|GoBD label back in the JSON package"
  "includes/class-sentinel-admin.php|s#Notice und höher \(Notice-Einträge selbst lösen keinen Alarm aus\)#Alle Hinweise (Notice+)#|alert threshold label implies notice entries alert"
  "includes/class-sentinel-admin.php|s#<span class=\"dashicons dashicons-bell\" aria-hidden=\"true\"></span>#<span class=\"dashicons dashicons-bell\"></span>#|a decorative icon announced to screen readers"
  "includes/class-sentinel-admin.php|s#<th scope=\"col\" class=\"wm-sentinel-col-id\">#<th scope=\"col\" style=\"width: 70px;\">#|inline pixel style back in the stream table"
  "includes/class-sentinel-logger.php|s#if \( null === \\\$last_entry && '' !== \(string\) \\\$wpdb->last_error \) \{#if ( false ) {#|a failed row lock chains the entry to GENESIS again"
  "includes/class-sentinel-logger.php|s#if \( false === \\\$wpdb->query\( 'COMMIT' \) \) \{#\\\$wpdb->query( 'COMMIT' ); if ( false ) {#|a failed COMMIT still reports the entry and alerts"
  "includes/class-sentinel-logger.php|s#! self::\\\$alerts_muted && ##|the demo import sends alert mails again"
  "includes/class-sentinel-seeder.php|s#if \( is_int\( \\\$written \) \) \{#if ( true ) {#|the demo import counts refused entries again"
  "includes/class-sentinel-logger.php|s#self::PLACEHOLDER_KEY === \(string\) AUTH_KEY \? 'wp-config-placeholder' : 'wp-config'#'wp-config'#|the sample placeholder key reported as a wp-config key"
  "includes/class-sentinel-cli.php|s#if \( 0 === \\\$count \) \{\n\t\t\t\tWP_CLI::success\( 'Keine Demo-Einträge vorhanden#if ( false ) {\n\t\t\t\tWP_CLI::success( 'Keine Demo-Einträge vorhanden#|deleting no demo data reports a reset again"
  "includes/class-sentinel-admin.php|s#wp_login \(Info\), wp_login_failed und wp_create_application_password \(Warning\)#wp_login, wp_login_failed, wp_create_application_password#|handbook severities back to the old table"
  "includes/class-sentinel-sensors.php|s#\(string\) \( \@filemtime\( \\\$tracker \) \?: WM_SENTINEL_VERSION \)#WM_SENTINEL_VERSION#|frontend tracker versioned by plugin version again"
  "tests/test-sentinel-chain.php|s#\n// The docblock warning did not stop runs.*?\n\}\n#\n#s|the chain test truncates any install again"
  "uninstall.php|s#_transient_wm_sentinel_telemetry_#_transient_wm_sentinel_other_#g|uninstall leaves the telemetry transients"
  "includes/class-sentinel-integrity.php|s# Entweder wurden die Daten nachträglich in der Datenbank verändert, oder das Protokoll wurde mit einem anderen AUTH_KEY geschrieben \(etwa vor einem Umzug der Website\)\.# (Daten wurden nachträglich in der Datenbank verändert).#|a hash mismatch blamed on editing only"
  "includes/class-sentinel-forwarder.php|s#(private static function escape_cef_header\( string \\\$value \): string \{\n)#\$1\t\treturn \\\$value;\n#|CEF header stops escaping the pipe that separates its fields"
  "includes/class-sentinel-forwarder.php|s#(private static function escape_cef_extension\( string \\\$value \): string \{\n)#\$1\t\treturn \\\$value;\n#|CEF extensions stop escaping the equals sign"
  "includes/class-sentinel-forwarder.php|s#private const SYSLOG_FACILITY = 13;#private const SYSLOG_FACILITY = 1;#|syslog stops announcing itself as log audit, so PRI is wrong"
  "includes/class-sentinel-forwarder.php|s#'critical' => 2, // Critical#'critical' => 6, // Critical#|a critical entry leaves as syslog severity informational"
  "includes/class-sentinel-forwarder.php|s#if \( ! \(bool\) get_option\( 'wm_sentinel_siem_enabled', 0 \) \) \{#if ( false ) {#|entries leave for a collector before forwarding is switched on"
  "includes/class-sentinel-forwarder.php|s#if \( \\\$rank < \( self::SEVERITY_RANK\[ \\\$minimum \] \?\? 3 \) \) \{#if ( false ) {#|the configured minimum severity stops filtering"
  "includes/class-sentinel-forwarder.php|s#return in_array\( \(string\) get_option\( 'wm_sentinel_siem_format', 'cef' \), self::FORMATS, true \);#return true;#|an unknown wire format is guessed instead of refused"
  "includes/class-sentinel-forwarder.php|s#self::LEEF_DELIMITER, ' ', self::one_line\( \\\$value \)#self::one_line( \\\$value ), '', ''#|a caret inside a LEEF value splits the attribute"
  "includes/class-sentinel-logger.php|s#if \( ! self::\\\$alerts_muted && class_exists\( 'WM_Sentinel_Forwarder' \) \) \{#if ( false ) {#|the logger stops handing entries to the forwarder"
  "includes/class-sentinel-fim.php|s#if \( str_starts_with\( \\\$relative, 'wp-content/' \) \) \{#if ( false ) {#|the bundled themes under wp-content are scanned as core files again"
  "includes/class-sentinel-fim.php|s#! hash_equals\( \\\$expected, strtolower\( \\\$actual \) \)#false#|an edited core file matches the catalogue whatever it contains"
  "includes/class-sentinel-fim.php|s#'readme.html', 'license.txt', 'wp-config-sample.php'#'readme.html'#|a deleted license.txt is reported as a missing core file"
  "includes/class-sentinel-fim.php|s#! in_array\( strtolower\( \\\$file->getExtension\(\) \), self::EXECUTABLE_EXTENSIONS, true \)#false#|every unexpected file is reported, not only the executable ones"
  "includes/class-sentinel-fim.php|s#if \( '' === \\\$relative \|\| isset\( \\\$checksums\[ \\\$relative \] \) \) \{#if ( true ) {#|a dropped file in wp-includes stops being reported"
  "includes/class-sentinel-fim.php|s#if \( ! is_array\( \\\$checksums \) \) \{#\\\$checksums = is_array( \\\$checksums ) ? \\\$checksums : [ 'index.php' => '0123456789abcdef0123456789abcdef' ]; if ( false ) {#|an unknown version is taken for a usable catalogue"
  "includes/class-sentinel-fim.php|s#\\\$relative = self::normalise_relative_path\( \\\$path \);#\\\$relative = \\\$path;#|a catalogue entry may walk out of the installation again"
  "includes/class-sentinel-fim.php|s#return hash_equals\( \\\$sealed, self::manifest_fingerprint\( \\\$baseline \) \) \? 'sealed' : 'broken';#return 'sealed';#|an edited baseline still reports itself as sealed"
  "includes/class-sentinel-fim.php|s#wp_json_encode\( \\\$manifest, JSON_UNESCAPED_SLASHES#wp_json_encode( array_keys( \\\$manifest ), JSON_UNESCAPED_SLASHES#|the seal covers only the file names, so changing a hash in the baseline is invisible"
  "includes/class-sentinel-fim.php|s#'event_type' => self::EVENT_SEALED,#'event_type' => 'fim_baseline_captured',#|the seal is looked up under an event type nothing writes"
  "includes/class-sentinel-fim.php|s#\\\$context\['fingerprint'\] \?\? ''#\\\$context['fingerprints'] ?? ''#|the sealed fingerprint is read under a missing context key"
  "includes/class-sentinel-fim.php|s#if \( \[\] !== \(array\) \\\$core\['modified'\] \|\| \[\] !== \(array\) \\\$core\['unexpected'\] \|\| 'broken' === \( \\\$suite\['seal'\] \?\? '' \) \) \{#if ( false ) {#|a modified core file no longer raises a critical entry"
  "includes/class-sentinel-fim.php|s# \|\| null !== \\\$core\['error'\] \) \{# ) {#|a scan that could not load the catalogue reports itself as clean"
  "includes/class-sentinel-fim.php|s#public const OBJECT_TYPE = 'integrity';#public const OBJECT_TYPE = 'file';#|a module switch can silence a modified wp-includes again"
  "includes/class-sentinel-fim.php|s#if \( ! \(bool\) get_option\( 'wm_sentinel_fim_enabled', 1 \) \) \{#if ( false ) {#|the scheduled scan runs although the monitor is switched off"
  "includes/class-sentinel-fim.php|s#if \( \\\$use_cache \) \{#if ( false ) {#|every page load asks api.wordpress.org again"
  "includes/class-sentinel-fim.php|s#! in_array\( strtolower\( \\\$file->getExtension\(\) \), self::SUITE_EXTENSIONS, true \)#false#|the baseline covers files that neither run nor render"
  "includes/class-sentinel-fim.php|s#! str_starts_with\( \\\$entry, self::SUITE_PREFIX \)#false#|the baseline covers third-party plugins"
  "includes/class-sentinel-fim.php|s#\\\$added = array_values\( array_diff\( array_keys\( \\\$current \), array_keys\( \\\$baseline \) \) \);#\\\$added = [];#|a file dropped into a WM Suite plugin after the baseline is not reported"
  "uninstall.php|s#\t\t'wm_sentinel_fim_baseline',\n##|uninstall leaves the sealed baseline behind"
  "tests/test-suite.php|s#return array_slice\( \\\$rows, \(int\) \\\$m\[6\], \(int\) \\\$m\[5\] \);#return array_map( static fn( \\\$r ) => clone \\\$r, array_values( \\\$mock_db_tables[ self::table( \\\$m[1] ) ] ?? [] ) );#|the suite mock stops filtering get_logs, so the seal lookup reads the genesis block"
  "includes/class-sentinel-fim.php|s#\t\treturn 'en_US';#\t\treturn function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';#|the catalogue is asked for by display language instead of installed package"
  "includes/class-sentinel-fim.php|s#return ! str_contains\( \\\$relative, '/' \) && str_ends_with\( strtolower\( \\\$relative \), '.html' \);#return false;#|the translated readme of a localized package is reported as a missing core file"
  "includes/class-sentinel-fim.php|s#return ! \\\$current->isDir\(\) \|\| ! in_array\( \\\$current->getFilename\(\), self::SUITE_EXCLUDED_DIRS, true \);#return true;#|node_modules and .git are walked into the baseline again"
  "includes/class-sentinel-fim.php|s#'node_modules', '.git', '.github'#'.github'#|node_modules and .git alone are enough to blow the baseline up"
  "includes/class-sentinel-fim.php|s#if \( count\( \\\$manifest \) > \\\$max \) \{#if ( false ) {#|an oversized baseline is stored silently instead of refused"
  "docs/SETTINGS.md|s#wm_sentinel_telemetry_intensity#telemetry_intensity#|an option the plugin reads stops being documented (the state the whole settings surface was in)"
  "wm-activity-sentinel.php|s#add_action\( 'plugins_loaded', \[ 'WM_Sentinel_Retention', 'sync_schedule' \], 5 \);##|the retention pass is only scheduled on activation, so it arrives dead on every already-active install"
  "includes/class-sentinel-retention.php|s#\\\$always_recorded = WM_Sentinel_Logger::ALWAYS_RECORDED;#\\\$always_recorded = [ 'nothing' ];#|retention pseudonymises the chain's own records, stripping the fingerprints later rows verify through"
  "includes/class-sentinel-retention.php|s#if \( \\\$days < 30 \) \{\n\t\t\treturn 0;\n\t\t\}##|a retention of 0 is read as 'pseudonymise everything' instead of 'keep everything'"
  "includes/class-sentinel-retention.php|s#if \( ! \\\$record_id \) \{#if ( false ) {#|rows are changed even when their fingerprints could not be recorded, so they read as tampering for ever"
  "includes/class-sentinel-integrity.php|s#\\\$residual_records = \[ 'privacy_erasure_applied', WM_Sentinel_Retention::RECORD_EVENT \];#\\\$residual_records = [ 'privacy_erasure_applied' ];#|the audit stops honouring the retention record, so the site's own retention policy reads as tampering"
  "includes/class-sentinel-logger.php|s#, 'retention_pseudonymized' \];# ];#|the retention record is filtered out by a strict logging level, leaving its rows unverifiable"
  "README.md|s#retention days \(applied nightly since 2026-09-17: entries past the window lose name, e-mail address and IP identifier; nothing is deleted\)#retention days (stored, not applied)#|README calls a shipped feature unapplied again, in the list of what the plugin does"
  "README.md|s#- \*\*Nothing is deleted, and that is the design, not a gap\.\*\*.*?wait for Arnold\.#- Nothing is deleted automatically; the retention setting is stored and not applied. How long the log is kept waits for Arnold.#s|README's Known issues denies the retention pass again"
  "README.md|s#wp sentinel retention#wp sentinel retro#g|the command that runs the retention pass stops being documented"
  "README.de.md|s#Aufbewahrungstage \(seit dem 2026-09-17 täglich angewendet[^)]*\)#Aufbewahrungstage (gespeichert, nicht angewendet)#|the German README calls the shipped pass unapplied again, in the language the interface speaks"
  "README.es.md|s#días de conservación \(aplicados cada noche desde el 2026-09-17[^)]*\)#días de conservación (se guardan, no se aplican)#|the Spanish README calls the shipped pass unapplied again"
  "docs/DSGVO-COMPLIANCE.md|s#\* \*\*Die Aufbewahrungsfrist wird seit dem 2026-09-17 täglich angewendet\.\*\*#* Die Einstellung „Aufbewahrungsfrist“ (Standard 90 Tage) wird gespeichert und nicht angewendet.#|the DATA-PROTECTION document calls the storage-limitation control inert again — the surface the first version of this check did not cover"
  "DEVELOPERS.md|s#- Whether 90 days is the right window is open; that it is \*applied\* is no longer drift \(§14\)\.#- Nothing applies \`wm_sentinel_retention_days\`; how long the log is kept is open.#|the developer manual lists the shipped pass as known drift again"
  "DEVELOPERS.md|s#settings screen and \`WM_Sentinel_Retention::configured_days\(\)\`; applied daily since 2026-09-17 — it neutralises identifying fields, it deletes nothing \(§14\)#settings screen only; nothing deletes by it#|the option table says nothing reads the retention window again"
  "docs/translations/README.de.md|s#6\. Set \*\*Aufbewahrungsfrist\*\*[^\n]*#6. Decide how long the log is kept; the plugin deletes nothing on its own.#|a translated README tells the operator the plugin deletes nothing on its own again"
  "includes/class-sentinel-sensors.php|s#add_action\( 'wm_activity_sentinel_log_event', \[ \\\$this, 'on_hub_audit_event' \], 10, 1 \);##|hub audit bridge hook unsubscribed"
  "includes/class-sentinel-sensors.php|s#'reason'        => \\\$reason,#'reason'        => \\\$reason, 'ip' => \\\$raw_ip,#|security incident sensor leaks raw client IP into context JSON"
  "uninstall.php|s#\\\$delete_all = 1 === \(int\) get_option\( 'wm_sentinel_delete_data_on_uninstall', 0 \);#\\\$delete_all = true;#|uninstall drops audit tables without operator opt-in"
  "uninstall.php|s#if \( \\\$delete_all \) \{\n\t\t\\\$logs_table#if ( false ) {\n\t\t\\\$logs_table#|opt-in uninstall fails to drop audit tables"
  "includes/class-sentinel-core.php|s#do_action\( 'wm_sentinel_ready', \\\$this \);##|wm_sentinel_ready action no longer fires after boot"
  "includes/class-sentinel-core.php|s#\\\$filtered = apply_filters\( 'wm_sentinel_public_metrics', \\\$metrics \);#\\\$filtered = \\\$metrics;#|wm_sentinel_public_metrics filter bypassed"
  "includes/class-sentinel-core.php|s#register_block_bindings_source\(#if ( false ) register_block_bindings_source(#|WP 6.5+ Block Bindings source wm-sentinel/metrics skipped"
  "includes/class-sentinel-core.php|s#if \( 'wm_sentinel_metric' !== \\\$tag \) \{#if ( true ) {#|GenerateBlocks Dynamic Tag output filter ignores wm_sentinel_metric"
  "includes/class-sentinel-admin.php|s#name=\"wm_sentinel_delete_data_on_uninstall\"#name=\"wm_sentinel_unused_toggle\"#|settings screen loses wm_sentinel_delete_data_on_uninstall checkbox"
  "readme.txt|s#^Stable tag: [^\n]*#Stable tag: 0.0.1#m|readme.txt Stable tag drifts from the plugin version"
  "includes/class-sentinel-admin.php|s#<pre id=\"wm-sentinel-context-json\" tabindex=\"0\"></pre>#<pre id=\"wm-sentinel-context-json\"></pre>#|context inspector <pre> loses tabindex=0"
  "assets/js/sentinel-admin.js|s#e\.key === 'Tab'#false#|focus-trap stops intercepting Tab/Shift+Tab"
  "assets/js/sentinel-admin.js|s#el\.inert = true;#el.inert = false;#|modal stops marking sibling wrappers inert"
  "assets/css/sentinel-admin.css|s#min-height: 44px;#min-height: 24px;#|high-specificity .wp-core-ui touch target drops below 44px"
  "wm-activity-sentinel.php|s#add_action\( 'wp_initialize_site', \[ 'WM_Sentinel_Core', 'on_initialize_site' \], 20, 1 \);##|multisite wp_initialize_site hook unsubscribed"
  "includes/class-sentinel-core.php|s#! is_plugin_active_for_network\( \\\$basename \)#false#|wp_initialize_site provisions non-network-activated blogs"
  "includes/class-sentinel-admin.php|s#update_option\( 'wm_sentinel_retention_days', absint\( \\\$_POST\['wm_sentinel_retention_days'\] \?\? 90 \), false \);#update_option( 'wm_sentinel_retention_days', absint( \\\$_POST['wm_sentinel_retention_days'] ?? 90 ) );#|retention_days saved autoloaded again"
  "includes/class-sentinel-admin.php|s#'Aktivitäts-Stream öffnen'#'Live-Stream öffnen'#|the widget offers a live stream again (the page does not refresh)"
  "includes/class-sentinel-core.php|s#'Geprüfte Einträge'#'Geprüfte Blöcke'#|the GenerateBlocks tag counts blocks again"
  "includes/class-sentinel-integrity.php|s#Das Ende der Kette stimmt nicht mit dem verzeichneten Kettenstand#Der zuletzt verzeichnete Master-Block stimmt nicht mit dem Ende der Kette#|the integrity reason names a master block again"
  "includes/class-sentinel-exporter.php|s#'Eintrags-Hash'#'Block-Hash'#|the privacy export labels the entry hash a block hash again"
  "includes/class-sentinel-admin.php|s#esc_html_e\( 'Einträge', 'wm-activity-sentinel' \); \?></span>#esc_html_e( 'Gesamt Logs', 'wm-activity-sentinel' ); ?></span>#|the widget counts «Gesamt Logs» again"
  "includes/class-sentinel-admin.php|s#esc_html_e\( 'Protokollierungsstufe', 'wm-activity-sentinel' \)#esc_html_e( 'Master-Protokollierungs-Intensität', 'wm-activity-sentinel' )#|the logging level is the «Master-Protokollierungs-Intensität» again"
  "includes/class-sentinel-admin.php|s#'Nur Warnungen \(Warning, Critical\)'#'Erhöhte Sicherheit (Nur Warning & Critical) — Fokus auf Vorfälle'#|the level that writes less is called «Erhöhte Sicherheit» again"
  "includes/class-sentinel-alerts.php|s#WM Activity Sentinel — Aktivitätsprotokoll#WM Suite Activity Sentinel — Forensisches Schutzsystem#|the alert mail signs off as a forensic protection system again"
  "includes/class-sentinel-admin.php|s#'Protokollierungsstufe, überwachte Bereiche,#'Konfigurieren Sie die Sensitivität, überwachte Bereiche,#|the settings header calls the level a sensitivity again"
  "includes/class-sentinel-admin.php|s#'Erhält die Alarm-Mails; ab welchem Schweregrad, legt das nächste Feld fest.'#'E-Mail-Adresse für sofortige Sicherheitsbenachrichtigungen bei kritischen Ereignissen.'#|the alert address promises mail on critical events only again"
)

fresh_copy() {
  rm -rf "$WORK/repo" && mkdir -p "$WORK/repo"
  # tar, not rsync: rsync is absent from ordinary base images (measured: ubuntu:24.04 ships tar and
  # no rsync) and the fleet's CI runs in a container. The error is no longer sent to /dev/null,
  # because the one sentence that says what happened was the one being discarded.
  local expected copied
  expected=$( (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard) | tr -dc '\0' | wc -c | tr -d ' ' )
  if ! (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | tar -c -f - --null -T -) \
       | (cd "$WORK/repo" && tar -x -f -); then
    echo "COPY FAILED  could not copy the working tree into $WORK/repo (is tar present?)" >&2
    return 1
  fi
  # The exit status of a pipeline is not evidence that anything was copied: the extracting tar exits
  # 0 when it receives nothing. Counting is the check that holds whatever the repository's layout is
  # — an earlier version of this guard required tests/mutations.php and refused the one repository
  # that keeps its mutations inside this script. Symlinks count: git lists them and tar copies them.
  copied=$(find "$WORK/repo" \( -type f -o -type l \) | wc -l | tr -d ' ')
  if [ "$copied" -lt "$expected" ]; then
    echo "COPY SHORT   copied $copied of $expected file(s) into $WORK/repo" >&2
    return 1
  fi
}

fresh_copy || exit 1
# Exit 0 alone is not green: a file that leaves PHP mode early prints its source and exits 0 without
# running one assertion (measured 2026-09-15). The control must also print the results line.
control_out=$(cd "$WORK/repo" && php tests/test-suite.php 2>&1)
control_exit=$?
if [ "$control_exit" -ne 0 ] || ! grep -Eq '^  TEST SUITE RESULTS: [1-9][0-9]* PASSED \| 0 FAILED$' <<<"$control_out"; then
  echo "CONTROL FAILED  the unmutated copy is not green (exit $control_exit, no results line with 0 failures); mutations are meaningless"
  exit 1
fi
echo "CONTROL      unmutated copy green ($(printf '%s\n' "$control_out" | grep -Eo '[0-9]+ PASSED'))"

failed=0
for entry in "${MUTATIONS[@]}"; do
  file=${entry%%|*}
  rest=${entry#*|}
  subst=${rest%|*}
  desc=${rest##*|}

  fresh_copy || { failed=1; continue; }
  before=$(md5 -q "$WORK/repo/$file" 2>/dev/null || md5sum "$WORK/repo/$file" | cut -d' ' -f1)
  perl -0pi -e "$subst" "$WORK/repo/$file"
  after=$(md5 -q "$WORK/repo/$file" 2>/dev/null || md5sum "$WORK/repo/$file" | cut -d' ' -f1)

  if [ "$before" = "$after" ]; then
    echo "NOT APPLIED  $desc ($file)"
    failed=1
    continue
  fi

  # Red means the suite ran to its results line and counted a failure; a crash or a file that stopped
  # parsing proves nothing about the assertion that should catch the mutation.
  out=$(cd "$WORK/repo" && php tests/test-suite.php 2>&1)
  if grep -q '\[FAIL\] uncaught ParseError' <<<"$out"; then
    echo "NO RESULT    $desc ($file): the mutation made a file unparsable"
    failed=1
  elif grep -Eq '^  TEST SUITE RESULTS: [0-9]+ PASSED \| [1-9][0-9]* FAILED$' <<<"$out"; then
    echo "RED          $desc"
  elif grep -Eq '^  TEST SUITE RESULTS: [0-9]+ PASSED \| 0 FAILED$' <<<"$out"; then
    echo "SURVIVED     $desc ($file)"
    failed=1
  else
    echo "NO RESULT    $desc ($file): the suite did not reach its results line"
    failed=1
  fi
done

exit "$failed"

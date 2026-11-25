# Changelog — WM Activity Sentinel

All notable changes to **WM Activity Sentinel** are documented in this file in adherence to [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Fixed
- Switched GenerateBlocks 2.x Dynamic Tag filtering from `generateblocks_dynamic_tag_output` to `generateblocks_dynamic_tag_replacement` with `mixed` safety.
- Added `WM_Sentinel_Database::maybe_upgrade()` and `WM_Sentinel_FIM::sync_schedule()` to `WM_Sentinel_Core::init()` (`plugins_loaded`) so already-active installs upgrade tables and keep file integrity scans scheduled (`wm_sentinel_db_version` stored with `autoload = false`, removed on opt-in uninstall).

## [1.4.3] - 2026-11-21

### Fixed

- On the plugin's admin screens, which carry WordPress's help tab, the header was shorter than the page by the width of the «Hilfe» toggle (measured 1155 px of 1238 px): WordPress floats the toggle above the page and the header may not overlap it. The bundled family stylesheet, now identical to WM Suite Hub 1.2.5, starts the header below the toggle, so it spans the page.
- The settings described the logging level with names it does not live up to. «Master-Protokollierungs-Intensität» is the «Protokollierungsstufe»; its section is «Protokollierungsstufe & Klick-Erfassung» instead of «… & Forensischer Detaillierungsgrad». The level that writes fewer entries (`strict`) was called «Erhöhte Sicherheit» and reads «Nur Warnungen»; `standard` and `critical_only` read «Standard» and «Nur kritische Ereignisse». The overview said «Standard» records «alle Daten- und Konfigurationsänderungen» — the plugin logs no post, page or media change — and now says what each level leaves out; the level `critical_only`, missing from it, is listed. The help tab «Intensitätsstufen» is «Protokollierungsstufen» and no longer calls `critical_only` a log of «schwerste Sicherheitsvorfälle». The page header no longer calls the level «Sensitivität», and the alert address no longer promises mail «bei kritischen Ereignissen» only: the threshold below it decides.
- The alert mail opened with «ACHTUNG: Ein sicherheitskritischer Vorfall» for every warning entry and signed off as a «Forensisches Schutzsystem»; it names the severity of the entry it recorded and signs off as an activity log.
- README (EN, DE, ES) and `docs/SETTINGS.md`: genesis, erasure and retention records bypass the level; the integrity monitor's report bypasses only the switches, so every level but `all` drops the `info` entry of a clean scan (the English README said integrity reports bypassed the level too).

## [1.4.2] - 2026-11-21

### Fixed

- The stream page was titled «Echtzeit-Stream» and the dashboard widget offered «Live-Stream öffnen», although the page is rendered once and nothing refreshes it. Both say «Aktivitäts-Stream».
- The dashboard widget and the GenerateBlocks tag counted «Blöcke», the integrity page checked «von Block #1 bis zum verzeichneten Master-Block» and showed a «Master-Block-Hash», and the WordPress personal data export labelled each entry's hash «Block-Hash». The log holds entries, and the recorded tip is the chain state (hash and count) kept outside the log table; the texts say so. The `verified_blocks` key of `wm_sentinel_public_metrics` and of the Block Bindings source stays, because it is an API.
- When the recorded chain state did not match the end of the chain, the reason said the newest entries had been removed. The check also fails when the count agrees and only the hash differs, so the reason now says removed or replaced.
- The widget line «HMAC-SHA256 Hash-Chaining aktiv» is a sentence saying what the chain does: each entry is bound to the previous one with HMAC-SHA256, and its counter reads «Einträge» instead of «Gesamt Logs», as the stream page does.
- The screenshot captions promised a «real-time» stream with a «Merkle» audit chain, a File Integrity Monitoring status on screenshots 2 and 4, and retention, SIEM and uninstall controls on screenshot 3; the screenshots show none of these. The captions describe what each screenshot shows, and all four were taken again. The measured feature list was marked v1.3.4.
- `README.md` called the 28 catalogs «complete human-grade translations» (the German and Spanish READMEs: complete and free of double encoding). They are compiled with no empty active entry; they were produced with AI coding agents, and no review by native speakers is on record. Each catalog also had the plugin URI header empty; it is filled.
- An empty «Unreleased» heading sat between 1.3.5 and 1.3.4 here and between 1.3.3 and 1.3.2 in `readme.txt`; both are gone.

## [1.4.1] - 2026-11-21

### Fixed

- Seven catalogs (fi, fi_FI, ga, hr_HR, it_IT, pl_PL, sl_SI) still named the agency that 1.3.5 removed from the catalogs, in the sentence on the file integrity monitor's baseline. They name the WM Suite plugins now, as the German source string does.

## [1.4.0] - 2026-11-20

### Changed

- The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
- The `.wordpress-org/` banners show the current version.

### Fixed

- `wp sentinel demodaten --reset --yes` stopped with "unknown --yes parameter": the flag was missing from the command synopsis, so WP-CLI refused it before the confirmation could read it, and a script could not reset the log without a prompt. The synopsis now lists `[--yes]`.

### Removed

- The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

## [1.3.5] - 2026-11-19

- Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.

- Added root `SUPPORT.md`, `.github/ISSUE_TEMPLATE/{config.yml,bug_report.yml}`, and explicit zero-support / "AS IS" liability notices across `README.md`, `README.de.md`, `README.es.md`, `readme.txt`, `SECURITY.md`, and `llms.txt`.
- Migrated plugin author/catalog/repository metadata to personal open-source distribution (`Arnold Wender`, `https://arnoldwender.com/wordpress-plugins/wm-activity-sentinel/`, `https://github.com/arnoldwender/wm-wp-activity-sentinel`), normalized FSF GPL-2.0 `LICENSE` metadata where applicable, and updated `.wordpress-org/` banner branding to `ARNOLD WENDER · OPEN SOURCE`.

## [1.3.4] - 2026-11-19

### Changed

- Archive superseded legacy banners and mockups under `versionen/visuals-pre-2026-11-19/` and add the live `.wordpress-org/` visual kit (`banner-1544x500.png`, `banner-772x250.png`, `icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `screenshot-1..4.png`).
- Add `== Screenshots ==` captions in `readme.txt`, live screenshot tables and official plugin page links in `README.md`, `README.de.md`, `README.es.md`, and `llms.txt`, and align release metadata at `v1.3.4`.

## [1.3.3] - 2026-11-19

### Changed

- Point `Plugin URI` to `https://arnoldwender.com/wordpress-plugins/wm-activity-sentinel/` and align release metadata at `v1.3.3`.

---

## [1.3.2] - 2026-11-18

### Fixed — BFSG 2025 / WCAG 2.2 AA Focus-Trap & 44px Touch Targets, Multisite `wp_initialize_site`, and Option Autoload (`R2-B5`, `R2-B8`)

- **Context inspector keyboard Focus-Trap & modal isolation (`R2-B5`).** Added `tabindex="0"` to `<pre id="wm-sentinel-context-json" tabindex="0"></pre>`, moved `#wm-sentinel-context-modal` to sit as a sibling of `.wm-sentinel-admin-wrap` (with `.wm-admin` on `.wm-sentinel-modal-card`), and implemented a complete keyboard Focus-Trap in `assets/js/sentinel-admin.js` intercepting `Tab` / `Shift+Tab`, guarding `focusin`, marking sibling wrappers (`.wm-sentinel-admin-wrap`, `#adminmenuwrap`, `#wpadminbar`, `#wpfooter`) with `aria-hidden="true"` and `inert` (restoring prior state before returning focus to `lastFocus`).
- **High-specificity `.wp-core-ui` 44×44 px touch-target defense (`R2-B5`).** Added `.wp-core-ui .wm-admin .button.button-small`, `.wp-core-ui .wm-sentinel-view-context`, `.wp-core-ui .wm-sentinel-widget-actions .button.button-small`, and `.wm-sentinel-modal-close` (`min-height: 44px; min-width: 44px`) in `assets/css/sentinel-admin.css` so WordPress core's `.wp-core-ui .button.button-small` cannot shrink context and dashboard widget buttons below 44×44 px.
- **Multisite `wp_initialize_site` provisioning & `$autoload = false` (`R2-B8`).** Hooked `wp_initialize_site` (priority 20) via `WM_Sentinel_Core::on_initialize_site()` and network-wide activation via `WM_Sentinel_Core::activate()`, calling `switch_to_blog()` -> `activate_site()` (`create_tables()`, `ensure_genesis_block()`, default options, FIM/retention schedules) -> `restore_current_blog()` inside `try`/`finally`. Updated `WM_Sentinel_Admin::handle_form_submissions()` and `WM_Sentinel_Database::should_autoload_option()` so internal options not needed on frontend loads use `$autoload = false` while keeping `includes/class-sentinel-seeder.php` factory reset compatible with `tests/run-mutations.sh`.

---

## [1.3.1] - 2026-11-18

### Added — extensibility, Block Bindings, SBOM and 28-locale translations (2026-11-18)

- **Developer hooks, WP 6.5+ Block Bindings and GenerateBlocks 2.x Dynamic Tags.** `WM_Sentinel_Core` now fires `do_action( 'wm_sentinel_ready', $this )` after boot, exposes `apply_filters( 'wm_sentinel_public_metrics', $metrics )`, and registers the WordPress 6.5+ Block Bindings source `wm-sentinel/metrics` alongside the GenerateBlocks 2.x Dynamic Tag `wm_sentinel_metric` (`generateblocks_dynamic_tag_output`) for non-sensitive audit status and counters (`chain_status`, `verified_blocks`, `total_events`, `version`).
- **Query parameterization, attribute escaping and strict types.** Parameterized all remaining `$wpdb` table/counter queries in `WM_Sentinel_Database`, `WM_Sentinel_Integrity` and `WM_Sentinel_Seeder` with `$wpdb->prepare()` and `%i` identifier placeholders, escaped KPI modifier class attributes in `WM_Sentinel_Admin`, added `declare(strict_types=1);` to `WM_Sentinel_CLI_Command`, and guarded fallback `error_log()` behind a single-line `WP_DEBUG` check.
- **Distribution & translations.** Added CycloneDX 1.6 `bom.json`, `.wordpress-org/blueprints/blueprint.json`, `.license-allowlist.yml`, refreshed `languages/wm-activity-sentinel.pot` via `wp i18n make-pot`, and compiled complete human-grade `.po`/`.mo` translations across all 28 locales.

### Added — inter-plugin audit bridges and opt-in uninstall wipe (2026-11-16)

- **Subscribed to `wm_activity_sentinel_log_event` and `wm_sentinel_security_incident` (`W2` in fleet architecture).** `WM_Sentinel_Sensors::init_sensors()` now bridges chained events emitted by `WM\SuiteHub\Telemetry\Audit_Chainer` (`hub_audit_bridged`) and security incidents emitted by `WM\KanzleiSanitizer\Security\RequestShield` or third-party modules (`security_incident_reported`). Any client IP included in a security incident payload is extracted strictly for daily-salted `ip_hmac` computation and never written into `context`. Added `wm_sentinel_monitored_options` filter for `updated_option` surveillance.
- **Uninstall preserves the audit trail by default (`D-UNINSTALL`).** Added `wm_sentinel_delete_data_on_uninstall` (default `0`, configurable in *Einstellungen › 4. Aufbewahrung & Dashboard*). When `0`, `uninstall.php` deletes plugin settings, legacy options, transients and scheduled hooks while preserving `wp_wm_activity_logs`, `wp_wm_activity_alerts`, `wm_sentinel_chain_tip`, `wm_sentinel_fim_baseline` and `wm_sentinel_fim_last_scan`. When `1`, `uninstall.php` deletes those audit-state options and drops both tables.

### Fixed — the SBOM declared a proprietary licence (2026-11-05)

- **`get_sbom_data()` declared `Proprietary`, with an `id` and a `name` at once.** The header, `readme.txt` and `LICENSE` have said GPLv2 or later since `385cf69` (2026-09-16, "GPLv2 or later everywhere"); the adapter kept the licence block of the 2026-08-27 scaffold, and it is the one licence statement a machine reads. `Proprietary` is not an SPDX identifier, and CycloneDX 1.6 accepts an `id` or a `name`, not both (`license.oneOf`). It now declares `GPL-2.0-or-later`, the id alone. Test group 10 pins it; two mutations put back the old block and a name beside the id, and both turn the suite red.
- **Four docs still described the header as it was before `385cf69`.** *Known issues* in `README.md`, `README.de.md` and `README.es.md` said the header and `readme.txt` were `Proprietary` and that Plugin Check reported three licence errors, and §15 of `DEVELOPERS.md` listed the same as drift. Those lines are gone, and §11 states the SBOM licence as it is now. Plugin Check was not re-run for this change. `CLAUDE.md` no longer counts the licence lines (A3) among the decisions waiting for Arnold: a WM Suite WordPress plugin is GPLv2 or later. Its test counts are measured again (140 assertions, 94 mutations; it said 120 and 76), and so is the mutation count in §16 of `DEVELOPERS.md` (it said 89).

**Gate:** `php tests/test-suite.php` → **140 PASSED | 0 FAILED**. `bash tests/run-mutations.sh` → control green, **94/94 RED**.

### Translation catalog (2026-09-20)

- **159 of the 285 translatable strings had no entry in the `.pot`.** A string the code wraps in `__()` but that the catalog does not carry cannot be translated by anyone, to any language, ever, and nothing reports it. The template is now generated from the code with `wp i18n make-pot`, every `.po` merged with `msgmerge --no-fuzzy-matching` and every `.mo` rebuilt.
- **`tests/test-i18n.php` could not have caught it.** It checks encoding and that each locale has a `.po` and a valid `.mo` — all of which stays true of a catalog that has drifted away from the code. `tests/test-pot-freshness.php` asks the question it did not: does the catalog describe the strings this code translates?
- **354 real translations survived, 220 became obsolete `#~` entries**, verified against the strings the code extracts **before** the merge rather than after it: `ar` 221 → 124, `bg_BG` 107 → 69, `cs_CZ` 105 → 67, `da_DK` 104 → 66, `el` 24 → 18. Nothing was deleted.
- The Known issues note about ~198 double-encoded strings per catalog was obsolete and is corrected: all 29 catalogs measure **0 double-encoded** since the repair pass of 2026-09-17.

### Added — the retention setting is applied (2026-09-17), and the docs said otherwise until 2026-09-20

- **`includes/class-sentinel-retention.php`.** Until 2026-09-17 `wm_sentinel_retention_days` was written by activation, by the seeder and by the settings form, and read in exactly one place: to render itself back into that form. A field labelled *Aufbewahrungsfrist* that removes nothing reads like a DSGVO Art. 5(1)(e) storage-limitation control and is not one. A daily event (`wm_sentinel_apply_retention`) now neutralises `user_name`, `user_email` and `ip_hmac` of entries past the window, at most 500 rows per pass, and `wp sentinel retention [--dry-run]` runs or previews it.
- **It pseudonymises instead of deleting, on purpose.** The log is a hash chain: deleting old rows breaks the linkage check at the first survivor and the tip count at the end, so the audit would report the site's own retention policy as tampering — the worst possible outcome for an evidence log. Art. 5(1)(e) limits how long *personal data* is kept, not how long the fact that an event happened is kept, so the row and its chain position stay. `context` is left alone: it is the evidence, and Art. 17 rewrites it per person, on request.
- **The record goes first.** Each pass writes a chained `retention_pseudonymized` entry carrying a keyed fingerprint of every row it will change, and changes nothing at all if that record cannot be written — a pseudonymised row whose fingerprint was never recorded would read as tampering for ever. `verify_chain()` honours the record the same way it honours the Art. 17 erasure record.
- **`sync_schedule()` runs on `plugins_loaded`, not only on activation.** An already-active install never runs its activation hook again, so the feature arrived dead on every existing site until that was measured (2026-09-17).
- **The docs lagged the code by three days.** `README.md` still listed the setting as "stored, not applied" in *What the plugin does* and in *Known issues*, omitted `wp sentinel retention` from the WP-CLI list, and the **24 translated READMEs — which are generated from `README.md`** — still told the operator to "decide how long the log is kept; the plugin deletes nothing on its own". `readme.txt` carried the superseded line as well. All of it is corrected here and the 24 files regenerated with `wp-plugin-fleet-translations-sync.py`; `tests/test-suite.php` now fails if any of those claims comes back, with a mutation for each direction. The admin screen was never wrong — test group 12 pinned its text on the day the pass shipped; only the files no test read had drifted.

### Plugin Check errors in the distribution view (2026-09-17)

- **`fwrite()`/`fclose()` in `WM_Sentinel_Forwarder::send()` are annotated, not replaced.** The fleet measurement (`wp-plugin-fleet-measure-all.sh`) reported both as `WordPress.WP.AlternativeFunctions.file_system_operations_*` errors. The handle is a UDP/TCP stream to a syslog collector, not a file: `WP_Filesystem` abstracts host files over direct, FTP or SSH and has no network-stream API, so there is nothing to replace them with. Each call carries a `phpcs:ignore` naming that reason. Plugin Check on the distribution view: 2 errors → 0.

### Added — file integrity monitor (2026-09-16)

- **`includes/class-sentinel-fim.php`.** The second vector the spec names for this plugin: a continuous comparison of the WordPress tree against the official hash catalogue. `fetch_catalogue()` reads `https://api.wordpress.org/core/checksums/1.0/?version=…&locale=…` (twelve-hour transient), `scan_core()` compares `md5_file()` against it, and `scan_unexpected()` walks `wp-admin` and `wp-includes` for executable files the catalogue does not list — a dropped backdoor adds a file rather than editing one, and a walk over the catalogue alone never looks at it.
- **A version without a published catalogue is "not checked", not "clean".** api.wordpress.org answers an unknown version with **HTTP 200 and `"checksums": false`** (measured 2026-09-16 against `?version=99.9.9`). Taken for an empty catalogue, the scan would walk zero files and report zero findings — a clean bill of health for a tree nobody checked. It is an error, and `severity_for()` makes such a run a `warning`.
- **`wp-content` is skipped and hardening is not a finding.** The catalogue lists the bundled themes and Akismet, which a site may legitimately update or delete; `wp core verify-checksums` skips them for the same reason. A missing `readme.html`, `license.txt` or `wp-config-sample.php` goes to `hardening_removals`, because a monitor that cries wolf is one the operator learns to ignore.
- **A poisoned catalogue cannot walk out of the installation.** `normalise_relative_path()` drops entries that are absolute, contain `..`, carry a drive letter or a NUL byte, and any hash that is not 32 hex characters.
- **The WM Suite baseline is sealed into the chain.** There is no official catalogue for the plugins, so `capture_suite_baseline()` hashes their PHP, JS and CSS with SHA-256 and `seal_suite_baseline()` writes a chained `fim_baseline_sealed` entry carrying the manifest's fingerprint. The baseline alone would be worthless — whoever edits a plugin file can rewrite an option — so `baseline_seal_status()` reports `broken` when the stored baseline no longer matches what the chain recorded. Same reasoning as the chain tip.
- **Findings use object type `integrity`, deliberately not `file`.** `wm_sentinel_log_plugins` filters `file`, so switching off plugin logging would have silenced a modified `wp-includes`.
- Daily cron `wm_sentinel_fim_scan` following the `wm_sentinel_fim_enabled` switch, an admin screen *Dateiintegrität* showing the stored report (a full scan hashes ~3,000 files and belongs in cron, not in a page render), `wp sentinel fim [--baseline]`, and the report written into the chain — and therefore forwarded to the SIEM.

### Added — the SIEM forwarder reaches the operator (2026-09-16)

- **The seven forwarding settings had no interface.** They shipped on 2026-09-16 with the forwarder reading them, but no screen wrote them: the feature was reachable only through `wp option update`, which is not a feature the operator has. Section 5 of *Einstellungen* now configures the switch, format, transport, host and port or URL and the minimum severity, with the format and transport whitelisted against the forwarder's own constants.
- `README.md` and `DEVELOPERS.md` document the forwarder and the monitor; `DEVELOPERS.md` sections 12 and 13 carry the wire formats, the escaping rules and the scan.

### Fixed — two checks that measured nothing (2026-09-16)

- **The suite's `$wpdb` mock ignored `WHERE`, `ORDER BY` and `LIMIT`.** `get_results()` returned every row of the table whatever was asked, so a test for "the newest entry of type X" read the genesis block. The seal check would have passed without a seal. The mock now filters, orders and limits, and a mutation that puts the old behaviour back turns the suite red.
- `uninstall.php` did not delete `wm_sentinel_fim_baseline`, `wm_sentinel_fim_last_scan` or the cached catalogues, and did not clear the integrity schedule.

### Fixed — three false findings the deploy caught (2026-09-16)

- **The catalogue is keyed by the installed package, not by the display language.** The first run against a real tree (a local WordPress install, WordPress 7.1) reported three findings — `wp-includes/version.php`, `wp-config-sample.php` and a missing `liesmich.html` — on a tree where nothing was wrong. The site's language is German, the installed package is en_US, and all three md5s matched the **en_US** catalogue exactly. `installed_package_locale()` now reads `$wp_local_package`, which WordPress writes into `version.php` for localized builds only, and falls back to `WP_LOCAL_PACKAGE` and then `en_US`. Three false positives on a clean install is how a monitor gets switched off in its first week.
- **A localized package renames the readme.** The de_DE 7.1 catalogue carries `readme.html` *and* `liesmich.html`. `is_hardening_removal()` accepts any catalogue entry at the root ending in `.html` rather than a list of names that would go stale per locale; an `.html` missing from `wp-admin` or `wp-includes` stays a finding.
- The mutation for the hardening list had stopped measuring anything once the `.html` rule covered `readme.html`, so it now targets `license.txt`, which only the list covers.

### Fixed — the baseline was 47,085 files and 7.4 MB (2026-09-16)

- **`node_modules`, `.git` and the other development directories are pruned.** The first real capture on a local WordPress install stored **47,085 files and 7.4 MB in one `wp_options` row**, of which **46,443** were under `node_modules`, `vendor` or `.git` — a row read and rewritten by every scan. `SUITE_EXCLUDED_DIRS` prunes the development artifacts during the walk (pruning, not skipping: otherwise the walk still descends into every `node_modules` before discarding it). **`vendor/` deliberately stays**: Composer dependencies run through an autoloader, and a backdoor placed there is exactly what the baseline is for.
- **An oversized baseline is refused, not stored.** Above `MAX_BASELINE_FILES` (20,000) `seal_suite_baseline()` writes nothing, seals nothing and says why, in the CLI and on the screen. A manifest that quietly grows into megabytes is noticed when the cron times out, which is too late.

**Gate:** `php tests/test-suite.php` → **120 PASSED | 0 FAILED**. `bash tests/run-mutations.sh` → control green, **76/76 RED**, 0 SURVIVED, 0 NOT APPLIED.

### Family sheets (2026-09-16)

- Re-synced from the hub: the badge variants carry the state tokens on body surfaces, where the command-banner inks measured 1.49:1 to 2.21:1 against the composed background (hub PR #19), and the canonical sheet dropped five classes that belonged to a single plugin (hub PR #21). `tests/test-ui-family.php` re-pins both md5s.

### Fixed (2026-09-15, second pass)

- **The demo import sent real alerts.** Its invented "credential stuffing" and "privilege escalation" entries are critical, so every import sent alert mails (four with the default settings) and webhooks. `WM_Sentinel_Logger::mute_alerts()` suppresses them during the import, and the import counts what `log()` wrote instead of reporting 15 whatever the logging level let through.
- **"Das Protokoll wurde zurückgesetzt" when nothing was.** Deleting demo data from a log without any returned 0, and the CLI and the admin notice still said the log had been reset. Both say nothing changed.
- **A failed row lock broke the chain.** `SELECT … FOR UPDATE` that failed (lock wait timeout, lost connection) returned null like an empty table, so the entry was chained to `GENESIS` in the middle of a log. It rolls back and returns `false` now; a failed `COMMIT` returns `false` without alerts.
- **An `AUTH_KEY` left at the `wp-config-sample.php` phrase was reported as a proper key.** The chain was signed with a public phrase while the audit said the key came from `wp-config.php`. `chain_key_source()` returns `wp-config-placeholder`; the integrity screen and `wp sentinel audit` warn, the hub reports `DEGRADED`. The signing key is unchanged, so stored entries keep verifying.
- The handbook listed wrong severities (login success as notice, plugin deactivation and theme switch as notice, export page and editor views as critical); it now names each hook's severity. The exports switch no longer claims download-link clicks, which the UI-interactions switch decides. The frontend tracker is versioned by file time like the admin copy. Uninstall removes the telemetry rate-limit transients.
- **WordPress test files:** `test-sentinel-chain.php`, `test-sentinel-sensors.php` and `test-sentinel-tamper-evidence.php` exited 0 with failures; they exit 1 now. Those three and `test-sentinel-privacy-tools.php` write to the log (two truncate it) and refuse to run without `WM_SENTINEL_SCRATCH_INSTALL=1`: a docblock warning did not stop a run against a demo install in a sibling plugin the same day.
- Comments said "Until 1.1.0" for fixes the changelog dates to 1.3.0, and the exporter docblock called the JSON package "cryptographically signed"; corrected. The sensors test no longer prints "DSGVO-compliant IP HMAC".
- **A hash mismatch was always called an edit.** After moving a staging site to another server whose `wp-config.php` has another `AUTH_KEY`, the audit failed at entry #1 with "Daten wurden nachträglich in der Datenbank verändert". An HMAC cannot tell the two apart; the message names both causes.
- CI reads the suite output through a here-string as the mutation runner does.
- Tests: group 13 (70 assertions in total); 10 more mutations (40), and the runner reads the suite output through here-strings instead of `printf | grep -q`, which can miss a line on large output under `pipefail`.

### Documented (2026-09-15, second pass)

- README (EN/DE/ES), DEVELOPERS.md, `docs/DEVELOPER-GUIDE.md`, `docs/ARCHITECTURE.md`, `docs/SECURITY-AUDIT.md`, CLAUDE.md/AGENTS.md, llms.txt and `public/humans.txt`: the telemetry route knows nine event types and the script produces eight; the privacy exporter stops at 1,000 rows; theme installations are logged through `upgrader_process_complete`; the rollback covers the lock query, the insert and the commit, not the tip write; the 2026-08-27 audit fixed three findings, not "beide"; the telemetry limit has no lock; a move to a server with another `wp-config.php` breaks verification; no PSR-12 (tabs, no phpcs configuration); the family badge strings are English. The legacy marketing banner (carrying unverified audit-readiness claims) is no longer shown or packaged. Emoji removed from the headings below.

### Fixed (2026-09-15, privacy tools, CLI, sensors and telemetry)

- **WordPress's personal data tools ended in a fatal error.** The exporter and the eraser called `WM_Sentinel_Database::get_table_name()`, a method that does not exist, so *Tools › Export Personal Data* and *Erase Personal Data* failed for every request once the plugin was active (measured on a local WordPress install). Both now read the rows where the address is the actor or appears in the context. Test groups 5 and 6; `tests/test-sentinel-privacy-tools.php` 12/0 in WordPress, red with the exporter of `main`.
- **An erasure would have broken the chain while saying it did not.** Had the eraser run, it changed name, address and IP hash without anything the audit could accept: the next verification reported tampering at the first erased row, and the message said the chain signatures stayed intact. The address also stayed inside `context`. The eraser now appends a chained `privacy_erasure_applied` entry holding a keyed fingerprint of each row as it will look afterwards, then changes the rows, including the address inside `context`. `verify_chain()` accepts a changed row only when an erasure record written after it lists that row and the fingerprint still matches, so a later edit of an erased row stays detectable; the result carries a `pseudonymized` count. The erasure record bypasses the logging level and the switches. Test groups 4 and 6.
- **WP-CLI `demodaten --import` and `--reset` ended in a fatal error.** They called `WM_Sentinel_Seeder::seed()` and `::factory_reset()`, which do not exist. `wp sentinel audit` read `failed_log_id` and `error`, keys `verify_chain()` never returns, so a broken chain was reported without id or reason; it now names the row and the reason, warns when the key lives in the database and reports pseudonymised rows. `--reset` asks for confirmation. Test group 7.
- **The factory reset did not reset the settings.** It wrote the logging level `high`, which the logger does not know, and `wm_sentinel_modules`, which nothing reads, and left every real switch as it was; activation wrote three options nothing reads either. Activation and the reset now write the 15 settings the plugin reads, and the reset removes the legacy options. Test groups 7 and 9.
- **The authentication switch never filtered a login.** The switch listed event names no sensor emits; `user_login_success` and `user_login_failed` fell through to the users switch. The file editor events (object type `file`) were covered by no switch although the settings screen listed them under plugins and themes. Test group 4.
- **Two sensors listened to actions WordPress never fires.** `wp_edit_theme_plugin_file` became `wp_ajax_edit-theme-plugin-file` at priority 0 (event `plugin_theme_file_save_requested`, recorded before core processes the save) and `application_password_created` became `wp_create_application_password`, which logs the application name only; the callback takes two arguments so the plain password core passes third never reaches the log. `after_switch_theme` passes the previous theme, which was logged as the new one. Test group 8.
- **Any signed-in account could write forged critical entries.** `POST /wm-sentinel/v1/telemetry` accepted up to 25 entries per request with any event type, category and severity: a subscriber could post `user_role_changed` marked critical, which entered the chain and sent the alert mail. Visitors with a REST nonce for user 0 were let in as well. The route now takes signed-in users only, the nine event types, four categories and metadata keys the tracker produces, always severity `info`, values cut at 300 characters, at most 60 entries per user per minute; the response counts refused entries. Test group 8.
- **A false truncation alarm was possible.** The chain tip mirror was written after `COMMIT`, so two concurrent requests could store their tips in the wrong order and a request that died in between left the tip one entry behind; the next audit reported removed entries that were never removed. It is written before `COMMIT`, while the row lock is held. Test group 4.
- **An install without `NONCE_SALT` hashed IPs with a public literal.** The fallback `wm_sentinel_default_salt_2026` shipped in the source let anyone with a copy of the plugin test candidate addresses against the stored hashes; the fallback is WordPress's stored nonce salt. Test group 4.
- **Hub adapter:** `latest_entry_hash` read `$tip['tip']`, a key the tip never has, so the hub always showed no tip; health is `DEGRADED` when the key lives in the database; telemetry adds the chain error, pseudonymised rows and the key source; the SBOM lists features; `purge_cache` did nothing and is gone. Test group 10.
- **Admin screens:** Spanish strings inside the German interface replaced; "revisionssicher", "unveränderlich", GoBD and "vollständige Compliance" statements removed from the screens, the handbook and the plugin description; the retention field says it is stored and not applied *(superseded 2026-09-17: the pass runs, and the field says what it does — see the entry under [Unreleased])*; the handbook formula and sensor table show the real hash input and hooks; `th scope="col"` on the stream table; the minimum alert severity is whitelisted; the option "Alle Hinweise (Notice+)" is labelled "Notice und höher (Notice-Einträge selbst lösen keinen Alarm aus)", because the logger raises alerts for `warning` and `critical` only. Test group 12.
- `uninstall.php` keeps its variables inside a closure, deletes every setting, the tip and the legacy options, clears `wm_sentinel_retention_purge` and drops both tables. Deactivation clears the same hook. Test group 9.
- **Admin accessibility and markup:** 20 decorative dashicons were announced by screen readers; all carry `aria-hidden="true"`. 22 inline styles with pixel values (column widths, label spacing, a fixed 380 px minimum select width, now capped at the cell width) moved to classes with tokens in `assets/css/sentinel-admin.css`; the dialog toggle is the only inline style left. Two of the classes need the wrap in their selector, because core's form-table and list-table rules outrank a single class (measured in Chrome: labels fell back to inline-block and the context column lost its centring until then). Test group 12. The icon-only context button stays at core's `button-small` size, 24 × 38 px.
- CI tested PHP 8.3 only against a header requiring 8.2 and did not run the family suite or a mutation check; it now runs `tests/test-suite.php`, `tests/test-ui-family.php` and `tests/run-mutations.sh` (30 mutations after a green control run) on 8.2 and 8.3.
- **A green test run could be empty.** A test file that leaves PHP mode early (a closing PHP tag inside a `//` comment, measured in this pass) prints its own source and exits 0 without running an assertion; the mutation control and the CI step only read the exit code. Both now require the results line with 0 failures, a mutation counts as red only when the suite reaches that line with a failure, and an uncaught error still prints the line. Falsified by reintroducing the tag in a copy: the control fails. The CSV test also covers the carriage-return prefix now.
- Plugin Check on the dist view: warnings 104 → 95, the three licence errors unchanged (A3).

### Documentation (2026-09-15)

- `README.md`, `README.de.md`, `README.es.md`, the 24 translated READMEs, `DEVELOPERS.md`, `docs/ARCHITECTURE.md`, `docs/DEVELOPER-GUIDE.md`, `docs/SECURITY-AUDIT.md`, `docs/DSGVO-COMPLIANCE.md`, `docs/ACCESSIBILITY.md`, `CLAUDE.md`, `AGENTS.md`, `llms.txt`, `CONTRIBUTING.md`, `SECURITY.md`, `readme.txt` and `public/humans.txt` describe what the plugin ships. Until now they promised an unshipped frontend badge shortcode, WP-CLI `wp sentinel status|verify|export`, REST `/status` `/submit` `/export`, legacy table names, an unshipped tamper hook, PDF reports, "25+ forensic sensors", a daily purge cron, a 60-second alert throttle, HTML alert mails, `save_post` and `wp_logout` sensors, classes `WM_Sentinel_Privacy` and `prune_logs()`, and unverified conformity claims for GoBD, GeschGehG § 2, DSGVO Art. 32, the CRA and several judgments; none of those exist. The translated READMEs are generated from `README.md` by the fleet script `wp-plugin-fleet-translations-sync.py`.
- The header went from 1.0.0 straight to **1.3.0** in `6cbcf78` (2026-08-27). The entries **[1.3.0]** and **[1.2.0]** were written that day and lost on 2026-08-29, when `dd2edf3` replaced this file with a shorter one ending at 1.1.0; they are restored below from `6cbcf78`. Versions 1.1.0 and 1.2.0 were never in the plugin header. Lines marked *(not in the code)* describe what does not exist in this repository. `SECURITY.md` named 1.1.0 as the supported version and `docs/DEVELOPER-GUIDE.md` said 1.1.0.

### Fixed

- Plugin Check (dist view, 2026-09-14): 22 → 3 errors, the three licence codes that wait for a decision. The stream menu label concatenated a badge inside `__()`; six `sprintf`-translated strings had no translators comment and two integrity messages used unordered placeholders; `paginate_links()` output was echoed raw; the log list and the chain-tip count interpolated the table name into SQL (now `%i`); the JSON export echo and the `php://output` close carry their reason. `Tested up to: 7.1` in the header (runtime pass of today: activation, three WP-CLI suites).

### Design family (hackaton phase 3, 2026-09-14)

- The five admin screens and the dashboard widget render from the two WM Suite family sheets (`assets/css/wm-admin-tokens.css`, `assets/css/wm-admin-ui.css`, byte-identical copies of the hub's canonical files, pinned by `tests/test-ui-family.php`) plus a Sentinel layer (`assets/css/sentinel-admin.css`) that carries only what the family does not: KPI tiles with an icon column, the chain status banner, severity badges, the stream filter bar, the context inspector modal, the widget list, the danger zone and the handbook code sample. Every colour is a token, no focus suppression. Before this the sheet styled the header, tabs and guide only: the KPI tiles, cards, status banner, severity badges, filter bar, modal and widget had no rule at all and rendered unstyled (measured 2026-09-14: 0 matching selectors).
- Native `.wrap.wm-admin`, one shared header helper with the family badge (hub/standalone), family menu icon, same enqueue handles as every WM plugin versioned by file time. Under the hub the page is dispatched as `wm-suite_page_wm-activity-sentinel`; the enqueue matches that hook too. The two scripts are versioned by file time as well: the modal focus fix below was served from the browser cache under the unchanged `1.3.0` (measured: fetch fresh had the fix, the page did not).
- Dashicons instead of emoji in the tabs, jump links, guide pills, demo buttons and the help sidebar (those msgids changed; the catalog pass D10 picks them up). `aria-current` on the active tab, an accessible name on the icon-only context button and on the modal close, `aria-labelledby` on the dialog, focus moved into the modal on open and restored on close. The jump links to the filters and to the log table pointed at ids that did not exist; both anchors exist now.
- Inline styles with hex colours in the settings and handbook screens (demo card, danger zone, code sample) moved to tokenised classes.

---

## [1.3.0] — 2026-08-27

> Restored from `6cbcf78`, where it was written in German; `dd2edf3` removed it on 2026-08-29. This is the version in the plugin header.

### Fixed — zwei Befunde, die den Manipulationsnachweis aufhoben

- **Das Plugin lieferte einen Knopf, der seine eigene Manipulationserkennung löschte.** `WM_Sentinel_Database::rebuild_chain()` signierte beim Entfernen der Demodaten sämtliche verbleibenden Zeilen neu — erreichbar über die Einstellungsseite und über `wp sentinel demodaten --delete`. Gemessen: Zeile von Hand ändern → Prüfung rot → Demodaten löschen → Prüfung grün, Änderung unverändert vorhanden. Die Methode ist entfernt. Demodaten lassen sich nur noch löschen, solange keine echten Einträge vorliegen; dann wird das Protokoll vollständig zurückgesetzt, was in der Prüfung sichtbar ist.
- **Das Löschen der neuesten Einträge war unsichtbar.** `verify_chain()` meldete nach `DELETE` der zwei jüngsten Zeilen weiterhin `valid=true`. Das Kettenende wird jetzt in `wm_sentinel_chain_tip` außerhalb der Log-Tabelle verzeichnet und mitgeprüft; auch eine vollständig geleerte Tabelle gilt nun als Befund statt als unauffällig.
- **Kein ausgelieferter Ersatzschlüssel mehr.** War `AUTH_KEY` nicht definiert, benutzte `compute_entry_hash()` das Literal `wm_sentinel_auth_key_2026` aus dem Quelltext — auf solchen Installationen war der „geheime" Schlüssel öffentlich. Ersatz ist jetzt `wp_salt( 'auth' )`, und die Prüfung meldet über `key_source`, ob der Schlüssel aus der `wp-config.php` oder aus der Datenbank stammt. **Hinweis:** auf Installationen ohne `AUTH_KEY` verifizieren bestehende Ketten nach dem Update nicht mehr — das ist der sichtbare Preis dafür, dass der alte Schlüssel wertlos war.

### Changed — Aussagen an den Mechanismus angeglichen

- **`RFC 3161 Time-Stamping` aus der Plugin-Beschreibung entfernt.** Es war nie implementiert: `grep -r 3161` fand im gesamten Repository nur diese eine Zeile. *(Die ungenutzte Spalte `tsa_timestamp` in `wp_wm_activity_logs` stammt aus dieser Idee; nichts schreibt oder liest sie.)*
- Immutability wording replaced with „tamper-evident" in Beschreibung, `readme.txt`, README und Architekturdoku. Das Verfahren weist Manipulation nach, es verhindert sie nicht.
- „KETTE VOLLSTÄNDIG INTAKT" → „KETTE INTAKT" mit der geprüften Anzahl.
- `SECURITY-AUDIT.md` benennt jetzt auch, was das Verfahren **nicht** leistet: keine Unveränderbarkeit, kein Zeitnachweis, und der Spiegel des Kettenendes liegt in derselben Datenbank.

### Known gaps

- Die 25 EU-Sprachpakete enthalten die geänderten Zeichenketten noch nicht (*es sind 28 Kataloge; die EU hat 24 Amtssprachen*); betroffene Stellen fallen auf den deutschen Quelltext zurück. Bewusst nicht maschinell nachgezogen: es handelt sich um Compliance-Aussagen, die eine Übersetzung nicht verfälschen darf.

---

## [1.2.0] — 2026-08-27

> Restored from `6cbcf78`; never in the plugin header.

### Added

- **WP-CLI Command Suite (`wp sentinel`):**
  - `wp sentinel audit`: Complete cryptographic hash-chain mathematical verification from Genesis Block #1. *(reported a broken chain without id or reason until 2026-09-15)*
  - `wp sentinel list`: Outputs forensic activity log table with custom formats. *(not in the code: table only, with `--limit` and `--severity`)*
  - `wp sentinel demodaten`: Demo data management (`--import`, `--delete`, `--reset`). *(`--import` and `--reset` ended in a fatal error until 2026-09-15)*
- **European Union Internationalization (24 EU Locales):**
  - Full GNU gettext `.po`/`.mo` translation packages for all 24 EU official languages. *(the catalogs contain double-encoded strings; 574 of 9,094 entries translated across 28 catalogs, measured 2026-09-15)*
- **DSGVO Art. 17 Core Privacy Tools:**
  - `wm-activity-sentinel` Exporter and Eraser hooks with GoBD cryptographic pseudonymization. *(both ended in a fatal error until 2026-09-15; "GoBD" is a claim, not a feature)*
- **Demodaten Management & Factory Reset:**
  - One-click Admin Cockpit actions and WP-CLI commands for attack scenario generation and nuclear reset.

---

## [1.1.0]

> Never in the plugin header. Two texts exist for this number: the entry written on 2026-08-27 (restored from `6cbcf78`) and the one that replaced it on 2026-08-29 (`dd2edf3`). Both are kept.

### As written on 2026-08-27

#### Added

- **Configurable Multi-Tier Intensity & Telemetry Engine:**
  - 4 Master Logging Intensity levels: `all` (Paranoid Forensic), `standard`, `strict`, `critical_only`.
  - 3 Client-Side Telemetry Granularities: `all`, `actions_only`, `critical_only`.
  - 8 Modular Subsystem Toggles for granular domain activation (Auth, Users, Plugins, Content, REST, Screens, Clicks, Frontend Views). *(the authentication switch filtered no login until 2026-09-15)*
- **High-Concurrency InnoDB Row Locking:**
  - `WM_Sentinel_Database::get_last_entry_for_update()` using `SELECT ... FOR UPDATE`.
  - Atomic transaction wrapper in `WM_Sentinel_Logger::log()` to guarantee zero chain forks under concurrent bursts. *(no concurrency test exists; a failed lock query chained to GENESIS until 2026-09-15)*
- **Client Telemetry Beacon & Sensor:**
  - `sentinel-tracker.js` for asynchronous click and UI interaction tracking via REST beacon.
- **Comprehensive Developer Guide:**
  - Added `docs/DEVELOPER-GUIDE.md` with complete API reference and architectural blueprints. *(it described methods, hooks and a throttle that do not exist; rewritten 2026-09-15)*

#### Enhanced

- Expanded test suite in `tests/test-sentinel-sensors.php` to 11/11 automated tests.

### As written on 2026-08-29

#### Added

- **24 Official European Union Languages:** Full binary `.mo` and source `.po` translation packages in `languages/` (`bg_BG`, `cs_CZ`, `da_DK`, `de_DE`, `el_GR`, `en_GB`, `es_ES`, `et_EE`, `fi_FI`, `fr_FR`, `ga_IE`, `hr_HR`, `hu_HU`, `it_IT`, `lt_LT`, `lv_LV`, `mt_MT`, `nl_NL`, `pl_PL`, `pt_PT`, `ro_RO`, `sk_SK`, `sl_SI`, `sv_SE`). *(not full: the files are named `el`, `et`, `ga`, `lv` among 28 catalogs, double-encoded, 0 to 107 of 337 strings translated)*
- **Developer Documentation Suite:** Created `DEVELOPERS.md` with complete architecture diagrams, hook references, database schema, and REST API guides. *(not in the code: the hook, tables and routes it described)*
- **Decoupled REST API Controller:** Registered namespace `/wp-json/wm-sentinel/v1` with strict nonce and permission validation. *(the namespace holds one route, `POST /telemetry`)*
- **Zero-CDN Frontend Shortcode:** Described an unshipped frontend badge shortcode with inline SVG icons and CSS design tokens. *(not in the code)*
- **CycloneDX v1.6 SBOM Engine:** Dynamic SHA-256 entry file integrity hashing and spoke adapter integration for **WM Suite Hub**. *(one component with a hash; no CycloneDX document or version)*
- **Enterprise Showcase Assets:** High-fidelity 16:9 marketing banner and cockpit showcase mockups conditioned on real screenshot topology. *(the legacy banner carried unverified audit-readiness claims; not shipped since 2026-09-15)*
- **Clean Uninstallation Lifecycle:** Comprehensive `uninstall.php` handler for clean database and transient cache purging. *(no transients were deleted until 2026-09-15)*

#### Security

- **CWE-1236 Mitigation:** Active sanitization against formula injection attacks on all CSV exports and dynamic text inputs. *(CSV export only)*
- **Constant-Time Cryptography:** Enforced `hash_equals()` verification on all HMAC-SHA256 signature chains.
- **SQL Parameterization:** Refactored all queries to use strict `$wpdb->prepare()` with parameterized placeholders. *(several read queries interpolate the table name; no request value reaches them)*

#### Quality & Tests

- Expanded standalone deterministic test suite (`tests/test-suite.php`) with zero external dependencies. *(no run was recorded)*

---

## [1.0.0] — 2026-08-27

> Two texts exist for this number as well: the entry written on 2026-08-27 (restored from `6cbcf78`) and the one dated 2026-08-25 that replaced it on 2026-08-29. The first commit of the repository is from 2026-08-27.

### As written on 2026-08-27

#### Added

- **Core Architecture:**
  - Dedicated InnoDB relational database schema with `wp_wm_activity_logs` and `wp_wm_activity_alerts`.
  - Tamper-evident Hash-Chaining with HMAC-SHA256 and `GENESIS` block initialization. *(wording corrected in 1.3.0)*
- **Forensic Event Sensor Suite (25+ Sensors):** *(`init_sensors()` registers 22 hooks and the telemetry route)*
  - IP exfiltration sensors: Native export (`export_wp_native_triggered`), plugin editor access (`plugin_theme_file_viewed`), theme editor modifications (`plugin_theme_file_modified`), database backups. *(`plugin_theme_file_modified` listened to an action WordPress never fires; replaced 2026-09-15)*
  - Sabotage sensors: Plugin & theme install/activate/deactivate/delete, critical options monitor (`core_options_updated`). *(no theme delete sensor; installs are logged through `upgrader_process_complete`)*
  - Intrusion sensors: Brute-force failed logins (`user_login_failed`), login success, user registration, privilege escalation (`user_role_changed` -> administrator).
  - API surveillance: REST endpoint probing (`/wp/v2/plugins`, `/wp/v2/users`).
- **Integrity & Real-Time Alert Engine:**
  - One-click cryptographic chain verification tool (`WM_Sentinel_Integrity::verify_chain()`).
  - Real-time email and Webhook dispatching on critical security anomalies.
- **Enterprise Cockpit & Visor:**
  - Live activity stream with multi-criteria filter bar and slide-out JSON Context Inspector.
  - KPI Dashboard with 24h critical alert counter, warning counter, and distinct actor counts.
  - Forensic export suite with CWE-1236 Formula Injection protection (CSV) and signed JSON audit package. *(not signed: a SHA-256 checksum)*
- **Automated Verification Suites:**
  - `tests/test-sentinel-chain.php` (5/5 passing tests).
  - `tests/test-sentinel-sensors.php` (5/5 passing tests).

### As written on 2026-08-29

#### Initial Enterprise Release

- Core architectural implementation for Forensics & Security Logging.
- WP-CLI management commands under `wp sentinel`.
- Revisionssicheres GoBD cryptographic ledger and telemetry hooks. *(not in the code: the chain is tamper-evident, and "revisionssicher" is a legal assessment the plugin cannot make)*
- Claimed alignment with GoBD, GeschGehG § 2 (Angemessene Geheimhaltungsmaßnahmen), DSGVO Art. 32 & CRA. *(a claim, not a feature; the plugin makes no legal assessment)*

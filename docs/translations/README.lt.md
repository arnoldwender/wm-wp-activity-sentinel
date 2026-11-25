# WM Activity Sentinel (Lietuvių)

<p align="center">
  [🇧🇬 Български](README.bg.md) • [🇨🇿 Čeština](README.cs.md) • [🇩🇰 Dansk](README.da.md) • [🇩🇪 Deutsch](../../README.de.md) • [🇬🇷 Ελληνικά](README.el.md) • [🇬🇧 English](../../README.md) • [🇪🇸 Español](../../README.es.md) • [🇪🇪 Eesti](README.et.md) • [🇫🇮 Suomi](README.fi.md) • [🇫🇷 Français](README.fr.md) • [🇮🇪 Gaeilge](README.ga.md) • [🇭🇷 Hrvatski](README.hr.md)<br/>[🇭🇺 Magyar](README.hu.md) • [🇮🇹 Italiano](README.it.md) • [🇱🇹 Lietuvių](README.lt.md) • [🇱🇻 Latviešu](README.lv.md) • [🇲🇹 Malti](README.mt.md) • [🇳🇱 Nederlands](README.nl.md) • [🇵🇱 Polski](README.pl.md) • [🇵🇹 Português](README.pt.md) • [🇷🇴 Română](README.ro.md) • [🇸🇰 Slovenčina](README.sk.md) • [🇸🇮 Slovenščina](README.sl.md) • [🇸🇪 Svenska](README.sv.md)
</p>

---

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-6.4%20--%207.1+-21759b.svg?logo=wordpress&logoColor=white" alt="WordPress Version" /></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.1%20%7C%208.2%20%7C%208.3+-777bb4.svg?logo=php&logoColor=white" alt="PHP Version" /></a>
  <img src="https://img.shields.io/badge/Language-lt_LT-blue.svg" alt="Lietuvių" />
  <img src="https://img.shields.io/badge/Zero--CDN-100%25%20Local-purple.svg" alt="Zero-CDN" />
  <a href="../../LICENSE"><img src="https://img.shields.io/badge/License-GPLv2%20or%20later-brightgreen.svg" alt="License" /></a>
</p>

> **An activity log for WordPress whose entries are linked by an HMAC-SHA256 chain: logins, users and roles, plugins and themes, exports and the file editor, selected REST routes, page views and clicks of signed-in users, with chain verification, a file integrity monitor against the official WordPress.org checksums, forwarding to a SIEM as Syslog RFC 5424, CEF or LEEF, mail and webhook alerts and CSV or JSON export — it makes later edits, deletions and truncation detectable for anyone without the key in wp-config.php, it does not prevent them, and it makes no legal assessment**  
> **Įskiepio puslapis:** [https://arnoldwender.com/en/wordpress-plugins/wm-activity-sentinel/](https://arnoldwender.com/en/wordpress-plugins/wm-activity-sentinel/)  
> **Sukurta naudojant agentinę darbo eigą:** sukurta su dirbtinio intelekto programavimo agentais. Prieš naudodami gamyboje, peržiūrėkite ir išbandykite kaip bet kurį trečiųjų šalių kodą.  
> **Support & Liability:** Zero support — reproducible bugs on [GitHub Issues](https://github.com/arnoldwender/wm-wp-activity-sentinel/issues) only · no private support · AS IS / no liability ([SUPPORT.md](../../SUPPORT.md))

---

## 📸 Lietuvių Enterprise Showcase

!WM Activity Sentinel Banner

![WM Activity Sentinel Dashboard UI](../../.wordpress-org/screenshot-1.png)

---

## 🟢 1 LYGIS: GREITOJO PALIEKIMO VADOVAS PRADŽIAMOKSLIAMS (NO-CODE)

### Ką šis įskiepis daro jūsų svetainei?
An activity log for WordPress whose entries are linked by an HMAC-SHA256 chain: logins, users and roles, plugins and themes, exports and the file editor, selected REST routes, page views and clicks of signed-in users, with chain verification, a file integrity monitor against the official WordPress.org checksums, forwarding to a SIEM as Syslog RFC 5424, CEF or LEEF, mail and webhook alerts and CSV or JSON export — it makes later edits, deletions and truncation detectable for anyone without the key in wp-config.php, it does not prevent them, and it makes no legal assessment

### 🚀 Kaip pradėti per 2 minutes (Žingsnis po žingsnio):
1. Install and activate the plugin (WordPress 6.4+, PHP 8.2+, InnoDB). Make sure `AUTH_KEY` is defined in `wp-config.php`; otherwise the audit warns that the key is in the database.
2. Open **WM Sentinel › Integritäts-Audit**: the genesis entry verifies.
3. Under **Einstellungen**, choose the logging level and switches, set the alert address and decide whether admin screens, frontend views and clicks of signed-in users should be logged. Section 5 configures a SIEM collector, section 6 the file integrity monitor.
4. Open **WM Sentinel › Dateiintegrität** and capture the baseline of the WM Suite plugins once, so a later change to them is detectable. Re-capture it after every plugin update.
5. Check the chain regularly with `wp sentinel audit` and the files with `wp sentinel fim`, or through the hub.
6. Set **Aufbewahrungsfrist**. Since 2026-09-17 it is applied nightly: entries past the window lose name, e-mail address and IP identifier. Nothing is deleted — a deleted row would break the chain and the audit would report your own retention policy as tampering. Run it now with `wp sentinel retention --dry-run` first. See [docs/SETTINGS.md](../../docs/SETTINGS.md#retention).

### 💡 Dažniausiai užduodami klausimai pradedantiesiems:
#### ❓ What is not included?
No logging of logouts, password resets, post, page or media changes, comments, WooCommerce or login failures of other plugins; no per-change event for files outside the admin editor (the integrity monitor compares the tree on a schedule instead, and does not cover `wp-content` beyond the WM Suite plugins); no automatic deletion or archiving (the retention pass neutralises the identifying fields of old entries; the rows and their chain positions stay); no external timestamp and no signed export or PDF; no rate limit or grouping for alerts; no shortcode or standalone Gutenberg block; no assessment of whether logging signed-in users' page views and clicks, the retention of the log or its use as evidence is permitted.

---

## 🟡 2 LYGIS: TINKLALAPIŲ MEISTRŲ IR ADMINISTRATORIŲ VADOVAS

### What the plugin does
1. **Log entries.** Every entry in `wp_wm_activity_logs` stores user id, display name and e-mail of the acting user (or "System"), event type, object type and id, severity (`info`, `notice`, `warning`, `critical`), a JSON context, an HMAC of the client IP keyed with the date and `NONCE_SALT` (so hashes of one address match only within a UTC day), the UTC time, the previous entry's hash and its own hash. A genesis entry starts the chain on activation.
2. **Chain.** `entry_hash = HMAC-SHA256( prev_hash + "|" + sorted JSON of context, created_at, event_type, ip_hmac, object_id, object_type, prev_hash, severity, user_email, user_id, user_name )` with `AUTH_KEY` as key (or WordPress's stored salt when `AUTH_KEY` is not defined; both that and an `AUTH_KEY` still set to the `wp-config-sample.php` placeholder are flagged by the audit). Writing takes `SELECT … FOR UPDATE` on the newest row inside a transaction, and the newest hash and the row count are mirrored in the option `wm_sentinel_chain_tip` before the commit. When the lock query fails, or the commit does, nothing is written and no alert goes out.
3. **Verification.** The integrity page, `wp sentinel audit`, the dashboard widget and the hub adapter recompute every hash from the genesis entry on and compare the end with the mirrored tip. They report the first row whose link or hash does not match, an emptied table and a chain shorter than the tip. Rows pseudonymised by the eraser (item 11) are accepted only while their fingerprint matches the chained erasure record.
4. **Sensors.** `wp_login` and `wp_login_failed` (username attempt and user agent), `wp_create_application_password` (application name only), `user_register`, `deleted_user`, `set_user_role` (critical when the new role is administrator), `activated_plugin`, `deactivated_plugin`, `delete_plugin`, `upgrader_process_complete`, `after_switch_theme` (new and previous theme), `updated_option` for nine options (`siteurl`, `home`, `users_can_register`, `default_role`, `active_plugins`, `admin_email`, two NDA gate options, the alert address) with old and new scalar values, `export_wp`, visits to `export.php`, opening the plugin or theme file editor and every save request from it, `updraft_backup_started` and `duplicator_backup_started`, REST calls to `/wp/v2/plugins` and calls to `/wp/v2/users` without a session, every admin screen a signed-in user opens, and every frontend page a signed-in user opens (address and referrer).
5. **Click telemetry.** For signed-in users, `assets/js/sentinel-tracker.js` reports clicks on links, buttons, menus and tabs (label, target, CSS path, page address) in batches to `POST /wp-json/wm-sentinel/v1/telemetry`. The route accepts only signed-in users, only the nine event types it knows (the script produces eight; `ui_click` is the default for an entry without a type) and four categories and the script's metadata keys, stores them with severity `info`, and takes at most 60 entries per user per minute.
6. **Settings.** Menu *WM Sentinel* (position 27, with the number of critical entries of the last 24 hours), page *Einstellungen*: logging level (`all`, `standard`, `strict`, `critical_only`), click filter, switches for authentication, users, plugins and themes (including the file editor), exports and backups, REST, admin screens, clicks and frontend views, alert address, minimum alert severity, webhook URL, retention days (applied nightly since 2026-09-17: entries past the window lose name, e-mail address and IP identifier; nothing is deleted), the dashboard widget, the SIEM collector (format, transport, host and port or URL, minimum severity) and the file integrity monitor. Genesis, erasure and retention records bypass the level and the switches; integrity reports bypass only the switches, so every level but `all` drops the `info` entry of a clean daily scan.
7. **Alerts.** Each new `warning` or `critical` entry at or above the minimum severity (default `critical`; `notice` entries never alert, whatever the setting) sends a plain-text mail to the alert address and records it in `wp_wm_activity_alerts`; with a valid webhook URL, a non-blocking JSON POST (`text`, `log_id`, `event_type`, `severity`, `user`, `site`, `timestamp`) follows. A broken chain does not raise an alert.
8. **Stream and audit.** *Aktivitäts-Stream* lists entries with severity filter, search and a context inspector; *Integritäts-Audit* runs the verification and shows the result, the tip hash, the number of pseudonymised rows and a warning when the key lives in the database. A dashboard widget shows chain status, counts and the last five entries.
9. **Export.** *Export* offers a semicolon-separated UTF-8 CSV of up to 10,000 entries (cells starting with `=`, `+`, `-`, `@`, tab or carriage return get a leading apostrophe) and a JSON package with the same entries, the verification result at export time and a SHA-256 checksum of the document without the checksum key. Neither file is signed.
10. **Demo data and reset.** Up to 15 entries marked `_is_demo` can be appended (the logging level and switches apply; the import reports how many were written and sends no alert mail or webhook); removing them is refused while real entries exist (the whole log is reset otherwise; with no demo entries nothing happens). The factory reset empties both tables, starts a new chain and restores every setting to its default.
11. **WordPress privacy tools.** Under *Tools › Export / Erase Personal Data* the plugin exports up to 1,000 entries where the address is the actor or appears in the context (in one batch; more are not paged). Erasing replaces name, address and IP hash of the actor rows and the address inside any context, after appending a chained `privacy_erasure_applied` entry with a keyed fingerprint of each changed row; event type, time, object ids, user id and the rest of the context stay.
12. **WP-CLI.** `wp sentinel audit` (exit 1 on a broken chain), `wp sentinel list [--limit] [--severity]`, `wp sentinel fim [--baseline]` (exit 1 on a modified core file, an unexpected executable or a broken baseline seal), `wp sentinel demodaten --import | --delete | --reset`, `wp sentinel retention [--dry-run]` (applies the configured window now, or counts what a pass would change and writes nothing).
13. **WM Suite Hub spoke.** `includes/class-sentinel-spoke-adapter.php` registers through `wm_register_suite_module`: health `ERROR` with a broken chain, `DEGRADED` when the key lives in the database or is the sample placeholder, `HEALTHY` otherwise; telemetry with totals, critical entries of 24 hours, chain status and error, verified and pseudonymised entries, key source and tip hash; actions `self_test` / `audit_chain` / `verify_chain`; one SBOM component.
14. **SIEM forwarding.** Off by default. With a collector configured, every entry at or above the chosen severity is also sent as Syslog RFC 5424 (facility 13, "log audit"), ArcSight CEF:0 or IBM LEEF:2.0 over UDP, TCP or HTTPS — the formats Splunk, Microsoft Sentinel, Datadog, Elastic and QRadar read. Each format's escaping is applied (the pipe in a CEF header, the equals sign in a CEF extension, the delimiter LEEF names in its own header), every payload is a single line, and the forwarder runs after the commit and outside the alert condition, so the SIEM receives the stream and filters it itself. A collector that is down returns false and is not retried; the stored entry is unaffected.
15. **File integrity monitor.** A daily scan (on by default) compares the installed core files against the official catalogue at `api.wordpress.org/core/checksums/1.0/` for this version and locale: modified files, missing files, and executable files sitting in `wp-admin` or `wp-includes` that the catalogue does not list — which is what a dropped backdoor looks like. `wp-content` is skipped, since the site owns it, and the absence of `readme.html`, `license.txt` or `wp-config-sample.php` counts as hardening rather than a finding. The WM Suite plugins have no official catalogue, so a baseline of their PHP, JS and CSS files is captured and its fingerprint **sealed into the hash chain**: rewriting the stored baseline to match an edited file breaks that chained entry. A version the API does not know (answered with HTTP 200 and `"checksums": false`) is reported as "not checked", never as clean. The result is written into the chain as its own entry — and therefore forwarded to the SIEM when forwarding is on.
16. **Developer hooks, Block Bindings and GenerateBlocks Dynamic Tags.** Fires `do_action( 'wm_sentinel_ready', $core )` after core boot, bridges external events via `wm_activity_sentinel_log_event` and `wm_sentinel_security_incident`, filters monitored options and public metrics via `wm_sentinel_monitored_options` and `wm_sentinel_public_metrics`, and registers the WordPress 6.5+ Block Bindings source `wm-sentinel/metrics` and the GenerateBlocks 2.x Dynamic Tag `wm_sentinel_metric` for non-sensitive status and counters (`chain_status`, `verified_blocks`, `total_events`, `version`).

### Not shipped
No logging of logouts, password resets, post, page or media changes, comments, WooCommerce or login failures of other plugins; no per-change event for files outside the admin editor (the integrity monitor compares the tree on a schedule instead, and does not cover `wp-content` beyond the WM Suite plugins); no automatic deletion or archiving (the retention pass neutralises the identifying fields of old entries; the rows and their chain positions stay); no external timestamp and no signed export or PDF; no rate limit or grouping for alerts; no shortcode or standalone Gutenberg block; no assessment of whether logging signed-in users' page views and clicks, the retention of the log or its use as evidence is permitted.

---

## 🔴 3 LYGIS: PROGRAMUOTOJŲ IR PROGRAMINĖS ĮRANGOS ARCHITEKTŲ VADOVAS

Classes, hooks, options, the admin screen and the WM Suite Hub adapter are documented in the English manual: **[DEVELOPERS.md](../../DEVELOPERS.md)**.

---

## ⚖️ Europos Sąjungos teisinė atitiktis

- **What the plugin establishes:** that the listed events were written in this order by code holding the key, and whether the stored rows still match. It does not establish that nothing else happened, when an entry was really written, or who operated the server, and it does not decide whether the logging, its retention or its use are lawful.
- **Outbound requests:** alert mails through `wp_mail()`; when configured, a JSON POST to the webhook URL and the forwarded entries to the SIEM collector; and, while the integrity monitor is on, one daily GET to `api.wordpress.org/core/checksums/1.0/` carrying the WordPress version and the site locale and nothing else — the same two values WordPress already sends on every update check. The click tracker talks only to the site's own REST route.
- **Personal data:** actor name and e-mail, daily-keyed IP hashes, usernames of failed logins, user agents, visited addresses and referrers, click labels and targets, and old and new values of the monitored options, kept until deleted by hand, erased through the WordPress tools or dropped on uninstall.

---

## 🔒 Licencija ir nuosavybės teisės

Distributed under the **GNU General Public License v2 or later**.  
Copyright (C) 2026 Arnold Wender.  

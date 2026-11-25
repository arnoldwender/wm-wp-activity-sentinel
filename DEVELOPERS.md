# WM Activity Sentinel — Developer Manual

> Everything below is measured against the code in this repository (updated 2026-11-20 for v1.4.0). Earlier editions before 2026-09-15 documented unshipped REST routes, legacy table names, an unshipped tamper hook and a shortcode; none of those exist here. Developer extensibility hooks: `do_action( 'wm_sentinel_ready', $core )`, `apply_filters( 'wm_sentinel_monitored_options', $options )`, `apply_filters( 'wm_sentinel_public_metrics', $metrics )`, inter-plugin bridges `wm_activity_sentinel_log_event` and `wm_sentinel_security_incident`, WordPress 6.5+ Block Bindings source `wm-sentinel/metrics`, and GenerateBlocks 2.x Dynamic Tag `wm_sentinel_metric`.

---

## 1. Layout

| Path | Role |
| --- | --- |
| `wm-activity-sentinel.php` | Header, constants (`WM_SENTINEL_VERSION` / `_FILE` / `_PATH` / `_URL` / `_BASENAME`), `require_once` of every class, hub registration on `wm_register_suite_module`, activation (`WM_Sentinel_Core::activate()`), multisite `wp_initialize_site` (`WM_Sentinel_Core::on_initialize_site()`), deactivation (clears `wm_sentinel_fim_scan`, `WM_Sentinel_Retention::CRON_HOOK` and the never-scheduled legacy `wm_sentinel_retention_purge`), the `wm_sentinel_fim_scan` and `wm_sentinel_apply_retention` cron callbacks, `WM_Sentinel_Retention::sync_schedule()` on `plugins_loaded` priority 5 — **not only on activation**, because an already-active install never runs its activation hook again and the pass arrived dead on every existing site until that was measured (2026-09-17) — text domain on `init`, boot on `plugins_loaded`, action links. |
| `includes/class-sentinel-core.php` | `WM_Sentinel_Core` singleton: registers the sensors, the privacy exporter and eraser filters, WP 6.5+ Block Bindings (`wm-sentinel/metrics`), GenerateBlocks 2.x Dynamic Tag (`wm_sentinel_metric`), `get_public_metrics()` (`wm_sentinel_public_metrics`), the admin hooks when `is_admin()`, fires `do_action( 'wm_sentinel_ready', $this )`, and provides `activate()`, `activate_site()` and `on_initialize_site()`. |
| `includes/class-sentinel-database.php` | `WM_Sentinel_Database`: table names, `create_tables()`, `ensure_genesis_block()`, `get_last_entry()`, `get_last_entry_for_update()`, `get_logs()`, `get_logs_count()`, `get_kpis()`, `reset_tables()`, `record_chain_tip()`, `get_chain_tip()`, `reanchor_chain_tip()`, `default_options()`, `should_autoload_option()`, constants `TIP_OPTION`, `LEGACY_OPTIONS`, `FRONTEND_AUTOLOAD_OPTIONS`. |
| `includes/class-sentinel-logger.php` | `WM_Sentinel_Logger`: `log()`, `compute_entry_hash()`, `compute_residual_hash()`, `canonical_json()`, `generate_ip_hmac()`, `is_severity_permitted()`, `is_module_permitted()`, `chain_key_source()`, constant `ALWAYS_RECORDED`. |
| `includes/class-sentinel-integrity.php` | `WM_Sentinel_Integrity::verify_chain()`. |
| `includes/class-sentinel-retention.php` | `WM_Sentinel_Retention`: `init()`, `sync_schedule()`, `unschedule()`, `configured_days()`, `run_scheduled()`, `apply( bool $dry_run )`; constants `CRON_HOOK`, `RECORD_EVENT`, `NEUTRAL_NAME`, `BATCH_LIMIT`. |
| `includes/class-sentinel-fim.php` | `WM_Sentinel_FIM`: `fetch_catalogue()`, `parse_catalogue()`, `normalise_relative_path()`, `scan_core()`, `scan_unexpected()`, `capture_suite_baseline()`, `suite_directories()`, `compare_suite_baseline()`, `manifest_fingerprint()`, `seal_suite_baseline()`, `baseline_seal_status()`, `last_sealed_fingerprint()`, `run()`, `run_scheduled()`, `severity_for()`, `count_findings()`, `log_context()`, `last_report()`, `sync_schedule()`. |
| `includes/class-sentinel-forwarder.php` | `WM_Sentinel_Forwarder`: `forward()`, `should_forward()`, `build_entry()`, `format()` and the private `as_syslog()` / `as_cef()` / `as_leef()` / `send()`; constants `FORMATS`, `TRANSPORTS`, `VENDOR`, `PRODUCT`. |
| `includes/class-sentinel-sensors.php` | `WM_Sentinel_Sensors`: `init_sensors()` (the complete hook list), one callback per sensor, the telemetry route, `enqueue_frontend_tracker()`, constants `TELEMETRY_EVENT_TYPES`, `TELEMETRY_CATEGORIES`, `TELEMETRY_METADATA_KEYS`, `TELEMETRY_RATE_LIMIT`. |
| `includes/class-sentinel-alerts.php` | `WM_Sentinel_Alerts::evaluate_and_dispatch()`. |
| `includes/class-sentinel-exporter.php` | `WM_Sentinel_Exporter`: `sanitize_csv_cell()`, `export_csv()`, `export_json()`, privacy exporter and eraser registration and callbacks. |
| `includes/class-sentinel-seeder.php` | `WM_Sentinel_Seeder`: `seed_demo_data()`, `delete_demo_data()`, `reset_to_factory_defaults()`. |
| `includes/class-sentinel-cli.php` | `WM_Sentinel_CLI_Command` (`wp sentinel`), loaded only under WP-CLI. |
| `includes/class-sentinel-admin.php` | `WM_Sentinel_Admin`: menu, family sheet enqueue, tracker enqueue in the admin, dashboard widget, settings and demo forms, export requests, five screens, contextual help. |
| `includes/class-sentinel-spoke-adapter.php` | WM Suite Hub contract; returns early when the hub's interface does not exist. |
| `assets/js/sentinel-tracker.js` | Click tracker for signed-in users. |
| `assets/js/sentinel-admin.js` | Context inspector dialog on the stream screen. |
| `assets/css/sentinel-admin.css` | Sentinel layer on top of the family sheets. |
| `assets/css/wm-admin-*.css` | Copies of the hub's canonical sheets, pinned by `tests/test-ui-family.php`. |
| `uninstall.php` | Every setting and legacy option, the telemetry and catalogue transients, and the retention and integrity cron hooks. Preserves `wp_wm_activity_logs`, `wp_wm_activity_alerts`, `wm_sentinel_chain_tip`, `wm_sentinel_fim_baseline` and `wm_sentinel_fim_last_scan` by default (`wm_sentinel_delete_data_on_uninstall = 0`), and drops/deletes them when `wm_sentinel_delete_data_on_uninstall = 1`. |

---

## 2. Storage

`wp_wm_activity_logs` (InnoDB, site charset): `log_id` (PK), `user_id` (NULL for system and visitors), `user_name` (default `System`), `user_email`, `event_type`, `object_type`, `object_id`, `severity` (`info`, `notice`, `warning`, `critical`), `context` (canonical JSON), `ip_hmac`, `created_at` (UTC), `prev_hash`, `entry_hash`, `tsa_timestamp` (unused: nothing writes or reads it). Keys on `(user_id, created_at)`, `severity`, `event_type`, `created_at`.

`wp_wm_activity_alerts`: `alert_id` (PK), `log_id`, `severity` (`warning`, `critical`), `alert_message` (the mail subject), `channel`, `status` (`sent`, `failed`, `pending`), `created_at`. Only mail dispatches are recorded; the webhook is not.

| Option | Default | Read by |
| --- | --- | --- |
| `wm_sentinel_logging_intensity` | `all` | `is_severity_permitted()` |
| `wm_sentinel_telemetry_intensity` | `all` | tracker config (`all`, `actions_only`, `critical_only`) |
| `wm_sentinel_log_auth`, `_log_users`, `_log_plugins`, `_log_content`, `_log_rest` | 1 | `is_module_permitted()` |
| `wm_sentinel_monitor_screens`, `_monitor_frontend_views` | 1 | `is_module_permitted()` and the two view sensors |
| `wm_sentinel_monitor_ui_interactions` | 1 | `is_module_permitted()`, tracker enqueue, telemetry handler |
| `wm_sentinel_alert_email` | `admin_email` | alerts |
| `wm_sentinel_alert_min_severity` | `critical` | alerts (`critical`, `warning`, `notice`) |
| `wm_sentinel_webhook_url` | empty | alerts |
| `wm_sentinel_retention_days` | 90 | settings screen and `WM_Sentinel_Retention::configured_days()`; applied daily since 2026-09-17 — it neutralises identifying fields, it deletes nothing (§14) |
| `wm_sentinel_enable_dashboard_widget` | 1 | widget registration, dashboard enqueue |
| `wm_sentinel_siem_enabled` | 0 | `WM_Sentinel_Forwarder::should_forward()` |
| `wm_sentinel_siem_format` | `cef` | `should_forward()`, `forward()` (`syslog`, `cef`, `leef`; anything else sends nothing) |
| `wm_sentinel_siem_transport` | `https` | `send()` (`udp`, `tcp`, `https`) |
| `wm_sentinel_siem_host`, `_siem_port` | empty, 514 | `send()` for `udp` and `tcp` |
| `wm_sentinel_siem_url` | empty | `send()` for `https`, through `wp_http_validate_url()` |
| `wm_sentinel_siem_min_severity` | `warning` | `should_forward()` |
| `wm_sentinel_fim_enabled` | 1 | `run_scheduled()`, `sync_schedule()`, settings and integrity screens |
| `wm_sentinel_fim_scan_suite` | 1 | `WM_Sentinel_FIM::run()` |
| `wm_sentinel_delete_data_on_uninstall` | 0 | settings screen and `uninstall.php` (`0` preserves tables, tip and FIM state; `1` drops/deletes them) |
| `wm_sentinel_fim_baseline` | written by `seal_suite_baseline()` | `run()`, `baseline_seal_status()` |
| `wm_sentinel_fim_last_scan` | written by `run()` | `last_report()`, the integrity screen |
| `wm_sentinel_chain_tip` | written by the logger | `verify_chain()`, adapter |

`LEGACY_OPTIONS` lists eight names earlier versions wrote and nothing reads; the factory reset and the uninstaller delete them. Transients: `wm_sentinel_telemetry_<user id>_<YmdHi>` (entries accepted in that minute, two minutes) and `wm_sentinel_fim_catalogue_<md5 of version|locale>` (the parsed checksum catalogue, twelve hours, a few hundred kilobytes). The two baseline options are written by the monitor rather than by the settings form, so `default_options()` does not list them and `uninstall.php` names them itself.

---

## 3. Writing an entry

`WM_Sentinel_Logger::log( ?int $user_id = null, string $event_type = 'general_event', string $object_type = 'system', ?int $object_id = null, string $severity = 'info', array $context = [], ?string $ip = null ): int|false`

1. Unless `event_type` is in `ALWAYS_RECORDED` (`genesis_initialized`, `privacy_erasure_applied`, `retention_pseudonymized`): the severity must be allowed by the logging level (`all` everything, `standard` notice and above, `strict` warning and above, `critical_only`), and `is_module_permitted()` must return true. It maps event and object types to the switches in this order: admin screen views, frontend views, click telemetry, authentication, users, plugins/themes/options/files, content/exports/backups, REST; anything else is allowed.
2. `user_id` defaults to the current user; name and e-mail come from `get_userdata()`, otherwise `System` and empty.
3. `ip_hmac = hash_hmac( 'sha256', ip, gmdate( 'Y-m-d' ) . NONCE_SALT )`, with `wp_salt( 'nonce' )` when `NONCE_SALT` is not defined. The address is `REMOTE_ADDR` unless passed; proxies are not resolved.
4. `context` is serialised with recursively sorted keys.
5. `START TRANSACTION`, `SELECT * … ORDER BY log_id DESC LIMIT 1 FOR UPDATE`, `prev_hash` = that row's `entry_hash` or `GENESIS`, `entry_hash = hash_hmac( 'sha256', prev_hash . '|' . json( ksort( context, created_at, event_type, ip_hmac, object_id, object_type, prev_hash, severity, user_email, user_id, user_name ) ), key )`, `INSERT`, `record_chain_tip( entry_hash, COUNT(*) )`, `COMMIT`. When the lock query returns no row and `$wpdb->last_error` is set, it rolls back and returns `false` (until 2026-09-15 the entry was chained to `GENESIS`); a failed insert rolls back and returns `false`; a failed `COMMIT` returns `false` without alerts. The result of the tip write is not checked.
6. After the commit, `warning` and `critical` entries go to `WM_Sentinel_Alerts::evaluate_and_dispatch()`, unless `mute_alerts( true )` is active (the demo import).
7. Also after the commit and **outside** the alert condition, every entry goes to `WM_Sentinel_Forwarder::forward()`, which applies its own switch and minimum severity. A SIEM takes the stream and filters it itself, so the forwarder must not inherit the alert threshold.

The key is `AUTH_KEY` when it is defined and not empty (`chain_key_source()` = `wp-config`, or `wp-config-placeholder` when it is the `wp-config-sample.php` phrase, which still signs so stored entries keep verifying), otherwise `wp_salt( 'auth' )` (`database`). Other plugins can call `log()` directly; nothing guards against a caller writing any event type or severity.

---

## 4. Verifying the chain

`WM_Sentinel_Integrity::verify_chain(): array` loads every row ordered by `log_id` and returns `valid`, `total_verified`, `broken_at_id`, `error_reason`, `key_source`, `pseudonymized` and, when it reaches the end, `last_entry_hash`.

| Case | Result |
| --- | --- |
| Table empty, no tip or tip count 0 | valid |
| Table empty, tip count above 0 | invalid, no id, "Das Protokoll wurde gelöscht" |
| `prev_hash` differs from the previous row's `entry_hash` (or `GENESIS`) | invalid at that id, "Kettenbruch" |
| Recomputed hash differs and the row is not an accepted erasure | invalid at that id, "Manipulationsnachweis" |
| Recomputed hash differs, a `privacy_erasure_applied` **or `retention_pseudonymized`** entry with a higher `log_id` lists the row in `residual_hashes`, and `compute_residual_hash( row )` matches | accepted, counted in `pseudonymized` |
| Tip hash differs from the last hash, or tip count above the rows verified | invalid, no id, "Die neuesten Protokolleinträge wurden entfernt" |

`compute_residual_hash()` is `hash_hmac( 'sha256', 'erasure|' . json( ksort( context, created_at, entry_hash, event_type, ip_hmac, log_id, object_id, object_type, prev_hash, severity, user_email, user_id, user_name ) ), key )`. An erasure record is itself a chained entry, so a forged one fails its own recomputation first.

Callers: the integrity screen, the dashboard widget, `wp sentinel audit`, the JSON export, and the adapter's health, telemetry and actions. Each call reads the whole table.

---

## 5. Sensors

`init_sensors()` registers these and nothing else:

| Hook | Event type | Object type | Severity |
| --- | --- | --- | --- |
| `export_wp` | `export_wp_native_triggered` | `export` | critical |
| `admin_init` on `export.php` (GET) | `export_page_inspected` | `export` | warning |
| `load-plugin-editor.php`, `load-theme-editor.php` | `plugin_theme_file_viewed` | `file` | warning |
| `wp_ajax_edit-theme-plugin-file` (priority 0) | `plugin_theme_file_save_requested` | `file` | critical |
| `updraft_backup_started`, `duplicator_backup_started` | `database_backup_requested` | `backup` | critical |
| `activated_plugin` | `plugin_activated` | `plugin` | notice |
| `deactivated_plugin`, `delete_plugin` | `plugin_deactivated`, `plugin_deleted` | `plugin` | warning |
| `upgrader_process_complete` | `upgrader_process_executed` (context = the upgrader's `$hook_extra`) | `system` | notice |
| `after_switch_theme` | `theme_switched` (`new_theme`, `previous_theme`) | `theme` | warning |
| `updated_option` for nine options | `core_options_updated` (old and new scalar value, `[complex]` otherwise) | `option` | notice |
| `wp_login_failed` | `user_login_failed` (`username_attempt`, `user_agent`) | `user` | warning |
| `wp_login` | `user_login_success` (roles) | `user` | info |
| `user_register` | `user_created` (login, e-mail) | `user` | notice |
| `deleted_user` | `user_deleted` | `user` | warning |
| `set_user_role` | `user_role_changed` | `user` | critical for `administrator`, notice otherwise |
| `wp_create_application_password` | `application_password_created` (application name) | `user` | warning |
| `rest_pre_dispatch` | `rest_plugins_enumerated` (any `/wp/v2/plugins` call), `rest_users_enumerated` (`/wp/v2/users` without a session) | `api` | warning |
| `current_screen` | `admin_screen_viewed` (screen id, base, title, URI) for signed-in users, not for AJAX, cron or REST | `navigation` | info |
| `template_redirect` | `frontend_page_viewed` (post, title, type, URI, referrer) for signed-in users, not for feeds, robots or trackbacks | `navigation` | info |
| `wm_activity_sentinel_log_event` | `hub_audit_bridged` (`module_slug`, `hub_event_type`, `hub_hash`, `hub_timestamp`) from WM Suite Hub or third-party spokes | `hub` | notice (or valid payload `severity`) |
| `wm_sentinel_security_incident` | `security_incident_reported` (`incident_type`, `reason`, `parameter`, `source`; raw `ip` is never stored in `context`, only passed for daily-salted `ip_hmac`) | `security` | warning / critical |

The nine default options (extensible via `apply_filters( 'wm_sentinel_monitored_options', $monitored_options )`): `siteurl`, `home`, `users_can_register`, `default_role`, `active_plugins`, `admin_email`, `wm_nda_require_login_checkbox`, `wm_nda_enforce_admin_gate`, `wm_sentinel_alert_email`. The frontend view sensor skips admin requests unless the constant `WM_SENTINEL_TESTING_MODE` is defined, which the WP-CLI suites do.

---

## 6. Click telemetry

`assets/js/sentinel-tracker.js` is enqueued for signed-in users on every frontend page (`wp_enqueue_scripts`) and on every admin page (`admin_enqueue_scripts`) while `wm_sentinel_monitor_ui_interactions` is on, versioned by file time on both. It listens to clicks in the capture phase on links, buttons, inputs, tabs, menu entries and a few WordPress classes, drops repeats of the same element within 1.5 s, classifies each click into one of eight event types (`TELEMETRY_EVENT_TYPES` has nine; `ui_click` is the server default for an entry without one) and four categories, filters by `telemetryIntensity` (`actions_only` keeps menu, bar, tab, row action, button and download clicks; `critical_only` keeps downloads and labels matching delete, reset, export and similar words), queues it and sends up to 20 entries two seconds later with `fetch` (`X-WP-Nonce`, same-origin credentials, `keepalive`) or `navigator.sendBeacon` when the page is hidden or closed. Metadata: tag, id, label, href, classes, description, CSS path of up to five levels, page path and query, browser time.

`POST /wp-json/wm-sentinel/v1/telemetry`:

1. `telemetry_permission()`: signed-in users only (403 otherwise).
2. Monitoring off: 200 with `logged` 0.
3. Events from `events` (JSON or form body) or a single object with `event_type`; none: 400.
4. At most 25 per request. Each needs an event type in `TELEMETRY_EVENT_TYPES` and a category in `TELEMETRY_CATEGORIES`, and the user must have written fewer than 60 in the current calendar minute (a transient read and written without a lock, so parallel requests can pass the limit); otherwise it counts as refused.
5. Only `TELEMETRY_METADATA_KEYS` with scalar values are kept, sanitised and cut at 300 characters; the entry is logged with the category as object type and severity `info`.
6. Response: `success`, `logged`, `refused`.

---

## 7. Alerts

`evaluate_and_dispatch( int $log_id, string $severity, string $event_type, string $user_name, array $context )` runs for `warning` and `critical` entries only. It returns when the entry ranks below `wm_sentinel_alert_min_severity` (info 1, notice 2, warning 3, critical 4). With a valid alert address it sends a German plain-text mail (subject `[SEVERITY ALARM] event auf Site`, body with event, severity, user, UTC time, log id and the context as JSON) and inserts a row with `sent` or `failed`. With a valid webhook URL it posts JSON non-blocking. There is no throttle, no retry and no alert on a broken chain.

---

## 8. Export and privacy tools

| Entry point | Behaviour |
| --- | --- |
| `admin.php?action=wm_sentinel_export_csv` / `_json` on `admin_init` | `manage_options` and the nonce `wm_sentinel_export_csv` / `_json`, else `wp_die` 403. Up to 10,000 newest entries. |
| `export_csv()` | UTF-8 BOM, semicolon separator, eleven columns including both hashes and the raw context; every cell through `sanitize_csv_cell()` (apostrophe before `=`, `+`, `-`, `@`, tab, carriage return). |
| `export_json()` | `audit_package_metadata` (issuer, site URL, time, count, `chain_intact`, `verified_blocks`, `pseudonymized`) and `activity_records`; `master_checksum_sha256` = SHA-256 of the pretty-printed document before that key is added. Not signed. |
| `export_personal_data( $email, $page )` | Up to 1,000 rows where `user_email` equals the address or `context` contains it; seven fields per row; `done` true, so rows beyond 1,000 are not exported. |
| `erase_personal_data( $email, $page )` | Plans per row: actor rows get `[DSGVO Art. 17 Gelöscht]`, `anonymized-<id>@privacy.local` and the HMAC of `0.0.0.0`; any context containing the address gets it replaced. Then logs `privacy_erasure_applied` (notice) with `pseudonymized_rows` and `residual_hashes`; when that fails nothing changes. Then updates each row. The message names the number of rows and the record id. |

The eraser matches the address case-insensitively as a string; it does not look for usernames or display names.

---

## 9. Demo data, reset, WP-CLI

- `seed_demo_data( 15 )`: appends entries from eight scenarios, each context marked `_is_demo`, with alerts muted; returns the number `log()` actually wrote (the level and switches apply).
- `delete_demo_data()`: 0 when there are no demo rows; −1 (refused) when any row other than genesis and demo rows exists; otherwise `reset_tables()` and the number of demo rows. There is no way to remove rows from the middle of the chain.
- `reset_to_factory_defaults()`: `TRUNCATE` of both tables, tip deleted, new genesis entry, every default written, legacy options deleted.

| Command | Effect |
| --- | --- |
| `wp sentinel audit` | `verify_chain()`; warning when the key lives in the database or is the sample placeholder; success with the count and pseudonymised rows; error (exit 1) with id or "am Kettenende" and the reason. |
| `wp sentinel list [--limit=<n>] [--severity=<level>]` | Table of the newest entries (default 20). |
| `wp sentinel fim` | Runs `WM_Sentinel_FIM::run( 'cli' )`, prints the buckets (first 20 paths each) and exits 1 on a broken baseline seal or a critical report. |
| `wp sentinel fim --baseline` | `seal_suite_baseline( WP_PLUGIN_DIR )`; errors when the chained sealing entry could not be written, since an unsealed baseline proves nothing. |
| `wp sentinel demodaten --import` / `--delete` / `--reset` | Seeder methods above; `--import` names how many of 15 were written (warning at 0); `--delete` errors when refused and says nothing changed at 0; `--reset` asks for confirmation (`--yes` skips it). |

---

## 10. Admin

`add_menu_page` with `manage_options`, slug `wm-sentinel-stream`, position 27, the count of critical entries in the last 24 hours as badge. Subpages `wm-sentinel-stream`, `wm-sentinel-integrity`, `wm-sentinel-fim`, `wm-sentinel-export`, `wm-sentinel-settings`, `wm-sentinel-docs`. Family sheets on hooks containing `wm-sentinel` or `wm-activity-sentinel` (hub dispatch) and on `index.php` while the widget is on; `sentinel-admin.js` on the plugin's screens. The settings form (`wm_sentinel_settings_nonce`) whitelists the logging level, telemetry level, minimum alert severity, SIEM format, transport and minimum severity, and calls `sync_schedule()` after saving; the integrity screen's two buttons use `wm_sentinel_fim_action` and show the stored report rather than scanning on load; demo actions use `wm_sentinel_demo_action`, the factory reset `wm_sentinel_reset_action`. The stream shows 25 entries per page with severity filter and search over name, e-mail, context and event type. `register_admin_menu()` and the widget query the table on every admin page and dashboard load.

---

## 11. WM Suite Hub spoke

| Method | Behaviour |
| --- | --- |
| `get_health_status()` | `ERROR` when `verify_chain()` is invalid; `DEGRADED` when the key source is `database` or `wp-config-placeholder`; `HEALTHY` otherwise. |
| `get_telemetry_metrics()` | `total_events`, `critical_24h`, `chain_integrity` (`INTACT` / `BROKEN`), `chain_error`, `verified_blocks`, `pseudonymized`, `chain_key_source`, `latest_entry_hash` (first 16 characters of the tip). |
| `execute_action()` | `self_test`, `audit_chain`, `verify_chain`: the verification result and a message; anything else fails. |
| `get_sbom_data()` | One component, SHA-256 of the main file, the licence as the SPDX id `GPL-2.0-or-later` alone (until 2026-11-05 `Proprietary`, with an id and a name), feature properties. |
| `get_settings_url()` | `admin.php?page=wm-sentinel-settings`. |
| `get_version()` | `WM_SENTINEL_VERSION`. |

---

## 12. SIEM forwarding

`WM_Sentinel_Forwarder::forward( array $entry ): bool` is called by the logger for every committed entry. It is `should_forward()` (policy, no network) followed by `format()` (pure) and `send()` (network). The split exists because a single `false` used to mean both "the settings refused it" and "the collector refused it", and no test could tell which one it had measured.

| Step | Behaviour |
| --- | --- |
| `build_entry( log_id, severity, event_type, user_name, context, timestamp )` | The fields every format prints, plus `site` = `site_url()`. An unknown severity becomes `info`. |
| `should_forward()` | `wm_sentinel_siem_enabled`, then the entry's severity rank against `wm_sentinel_siem_min_severity` (info 1 … critical 4), then the format against `FORMATS`. An unrecognised format sends nothing rather than falling back to one. |
| `format( 'syslog', … )` | `<PRI>1 TIMESTAMP HOSTNAME wm-activity-sentinel - MSGID [wmSentinel@0 logId="…" severity="…" user="…" site="…"] MSG`. PRI = facility 13 ("log audit") × 8 + severity (info 6, notice 5, warning 4, critical 2), so a critical entry is `<106>` and an info entry `<110>`. Structured data escapes `\`, `"` and `]`. |
| `format( 'cef', … )` | `CEF:0\|WM Suite\|WM Activity Sentinel\|version\|event_type\|Readable Name\|0-10\|extensions`. Header fields escape `\` and `\|`; extension values escape `\` and `=`. A pipe inside an extension value is legal and left alone. Extensions: `rt` (ms), `suser`, `externalId`, `cs1Label`/`cs1` = site, `cs2Label`/`cs2` = context, `cat`. |
| `format( 'leef', … )` | `LEEF:2.0\|WM Suite\|WM Activity Sentinel\|version\|EventID\|^\|attributes`, the sixth header field naming the delimiter. A caret inside a value is replaced with a space, or the collector would split the attribute. |
| `send()` | `https`: `wp_remote_post()` non-blocking, two-second timeout, `text/plain`, only after `wp_http_validate_url()`. `udp`/`tcp`: `stream_socket_client()` to host and port, two seconds, one line with a trailing newline. Anything else, or a missing target, returns `false`. |

Every payload is one line: `\r`, `\n` and tabs inside values collapse to spaces. Nothing is retried and no error reaches the request — the entry is already stored and the forward is a copy.

---

## 13. File integrity monitor

`WM_Sentinel_FIM::run( string $trigger = 'cron', ?string $root = null, ?string $plugins_dir = null ): array` is the whole monitor. `$root` and `$plugins_dir` default to `ABSPATH` and `WP_PLUGIN_DIR` and exist so the standalone suite can point it at a fixture tree.

**Core.** The locale is `installed_package_locale()` — `$wp_local_package` from `wp-includes/version.php`, then `WP_LOCAL_PACKAGE`, then `en_US` — and **not** `get_locale()`: the catalogue is per package, and a German-language site running the en_US package compared against the de_DE catalogue reports `version.php`, `wp-config-sample.php` and the localized `liesmich.html` as findings on a clean tree (measured 2026-09-16 on a local WordPress install). `fetch_catalogue( version, locale )` GETs `https://api.wordpress.org/core/checksums/1.0/?version=…&locale=…` (15 s, cached twelve hours) and hands the body to `parse_catalogue()`, which returns `['checksums' => path => md5, 'error' => null]` or an error. Four bodies are errors and not empty catalogues: a non-`checksums` payload, `"checksums": false` (**how the API answers an unknown version — with HTTP 200**, measured 2026-09-16 against `?version=99.9.9`), a catalogue whose entries are all unusable, and a non-200 status. Each entry passes `normalise_relative_path()`, which drops anything absolute, containing `..`, carrying a drive letter or a NUL byte, so a hijacked response cannot make the scanner read outside the installation; each hash must be 32 hex characters.

`scan_core( checksums, root )` compares `md5_file()` against the catalogue and returns `checked`, `modified`, `missing` and `hardening_removals`. Entries under `wp-content/` are skipped — the catalogue lists the bundled themes and Akismet, which a site may legitimately update or delete, and `wp core verify-checksums` skips them for the same reason. `readme.html`, `license.txt`, `wp-config-sample.php` and any other catalogue entry at the root ending in `.html` go to `hardening_removals` when absent rather than to `missing` — a localized package ships a translated readme (`liesmich.html` on de_DE) beside the English one, and a name list per locale would go stale. An `.html` missing from `wp-admin` or `wp-includes` stays a finding.

`scan_unexpected( checksums, root )` walks `wp-admin` and `wp-includes` and returns every file with an executable extension (`php`, `phtml`, `php3`–`php8`, `phps`, `phar`, `inc`) that the catalogue does not list. This is the half that finds a dropped file: a backdoor adds a file rather than editing one, and a walk over the catalogue alone never looks at it.

**WM Suite plugins.** There is no official catalogue, so `capture_suite_baseline( plugins_dir )` hashes every `.php`, `.js` and `.css` under each `wm-*` plugin folder with SHA-256, pruning `SUITE_EXCLUDED_DIRS` (`node_modules`, `.git`, `.github`, `.svn`, `.idea`, `.vscode`) during the walk — but **not** `vendor/`, which runs through an autoloader and is where a backdoor would sit. Above `MAX_BASELINE_FILES` (20,000) `seal_suite_baseline()` refuses and stores nothing rather than writing a manifest that every scan then reads and rewrites; measured 2026-09-16 on a local WordPress install, an unpruned capture was 47,085 files and 7.4 MB in one option row, 46,443 of them development artifacts. It stores that manifest in `wm_sentinel_fim_baseline` **and writes a chained `fim_baseline_sealed` entry carrying `manifest_fingerprint()`** — SHA-256 over the canonically sorted manifest. `baseline_seal_status()` compares the stored baseline's fingerprint against the newest sealing entry: `sealed`, `unsealed` (no entry) or `broken`. The baseline alone would be worthless — whoever edited a plugin file can rewrite an option — so the seal is what makes that second edit visible, on the same reasoning as the chain tip.

**Result.** `severity_for()` is `critical` for a modified core file, an unexpected executable or a broken seal; `warning` for a missing core file, a changed or missing plugin file, **or a catalogue that could not be loaded**; `notice` for files added since the baseline; `info` otherwise. Not having looked is never an all-clear. The report is stored in `wm_sentinel_fim_last_scan` and written into the chain as `fim_scan_completed` or `fim_integrity_finding` with object type `integrity` — deliberately not `file`, which `wm_sentinel_log_plugins` filters, so no module switch can silence a modified `wp-includes`. `log_context()` caps each path list at 200 entries with a `… +N` marker.

**Schedule.** `sync_schedule()` adds or removes the daily `wm_sentinel_fim_scan` event to match `wm_sentinel_fim_enabled`; it runs on activation and on every settings save. `run_scheduled()` checks the switch again before scanning.

---

## 14. Retention

`WM_Sentinel_Retention::apply( bool $dry_run = false ): array` is the whole pass. A daily event
`wm_sentinel_apply_retention` calls `run_scheduled()`, which returns immediately while retention is
off. Until 2026-09-17 `wm_sentinel_retention_days` was written by activation, by the seeder and by
the settings form, and read in exactly **one** place: to render itself back into that form. A field
labelled *Aufbewahrungsfrist* that removes nothing reads like a DSGVO Art. 5(1)(e) storage-limitation
control and is not one.

| Step | Behaviour |
| --- | --- |
| `configured_days()` | `wm_sentinel_retention_days`, clamped: **below 30 (the form's minimum) returns 0 = keep everything**, never "pseudonymise everything"; above 3650 returns 3650. When the value is missing or out of range, the default is never the destructive one. |
| `sync_schedule()` | Schedules the daily event while the window is above 0, unschedules it at 0. Registered on **`plugins_loaded` priority 5**, not only on activation — an already-active install never runs its activation hook again, and the feature arrived dead on every existing site until that was measured (2026-09-17). |
| Candidate query | `created_at < cutoff`, `event_type NOT IN ALWAYS_RECORDED`, and still carrying something to neutralise (`user_name <> NEUTRAL_NAME OR user_email <> '' OR ip_hmac <> ''`). The last condition is what makes a second pass a no-op that writes no record. `BATCH_LIMIT` 500 per run; `remaining` says whether more wait. |
| The record goes **first** | A chained `retention_pseudonymized` entry (severity `notice`, object type `privacy`) carrying `retention_days`, `cutoff`, `pseudonymized_rows` and a `compute_residual_hash()` of every row **as it will look afterwards**. If it cannot be written, **not one row changes** — a pseudonymised row whose fingerprint was never recorded reads as tampering for ever, and the original values are gone. Same ordering as the Art. 17 eraser (§8). |
| The update | `user_name` → `NEUTRAL_NAME` (`[Aufbewahrungsfrist abgelaufen]`), `user_email` → `''`, `ip_hmac` → `''`. `context` is deliberately untouched: it is the evidence, and Art. 17 rewrites it per person, on request. |
| Result | `applied`, `candidates`, `remaining`, `record_id`, `cutoff`, `reason` (`retention_off`, `dry_run`, `record_not_written`, or `null`). |

**Why it pseudonymises instead of deleting.** The log is a hash chain: deleting old rows breaks the
linkage check at the first survivor and the tip count at the end, so `verify_chain()` would report
the site's own retention policy as tampering — the worst possible outcome for an evidence log.
Art. 5(1)(e) limits how long *personal data* is kept, not how long the fact that an event happened
is kept, so the row and its chain position stay and the entry keeps verifying through the residual
fingerprint (§4). `wp sentinel retention [--dry-run]` runs or previews it.

---

## 15. Known drift (documented, not fixed)

- Whether 90 days is the right window is open; that it is *applied* is no longer drift (§14).
- `languages/`: `wm-activity-sentinel.pot` is extracted from the code via `wp i18n make-pot` (`tests/test-pot-freshness.php`), and all 28 `.po`/`.mo` locale pairs are compiled with complete translations and 0 double-encoded strings.
- The backup hook names were not checked against UpdraftPlus or Duplicator.
- `tsa_timestamp` is an unused column.

---

## 16. Tests

```bash
php tests/test-suite.php        # standalone, mocked wpdb, options, transients, hooks, mail, HTTP, cron, REST and WP-CLI classes; 17 groups
bash tests/run-mutations.sh     # 122 mutations, control run first; exit 0 only when every one makes the suite report a failure
# inside WordPress
wp eval-file wp-content/plugins/wm-activity-sentinel/tests/test-i18n.php --allow-root
# these write to the log and refuse to run without WM_SENTINEL_SCRATCH_INSTALL=1; scratch installs only:
WM_SENTINEL_SCRATCH_INSTALL=1 wp eval-file wp-content/plugins/wm-activity-sentinel/tests/test-sentinel-privacy-tools.php --allow-root   # temporary users, erasure records stay in the chain
WM_SENTINEL_SCRATCH_INSTALL=1 wp eval-file wp-content/plugins/wm-activity-sentinel/tests/test-sentinel-sensors.php --allow-root        # appends test entries
WM_SENTINEL_SCRATCH_INSTALL=1 wp eval-file wp-content/plugins/wm-activity-sentinel/tests/test-sentinel-chain.php --allow-root          # TRUNCATE
WM_SENTINEL_SCRATCH_INSTALL=1 wp eval-file wp-content/plugins/wm-activity-sentinel/tests/test-sentinel-tamper-evidence.php --allow-root # TRUNCATE
```

`test-sentinel-privacy-tools.php` creates two temporary users (`@wm-sentinel-it.test`), runs the exporter and eraser through their registered callbacks, posts telemetry as a subscriber and as a visitor through the REST server, checks the audit before, after and at the end, and deletes the users; its fixture, erasure and telemetry entries stay in the chain, as every entry does. The four WordPress suites that write to the log refuse to run without `WM_SENTINEL_SCRATCH_INSTALL=1` and exit 1 when an assertion fails (chain, sensors and tamper-evidence exited 0 until 2026-09-15); `test-i18n.php` loads `wp-load.php` itself when run outside WP-CLI. CI runs the two standalone files and the mutation check on PHP 8.2 and 8.3 plus `.github/scripts/security-audit.py`.

---

## 17. Security disclosure

See [SECURITY.md](SECURITY.md).

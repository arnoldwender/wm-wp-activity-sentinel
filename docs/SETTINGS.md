# Every setting this plugin reads

25 options. The canonical list is
[`WM_Sentinel_Database::default_options()`](../includes/class-sentinel-database.php#L31-L66) —
activation and the factory reset write exactly these values, and nothing else is read anywhere
in the plugin.

All of them are set on **Sentinel › Einstellungen** (`admin.php?page=wm-sentinel-settings`,
capability `manage_options`). They can also be set with WP-CLI:

```bash
wp option update wm_sentinel_logging_intensity strict
wp option get wm_sentinel_siem_enabled
```

Values written through the screen are validated against the lists below; values written with
`wp option update` are not. An invalid value falls back to the default at read time, so the
worst case is the default, not a crash.

Measured 2026-09-16:

```bash
grep -c "wm_sentinel_" includes/class-sentinel-database.php   # the default table
grep -rn "get_option( 'wm_sentinel" includes/ | wc -l         # every read site
```

---

## What gets written to the log

### `wm_sentinel_logging_intensity` — `all`

The severity floor. Each level keeps its own severities and everything above:

| Value | Keeps |
|---|---|
| `all` (default) | `info`, `notice`, `warning`, `critical` |
| `standard` | `notice`, `warning`, `critical` |
| `strict` | `warning`, `critical` |
| `critical_only` | `critical` |

Entries in `ALWAYS_RECORDED` — the genesis entry, erasure records and retention records — ignore this
setting entirely, because a chain with a hole in it cannot be verified. The integrity monitor's report
does not: every level but `all` drops the `info` entry of a clean scan.
([class-sentinel-logger.php:264-278](../includes/class-sentinel-logger.php#L264-L278))

### `wm_sentinel_telemetry_intensity` — `all`

The same idea for the browser tracker: `all`, `actions_only`, `critical_only`.

### The five source switches — all `1`

Each drops one family of events before it reaches the chain.

| Option | Silences |
|---|---|
| `wm_sentinel_log_auth` | logins, logouts, failed attempts |
| `wm_sentinel_log_users` | user created, changed, deleted, role changed |
| `wm_sentinel_log_plugins` | plugin and theme activation, deactivation, update |
| `wm_sentinel_log_content` | posts and pages created, edited, deleted |
| `wm_sentinel_log_rest` | REST requests |

`wm_sentinel_log_plugins` filters object type `file`. File-integrity findings deliberately
carry object type `integrity` instead, so switching plugin logging off does **not** silence a
modified `wp-includes`.

### The three view switches — all `1`

| Option | Stops recording |
|---|---|
| `wm_sentinel_monitor_screens` | which admin screen a signed-in user opened |
| `wm_sentinel_monitor_frontend_views` | which public page a signed-in user opened |
| `wm_sentinel_monitor_ui_interactions` | menu clicks, tab switches, button presses, download clicks |

These three record the browsing behaviour of identified people. Whether
logging page views and clicks of signed-in users is permissible on a given site depends on the
operator's legal assessment, and it is a works-council matter in Germany
(§ 87 Abs. 1 Nr. 6 BetrVG) as well as a DSGVO one. They default to on.

---

## Retention

### `wm_sentinel_retention_days` — `90`

Applied daily since 2026-09-17. Entries older than this window lose their identifying fields:
`user_name`, `user_email` and `ip_hmac`. **Nothing is deleted.**

```bash
wp sentinel retention --dry-run    # what a pass would change
wp sentinel retention              # run it now; the daily event does the same
```

**Why it neutralises instead of deleting.** The log is a hash chain: every row carries the
`entry_hash` of the row before it, and the recorded tip carries the count. Deleting old rows
breaks the linkage check at the first survivor and the tip count at the end — the audit would
report the site's own retention policy as tampering, which is the worst possible outcome for an
evidence log. Art. 5(1)(e) limits how long **personal data** is kept, not how long the fact that
an event happened is kept, so the row and its chain position stay.

The changed rows keep verifying through the same residual-fingerprint mechanism the Art. 17
eraser uses: each pass writes a chained `retention_pseudonymized` record listing a keyed
fingerprint of every row it changed. `wp sentinel audit` counts them as *pseudonymisiert* and
still detects any later edit of them.

| Behaviour | Value |
|---|---|
| Below 30 (the form's minimum) | keep everything — the daily event is unscheduled |
| 30 – 3650 | applied nightly |
| Rows per pass | at most 500; the rest wait for the next run |
| Skipped always | `genesis_initialized`, `privacy_erasure_applied`, `retention_pseudonymized` — the chain's own scaffolding |

**`context` is deliberately left alone.** It is the evidence of what happened, and blanking it
would destroy the record this plugin exists to keep. Where a context field holds a person's data
and that person asks for erasure, the Art. 17 path rewrites it — per person, on request, which is
what the right actually is.

If the record cannot be written, **not one row is changed**: a pseudonymised row whose fingerprint
was never recorded would read as tampering for ever, and the original values are gone.

## Alerts

| Option | Default | Meaning |
|---|---|---|
| `wm_sentinel_alert_email` | the site's `admin_email` | where mail alerts go; `sanitize_email` on save |
| `wm_sentinel_alert_min_severity` | `critical` | floor for sending: `critical`, `warning` or `notice` |
| `wm_sentinel_webhook_url` | `''` | `POST`ed a JSON body per alert; empty means no webhook |
| `wm_sentinel_enable_dashboard_widget` | `1` | the widget on `index.php` |
| `wm_sentinel_delete_data_on_uninstall` | `0` | when `0` (default), deleting the plugin keeps `wp_wm_activity_logs`, `wp_wm_activity_alerts`, `wm_sentinel_chain_tip` and the sealed FIM baseline; when `1`, `uninstall.php` drops both tables and deletes those audit-state options |

---

## SIEM forwarding

Off until a collector is configured. Read by
[`WM_Sentinel_Forwarder`](../includes/class-sentinel-forwarder.php).

| Option | Default | Values |
|---|---|---|
| `wm_sentinel_siem_enabled` | `0` | `0` / `1` |
| `wm_sentinel_siem_format` | `cef` | `syslog` (RFC 5424), `cef` (CEF:0), `leef` (LEEF:2.0) |
| `wm_sentinel_siem_transport` | `https` | `udp`, `tcp`, `https` |
| `wm_sentinel_siem_host` | `''` | hostname or IP — used by `udp` and `tcp` |
| `wm_sentinel_siem_port` | `514` | clamped to 1-65535 on save |
| `wm_sentinel_siem_url` | `''` | endpoint — used by `https` |
| `wm_sentinel_siem_min_severity` | `warning` | `info`, `notice`, `warning`, `critical` |

`host` + `port` apply to `udp` and `tcp`; `url` applies to `https`. Setting a transport without
its destination forwards nothing.

These seven were readable by the forwarder but **no screen wrote them** until 2026-09-16: the
feature existed and was reachable only through `wp option update`, which is not a feature an
operator has. They are on the settings screen now.

Forwarding sends log entries to a host you nominate. That is an outbound transfer of personal
data (user ids, IP addresses) and, if the collector is outside the EU, a third-country transfer
that needs its own legal basis.

---

## File integrity monitor

| Option | Default | Meaning |
|---|---|---|
| `wm_sentinel_fim_enabled` | `1` | the daily scan; `WM_Sentinel_FIM::sync_schedule()` follows this switch, so turning it off also unschedules the cron event |
| `wm_sentinel_fim_scan_suite` | `1` | also check the `wm-*` plugins against a baseline sealed into the chain |

Core files are compared against `api.wordpress.org/core/checksums/1.0/`. The only thing that
leaves the site is the version and locale in a query string — what WordPress already sends on
every update check.

The catalogue is chosen by the **installed package** (`$wp_local_package`), never by
`get_locale()`. Using the display language produced three false findings on a clean tree the
first time the monitor ran for real.

A scan that could not reach the API reports `warning` with `checked = 0`, never `info`. An
unknown version answers HTTP 200 with `"checksums": false`, which is the same case.

---

## Options earlier versions wrote that nothing reads

`WM_Sentinel_Database::LEGACY_OPTIONS` names eight: `wm_sentinel_monitor_rest`,
`wm_sentinel_monitor_files`, `wm_sentinel_monitor_exports`, `wm_sentinel_modules`,
`wm_sentinel_logging_level`, `wm_sentinel_daily_salt`, `wm_sentinel_salt_date`,
`wm_sentinel_settings`. They are deleted on uninstall and on the factory reset. If you find one
on a site, it is a leftover and setting it changes nothing.

Not options, but stored: `wm_sentinel_chain_tip` (the chain head mirror) and the FIM baseline
and catalogue transients.

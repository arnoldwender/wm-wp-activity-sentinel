# WM Activity Sentinel — Developer Guide

> **Target audience:** developers who maintain the plugin or log their own events into it.  
> **Repository:** `wm-activity-sentinel` · **Plugin header:** 1.3.4 · **Author:** Arnold Wender  
> Measured against the code on 2026-09-15. The previous edition documented methods that do not exist (`prune_logs()`, `get_total_count()`, `seed_genesis_block()`, a `WM_Sentinel_Privacy` class), sensors on `save_post`, `before_delete_post` and `wp_logout`, a 60-second alert throttle, HTML alert mails, a `list --format` option and an erasure that replaced data with salted hashes "for GoBD compliance". The method-by-method reference is [DEVELOPERS.md](../DEVELOPERS.md); this guide explains the design and how to work with it.

---

## Table of contents

1. [What the plugin is for, and what it is not](#1-what-the-plugin-is-for-and-what-it-is-not)
2. [Lifecycle of an entry](#2-lifecycle-of-an-entry)
3. [The chain and its verification](#3-the-chain-and-its-verification)
4. [Concurrency](#4-concurrency)
5. [IP addresses and personal data](#5-ip-addresses-and-personal-data)
6. [Logging your own events](#6-logging-your-own-events)
7. [Gotchas](#7-gotchas)
8. [WP-CLI](#8-wp-cli)
9. [Language catalogs](#9-language-catalogs)
10. [Tests](#10-tests)

---

## 1. What the plugin is for, and what it is not

Audit plugins that write plain rows share four weaknesses; this plugin addresses them as follows.

| Weakness | What Sentinel does | Limit |
| --- | --- | --- |
| Anyone with database access can edit or delete rows unnoticed. | Every row carries an HMAC over its content and its predecessor's hash; the newest hash and the row count are mirrored in `wp_options`. | The key is `AUTH_KEY` in `wp-config.php`. Whoever can read that file can re-sign everything, and the mirror lives in the same database. It detects edits by others, not by the server's operator. |
| Concurrent writes fork the chain. | The newest row is locked with `SELECT … FOR UPDATE` inside a transaction. | Needs InnoDB and a database that honours the lock. |
| Raw IP addresses are stored. | A daily-keyed HMAC of the address is stored instead. | The hash is a pseudonym: with the salt and a candidate address it can be recomputed for any stored date. |
| Exported CSV cells run as spreadsheet formulas (CWE-1236). | Cells starting with `=`, `+`, `-`, `@`, tab or carriage return get a leading apostrophe. | Only the CSV export; the JSON export is not signed. |

It does not prevent anything and it timestamps nothing externally. It **deletes** nothing on its own — but that is not the same as doing nothing with the retention window: since 2026-09-17 a daily pass neutralises the identifying fields of entries past `wm_sentinel_retention_days` and leaves the row and its chain position standing, because removing one would make the audit report the site's own retention policy as tampering (`DEVELOPERS.md` §14). It makes no legal assessment of the logging, its retention or its use as evidence.

---

## 2. Lifecycle of an entry

```text
WordPress hook / telemetry POST / your own call
        │
        ▼
WM_Sentinel_Sensors callback  (builds event type, object type, severity, context)
        │
        ▼
WM_Sentinel_Logger::log()
  1. level and switch checks (skipped for genesis_initialized, privacy_erasure_applied
     and retention_pseudonymized — the chain's own scaffolding)
  2. user name and e-mail, daily IP HMAC, canonical context JSON, UTC time
  3. START TRANSACTION
  4. SELECT newest row FOR UPDATE → prev_hash (or GENESIS)
  5. entry_hash = HMAC-SHA256( prev_hash | sorted JSON of the eleven fields, key )
  6. INSERT
  7. update option wm_sentinel_chain_tip { hash, count }
  8. COMMIT
  9. warning / critical → WM_Sentinel_Alerts::evaluate_and_dispatch()
        │
        ▼
wp_wm_activity_logs  ─── read by ───►  WM_Sentinel_Integrity::verify_chain()
                                         (integrity screen, widget, WP-CLI, JSON export, hub)
```

A failed step 4 (no row and `$wpdb->last_error` set) or step 6 rolls back and `log()` returns `false`; a failed `COMMIT` returns `false` without alerts. Until 2026-09-15 a failed lock query chained the entry to `GENESIS`, and the `COMMIT` result was ignored. The option write in step 7 is not checked. Alerts go out after the commit, so a failed mail never loses an entry; `WM_Sentinel_Logger::mute_alerts( true )` suppresses them (the demo import uses it).

---

## 3. The chain and its verification

### 3.1 Hash input

```php
$payload = [
	'context'     => $canonical_context,   // JSON with recursively sorted keys
	'created_at'  => $timestamp,           // UTC, Y-m-d H:i:s
	'event_type'  => $event_type,
	'ip_hmac'     => $ip_hmac,
	'object_id'   => $object_id ?: null,
	'object_type' => $object_type,
	'prev_hash'   => $prev_hash,
	'severity'    => $severity,
	'user_email'  => $user_email,
	'user_id'     => $user_id ?: null,
	'user_name'   => $user_name,
];
ksort( $payload );
$entry_hash = hash_hmac( 'sha256', $prev_hash . '|' . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $key );
```

`$key` is `AUTH_KEY` when it is defined and not empty, otherwise `wp_salt( 'auth' )`. `WM_Sentinel_Logger::chain_key_source()` returns `wp-config`, `wp-config-placeholder` (AUTH_KEY is the `wp-config-sample.php` phrase: it still signs, but the key is public) or `database`; the integrity screen, the CLI and the hub (`DEGRADED`) report the last two.

### 3.2 What verification detects

1. **An edited row:** its recomputed hash no longer matches.
2. **A deleted row in the middle:** the next row's `prev_hash` points at a hash that is no longer there.
3. **Inserted rows:** a valid hash needs the key.
4. **Deleted newest rows:** the mirrored tip has a different hash or a higher count than the chain.
5. **An emptied table:** the tip counted rows that are gone.

### 3.3 Pseudonymised rows

The WordPress eraser changes name, address, IP hash and context of rows, which makes their own hashes fail. Before changing them it appends a chained `privacy_erasure_applied` entry whose context lists, per row id, `compute_residual_hash()` of the row as it will look afterwards (an HMAC over all columns including `entry_hash` and `log_id`, prefixed `erasure|`). **The daily retention pass works the same way** and writes a `retention_pseudonymized` entry with the same kind of fingerprint list (`DEVELOPERS.md` §14). Verification accepts a failing row only when one of those two entry types, with a higher `log_id`, lists it and the fingerprint still matches; the result counts such rows in `pseudonymized`. Without the retention case the audit would report the site's own retention policy as tampering. A later edit of an erased row changes the fingerprint and is reported like any other edit; a forged erasure entry fails its own hash first.

### 3.4 What removes entries legitimately

Only two paths restart the chain, and both are visible (the count drops to one genesis entry): the factory reset and removing demo data while no real entries exist. **The retention window removes nothing** — it neutralises identifying fields and leaves the row and its chain position standing, for the reason in §3.3. There is no method that re-signs existing rows; the one that existed until 1.3.0 (`rebuild_chain()`) let the demo-data button launder a hand edit.

---

## 4. Concurrency

Without a lock, two requests read the same newest row and write two entries with the same `prev_hash`; verification then reports a break at the second one.

```sql
START TRANSACTION;
SELECT * FROM wp_wm_activity_logs ORDER BY log_id DESC LIMIT 1 FOR UPDATE;
-- compute the hash
INSERT INTO wp_wm_activity_logs (...) VALUES (...);
-- update wm_sentinel_chain_tip in wp_options
COMMIT;
```

The tip is written before `COMMIT`, while the lock is held. Until 2026-09-15 it was written after the commit, so two requests could store their tips in the wrong order and a request that died in between left the tip one entry behind; the next audit then reported removed entries that were never removed.

---

## 5. IP addresses and personal data

`WM_Sentinel_Logger::generate_ip_hmac( $ip )`:

$$\text{HMAC-SHA256}(\text{IP},\ \text{gmdate('Y-m-d')} \,\|\, \text{NONCE\_SALT})$$

with `wp_salt( 'nonce' )` when `NONCE_SALT` is not defined. Hashes of one address match within a UTC day, so failed logins of one source can be grouped; they differ across days. The address comes from `REMOTE_ADDR`.

Personal data in the log: actor id, name and e-mail; usernames of failed logins and user agents; login and e-mail of new users; admin screens, frontend addresses and referrers of signed-in users; labels and targets of their clicks; old and new values of monitored options. The exporter and eraser, what the eraser keeps, and the open decisions are described in [DSGVO-COMPLIANCE.md](DSGVO-COMPLIANCE.md).

---

## 6. Logging your own events

Call the logger from your plugin or theme. The class exists only while Sentinel is active.

```php
/**
 * Example: record when an invoice of your own plugin is cancelled.
 * `my_plugin_invoice_cancelled` is a hook your code fires, not a WordPress hook.
 */
add_action( 'my_plugin_invoice_cancelled', function ( int $invoice_id, string $reason ) {
	if ( ! class_exists( 'WM_Sentinel_Logger' ) ) {
		return;
	}

	WM_Sentinel_Logger::log(
		get_current_user_id(),
		'invoice_cancelled',   // event type
		'invoice',             // object type
		$invoice_id,
		'warning',             // info | notice | warning | critical
		[ 'reason' => $reason ]
	);
}, 10, 2 );
```

Things to know:

* An object type that no switch covers is always logged, whatever the switches say; only the logging level applies. Use one of the existing object types (`plugin`, `user`, `export`, …) if a switch should control your entries.
* `warning` and `critical` entries can send alert mails. Choose the severity with that in mind; there is no throttle.
* Do not put secrets or more personal data than needed into `context`: it is stored as is, shown in the stream, exported, and mailed with alerts. The eraser replaces only the exact e-mail address.
* `log()` returns the new id or `false` (filtered out, or the write failed).
* Extensibility hooks: `do_action( 'wm_sentinel_ready', $core )`, `apply_filters( 'wm_sentinel_monitored_options', $options )`, `apply_filters( 'wm_sentinel_public_metrics', $metrics )`, inter-plugin listeners `wm_activity_sentinel_log_event` and `wm_sentinel_security_incident`, WP 6.5+ Block Bindings source `wm-sentinel/metrics` and GenerateBlocks 2.x Dynamic Tag `wm_sentinel_metric`. There is no tamper-event hook because verification runs on demand rather than per query.

---

## 7. Gotchas

### 7.1 WP-CLI and the frontend view sensor

In `wp eval-file`, `is_admin()` is true, so `frontend_page_viewed` skips the request. The suites define `WM_SENTINEL_TESTING_MODE` to test it. `wp eval-file` also runs the file inside a method: counters need `$GLOBALS`, and a `declare(strict_types=1)` at the top is a fatal error.

### 7.2 Changing `AUTH_KEY`

Every existing entry then fails verification: the hashes were made with the old key. Rotating salts (for example after an incident) needs a decision about the old log first.

### 7.3 Character set

The hash is computed over the PHP string before the insert. If the table's character set cannot store a character (a 3-byte `utf8` table and an emoji in the context), the entry is either refused on insert (`log()` returns `false`) or stored with different text and then fails verification; which of the two happens was not measured here. `create_tables()` uses `$wpdb->get_charset_collate()`; keep the database on `utf8mb4`.

### 7.4 Reverse proxies

Behind Cloudflare or a reverse proxy, `REMOTE_ADDR` is the proxy's address unless the web server rewrites it (`mod_remoteip`, `ngx_http_realip_module`). The plugin does not read forwarded headers.

### 7.5 Verification cost

`verify_chain()` reads the whole table into memory on every call: the dashboard widget, the integrity screen, the JSON export and every hub health or telemetry request. Large logs make these slow.

### 7.6 Global gitignore and `assets/`

On macOS a global gitignore with `/Assets/` also matches `assets/`. The repository's `.gitignore` re-includes `!assets/` and `!assets/**`; keep those lines.

---

## 8. WP-CLI

Registered in `includes/class-sentinel-cli.php` only when WP-CLI runs:

```bash
wp sentinel audit                               # verify the chain; exit 1 when broken
wp sentinel list [--limit=20] [--severity=critical]   # table of the newest entries
wp sentinel demodaten --import                  # append up to 15 entries marked _is_demo, no alert mails
wp sentinel demodaten --delete                  # refused while real entries exist; otherwise resets the log
wp sentinel demodaten --reset [--yes]           # empty both tables, new chain, default settings
wp sentinel fim [--baseline]                    # core files against api.wordpress.org; --baseline seals the suite manifest
wp sentinel retention [--dry-run]               # apply the retention window now, or only count what a pass would change
```

---

## 9. Language catalogs

Source strings are German; the text domain is `wm-activity-sentinel`, loaded on `init` from `languages/`. The directory holds 28 `.po`/`.mo` pairs: `ar`, `bg_BG`, `cs_CZ`, `da_DK`, `de_DE`, `el`, `en_GB`, `en_US`, `es_ES`, `et`, `fi`, `fi_FI`, `fr_FR`, `ga`, `hr_HR`, `hu_HU`, `it_IT`, `lt_LT`, `lv`, `mt_MT`, `nl_NL`, `pl_PL`, `pt_PT`, `ro_RO`, `sk_SK`, `sl_SI`, `sv_SE`, `tr_TR`. `tests/test-pot-freshness.php` verifies that every translatable string in the code has an entry in `languages/wm-activity-sentinel.pot`, and all 28 `.po`/`.mo` catalogs carry complete translations with zero double-encoding. Do not edit `.po` files by hand.

---

## 10. Tests

```bash
php tests/test-suite.php        # standalone; every fix has an assertion
bash tests/run-mutations.sh     # undoes each fix once; the suite must report a failure every time
```

The mutation harness counts a run as red only when the suite reaches its results line with at least one failure, and the control run must reach it with none: a test file that leaves PHP mode early prints its own source and exits 0 without running anything. A fix comes with an assertion that fails without it and a mutation in `tests/run-mutations.sh`. The WordPress suites and their side effects are listed in [DEVELOPERS.md](../DEVELOPERS.md#16-tests).

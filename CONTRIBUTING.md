# Contributing to WM Activity Sentinel

Thank you for your interest in contributing to **WM Activity Sentinel**.

---

## Zero Support & Issue Scope

**Zero technical, configuration, or private support is provided.** See [SUPPORT.md](SUPPORT.md):

- Report reproducible code defects **exclusively** via public [GitHub Issues](https://github.com/arnoldwender/wm-wp-activity-sentinel/issues).
- **Do not** contact the author privately via email, `arnoldwender.com`, or direct messages — private inquiries are never answered.
- Issues and pull requests carry no response or maintenance SLA, and the software is provided strictly **"AS IS"** without warranty or liability.

---

## Core Development Standards

1. **PHP 8.2+ with strict types:** every shipped PHP file starts with `declare(strict_types=1);` and a direct-execution guard.
2. **Self-hosted assets:** no CDNs, remote fonts or third-party scripts at runtime.
3. **The chain is append-only:** no code path may re-sign, reorder or delete single entries. The only ways to restart the chain are the factory reset and removing demo data from a log without real entries, and both are visible. Values that change the hash input change `compute_entry_hash()` and every stored entry with it.
4. **Measured statements only:** no legal conformity verdicts, no immutability claims, no emoji in the interface, mails or docs. `README.md` and `DEVELOPERS.md` are measured against the code; keep them that way.
5. **CSV cells** go through `WM_Sentinel_Exporter::sanitize_csv_cell()`.
6. **Accessibility:** labelled fields, visible focus, decorative icons with `aria-hidden="true"`, touch targets of at least 44 px, colours from the family tokens, no inline styles.

---

## Development Workflow

```bash
git clone https://github.com/arnoldwender/wm-wp-activity-sentinel.git
cd wm-activity-sentinel

php tests/test-suite.php        # standalone suite (CI)
bash tests/run-mutations.sh     # every fix must turn the suite red when undone
python3 .github/scripts/security-audit.py

# inside WordPress
wp eval-file wp-content/plugins/wm-activity-sentinel/tests/test-sentinel-privacy-tools.php --allow-root
```

A fix comes with an assertion that fails without it, and with a mutation in `tests/run-mutations.sh`. Never write a closing PHP tag inside a `//` comment of a test file: PHP leaves PHP mode there, and the file prints itself and exits 0.

---

## Commit Conventions

- `[Feature] …`, `[Fix] …`, `[Security] …`, `[Docs] …`; the body explains why.
- User-visible changes get an entry under `[Unreleased]` in `CHANGELOG.md`. The header version is not bumped in a pull request.
- Do not edit the `.po` catalogs by hand.

For support and liability rules, read [SUPPORT.md](SUPPORT.md).

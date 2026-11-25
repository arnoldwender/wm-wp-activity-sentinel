# Security Policy — WM Activity Sentinel

## Supported Versions

| Version | Supported |
| --- | --- |
| `1.4.x` (`1.4.3`) | Current release branch |

---

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities, and **do not** send private inquiries or support emails to `arnoldwender.com`, email addresses, or social/direct messages (private channels are unmonitored for open-source plugins).

Submit security reports **exclusively** via GitHub Private Vulnerability Reporting:

- **Security Advisories:** [github.com/arnoldwender/wm-wp-activity-sentinel/security/advisories/new](https://github.com/arnoldwender/wm-wp-activity-sentinel/security/advisories/new)

> **Best-Effort Only / Zero Warranty / Zero Support:** This open-source repository carries **zero technical or user support** ([SUPPORT.md](SUPPORT.md)) and **zero response-time SLA**. Advisory reports may be reviewed at the author's sole discretion without obligation, timeline, or liability ("AS IS" under [LICENSE](LICENSE)).

### What to include

1. Reproduction steps or a proof of concept.
2. Affected component (REST route `POST /wm-sentinel/v1/telemetry`; chain writing or verification; WordPress privacy exporter or eraser; admin screens and exports; WP-CLI `wp sentinel`; hub adapter).
3. Impact as you see it.

### Response

Reports are acknowledged as soon as they are read and handled by one person; there is no guaranteed response time. Reporters are credited unless they ask otherwise.

### Surfaces worth knowing

- The chain proves nothing against someone who can read `wp-config.php` (`AUTH_KEY`) or, when `AUTH_KEY` is missing, the database. A report that re-signs the chain with the key is expected behaviour; a valid entry forged without the key is a vulnerability.
- `POST /wm-sentinel/v1/telemetry` accepts any signed-in user, including subscribers, and writes up to 60 `info` entries per user per minute.
- The alert webhook is sent with `wp_remote_post()` to a URL an administrator sets.
- What the design does not cover is listed in [docs/SECURITY-AUDIT.md](docs/SECURITY-AUDIT.md).

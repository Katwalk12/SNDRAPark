# SNDRA Park — documentation index

Everything written about this system, and which document answers which question.

SNDRA Park is a parking reservation platform for a multi-floor car park. A driver reserves a bay and gets a barcode pass; the booth teller scans it in and out; the system prices the stay; the administrator watches the same live floor map and reads the reports. All three roles share **one reservation record**, so nothing is reconciled by hand.

---

## The documents

| Document | What it is | Status |
|---|---|---|
| [../README.md](../README.md) | The main guide: install, configure, run, API reference, pricing rules, no-show policy, project structure, troubleshooting. **Start here.** | Current |
| [SECURITY.md](SECURITY.md) | Security architecture: every control, where it lives, what it assumes, and what is still missing. 19 sections. | Current (rev. 2, Sep 17 2026) |
| [../SECURITY_AUDIT_REPORT.md](../SECURITY_AUDIT_REPORT.md) | The March 2026 backend audit that produced most of today's controls. | **Historical — see the warning below** |
| [../SECURITY_AUDIT_QUICK_REFERENCE.md](../SECURITY_AUDIT_QUICK_REFERENCE.md) | The audit's companion: the file-by-file fix list it generated. | **Historical** |
| [screenshots/](screenshots/) | Seven full-page captures used by the README walkthrough. | Current |

### ⚠️ The audit reports describe the system *before* it was fixed

`SECURITY_AUDIT_REPORT.md` opens with "CRITICAL SECURITY ISSUES IDENTIFIED" and states that all admin endpoints are unprotected, there is no CSRF anywhere, and admin passwords are SHA-256. **That was true in March 2026 and is not true now.** Those findings were the work order; the fixes landed, and [SECURITY.md](SECURITY.md) records the result.

Keep the reports — they are the reasoning behind decisions that would otherwise look arbitrary, and they are the audit trail. Just never quote them as the current state of the system. When the two disagree, SECURITY.md is right.

---

## Find it by what you are trying to do

**Set the project up**
→ [README § Installation](../README.md#installation), then [§ Configuration](../README.md#configuration). Requires XAMPP with PHP 8.2 and MySQL.

**Understand the reservation → scan → payment loop**
→ [README § Demonstration](../README.md#demonstration) walks all six steps with screenshots. [§ Reservation lifecycle](../README.md#reservation-lifecycle) has the state transitions.

**Work out what a stay costs, or why a bay expired**
→ [README § How pricing works](../README.md#how-pricing-works) and [§ No-show policy](../README.md#no-show-policy). The no-show rules are also in [SECURITY § 14](SECURITY.md#14-application-level-abuse-controls), since the four-strike lock is an abuse control.

**Call the API**
→ [README § API reference](../README.md#api-reference). Note that state-changing calls need a CSRF token — [SECURITY § 6](SECURITY.md#6-csrf-protection) explains how to get one, and which endpoints are exempt.

**Add or change an endpoint**
→ [SECURITY § 5](SECURITY.md#5-authorization) for the authorization gate your family uses, and [§ 6](SECURITY.md#6-csrf-protection) for CSRF. Both are enforced at the router or bootstrap rather than per file, so a new endpoint is covered by default — but read which bootstrap you are inheriting.

**Deploy this somewhere real**
→ [SECURITY § 16](SECURITY.md#16-deployment-requirements). Ten requirements the code does *not* enforce, including TLS, `APP_DEBUG=false`, and a non-root database account. Skipping these silently disables controls that look present in the source.

**Review the security posture**
→ [SECURITY § 15](SECURITY.md#15-known-gaps-and-residual-risk) is the honest gap list, scored and explained. [§ 17](SECURITY.md#17-control-to-code-index) maps every control to the file that implements it.

**Run the tests**
→ [README § Tests and CI](../README.md#tests-and-ci). `php tests/run.php` — it primes the settings cache, so it needs no database.

**Something is broken**
→ [README § Troubleshooting](../README.md#troubleshooting) covers the known traps, including the 403 at the repo root and the broken Tailwind build.

---

## Known documentation gaps

Recorded here rather than left for the next person to discover.

- **The IoT slot-sensor rig is undocumented.** `backend/iot/common.php`, `backend/iot/sensors.php` and `backend/cli/serial-bridge.php` are roughly 1,475 lines with no coverage in any document, and the `SENSOR_*` variables in `.env.example` are explained only by the comments beside them. Anyone picking that up is reading source. Worth a `docs/sensors.md` covering the four bridge modes, the stale-reading fallback, and how live sensing interacts with reservation-derived availability.
- **No database schema reference.** The schema is created and migrated in code (`backend/config/database.php`, `backend/config/db.php`), so the tables are discoverable but never listed in one place.
- **No Content-Security-Policy**, which is gap 1 in [SECURITY § 15](SECURITY.md#15-known-gaps-and-residual-risk) — noted here because the reason it is still open is a documentation-shaped problem: it needs a page-by-page audit of inline scripts and CDN loads before a policy can be written safely.

---

## Conventions

- Markdown, one document per subject, linked rather than duplicated. Where two documents touch the same rule, one owns it and the other links across.
- `SECURITY.md` carries a revision line and a remediation record ([§ 19](SECURITY.md#19-remediation-record--september-17-2026)). Changes to security controls are recorded there, with the verification that backs them.
- Dates are absolute. "Recently" ages badly in a document nobody re-reads.

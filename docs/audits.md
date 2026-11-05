# CarbonGrader — Audits

> History of performance, technical-debt, and security checks.
> Append-only. See `.cursor/rules/001-audits.mdc` for what to record and when.

---

## 2026-11-05 — P5.4 security and docroot audit

Code review of the running app, plus status-code checks against `https://grader.byintention.co/` (headers and paths only; denied responses were HTML error pages, not file bodies). No Critical or High issues.

Skipped as not applicable: accounts, passwords, sessions, and role checks (the grade API is public; the control is the per-IP rate limit). SQL injection (no database). `npm audit` (no installed runtime packages; PHPUnit is dev-only in `composer.json`). Vendored CO2.js 0.18.0 and UIKit CSS 3.21.6 are pinned files, so a package audit would not see them.

### Findings

- **Secrets — none found.** Tracked files have no AWS, GitHub, Stripe, or private-key patterns. `.env` is gitignored; `.env.example` has empty values. The PageSpeed key is read in `src/api/config.php` and placed only on the server-side PageSpeed URL. `src/js/config.js` is poll timing and the API path. History and saved results are whitelisted and tested to drop extra fields such as `apiKey`.
- **SSRF — guarded; this server does not fetch the submitted URL.** `Http` is only used for the fixed Greencheck and PageSpeed hosts, with TLS verification on. `UrlValidator` allows http(s) only, rejects credentials, non-default ports, and names over 2048 characters, and rejects hosts that are or resolve to loopback, private, link-local, CGNAT (`100.64.0.0/10`), or IPv4-mapped IPv6 of those ranges. Covered in `tests/UrlValidatorTest.php` (23 cases) and `tests/phpunit/SecurityTest.php`. Residual, accepted: DNS is checked before the URL is handed to Google, and Google resolves it again. A redirect from the PageSpeed or Greencheck host would be followed (up to 5 hops). The API key sits in the PageSpeed query string; a cross-host redirect does not resend that query, and the host is not attacker-controlled.
- **Injection — none found.** No `innerHTML`, `eval`, or shell execution. Dynamic UI text goes through `textContent` (`src/js/ui.js`). Job, result, cache, lock, and rate-limit filenames are either `^[a-f0-9]{8,64}$` or a SHA-256 of the key. `src/router.php` requires `realpath` to stay under `src/`.
- **Docroot — live site matches the intended layout.** Requests for `/config/scoring.json`, `/var/`, `/tests/UrlValidatorTest.php`, `/composer.json`, and `/package.json` returned 404. `/router.php`, `/api/config.php`, and `/api/lib/` returned 403, which matches `src/.htaccess`, `src/api/.htaccess`, and `src/api/lib/.htaccess`. The homepage is CarbonGrader, so the vhost root is `src/`. Dotfile URLs such as `/.env` returned a 787-byte HTML 403, the same shape as `/.htaccess`, which is the host denying dot paths rather than serving the file.
- **Headers — present on the live host.** Homepage and `GET /api/grade.php` send CSP (`default-src 'self'`, no `unsafe-inline`), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, and `Referrer-Policy: strict-origin-when-cross-origin`. No `Access-Control-Allow-Origin`. The host also sends `Strict-Transport-Security: max-age=300`. The same CSP and frame/nosniff/referrer headers are in `src/router.php` and `src/.htaccess`. The HTML meta policy omits `frame-ancestors` because a meta tag cannot set it; the header does.
- **Data exposure — handled on the paths that return JSON.** Upstream failures store a fixed sentence (`GradeService::publicUpstreamMessage`); provider text such as `project_number` is not returned. That is tested. Saved and cached poll payloads go through `ResultStore::publicPayload`. Share links are 128-bit job ids (`random_bytes(16)`), with no list endpoint. Submitted URLs are sent to Google and the Green Web Foundation; the footer says so. Rate-limit files store a hash of the client IP, not the IP.
- **Low — uncaught PHP errors can still print a path.** `grade.php` maps the known error codes and does not attach stack traces itself. Constructors and `Fs` throw `RuntimeException` with a directory path on failure, and nothing catches that. A host with `display_errors` on would show the path and a stack trace. The live API's normal 400 response did not.
- **Low — rate-limit race.** `RateLimiter::hit` reads, increments, and writes without a lock. Overlapping requests can exceed the per-minute cap. Enough for v1 quota protection, not a hard ceiling.
- **Low — the id check is wider than the ids we mint.** `JobStore::isValidId` allows 8 to 64 hex characters. New ids are 32 hex characters. Poll and saved-result reads are unauthenticated, which is fine at 128 bits and would not be fine at 32 bits.
- **Low — the limit is per TCP peer.** `grade.php` uses `REMOTE_ADDR` and does not trust `X-Forwarded-For`. That avoids spoofed bypasses. If a reverse proxy is the only peer, every visitor shares one bucket.

### Actions taken

- Recorded this review. No application code changed.

### Follow-ups

- Wrap `grade.php` so an unexpected exception returns the existing generic JSON 500 and no filesystem path.
- Keep the live document root on `src/`. The deploy workflow uploads the whole repo to `/grader.byintention.co/`; a vhost pointed at that directory would expose `.env` and `var/`. There is no deny rule at the repo root.
- Optionally require job ids to be 32 hex characters, and lock the rate-limit read-modify-write if the cap must be exact.
- Confirm whether live traffic shares one `REMOTE_ADDR`. Raise the host HSTS `max-age` above 300 seconds if the site stays HTTPS-only. That header is set by the host, not by this repo.

## 2026-10-27 — Dark mode contrast
- **Findings:** System dark mode left several UIKit colors in place. Card titles (`#333` on `#18211c`, about 1.3:1), muted text (`#999` on white, about 2.8:1), the danger alert (`#f0506e` on `#fef4f6`, about 3.2:1), the green hosting label (`#fff` on `#32d296`, about 1.9:1), and the B badge (`#fff` on `#6aa84f`, about 2.9:1) failed WCAG AA. The brand green `#2e8b57` is 4.25:1 on white, short of 4.5:1 for normal text, so links use `#1a7a45` (5.4:1) in light and `#9ddeb8` (about 10:1) in dark. The primary button keeps `#2e8b57` with white text; at 1.5rem bold it is large text (3:1 required).
- **Actions taken:** Theme colors are custom properties. `data-theme` selects the palette; `prefers-color-scheme` still applies when no choice is saved. The choice is `cg-theme` in `localStorage` (not a secret). Input borders in both themes are at least 3:1 against the field.
- **Follow-ups:** A full keyboard and screen-reader pass is still part of P5.1. Light-mode list dividers stay softer than 3:1 (`#d5ddd8`); they are separators, not the only way to read the rows.

## 2026-10-27 — UIKit stylesheet vendored
- **Findings:** The only runtime CDN asset was UIKit 3.21.6 CSS from jsDelivr. `style-src` allowed that host. The minified file is 271 KB and self-contained (22 `url()` values, all data URIs). No UIKit JS is loaded.
- **Vendored:** `uikit` **3.21.6** → `src/assets/css/vendor/uikit.min.css`, pinned in `src/assets/css/vendor/VERSION`. Downloaded from jsDelivr; no npm install.
- **Actions taken:** `index.html` links the local file. Content-Security-Policy in `index.html`, `src/router.php`, and `src/.htaccess` is `style-src 'self'` only.
- **Follow-ups:** None. Re-download the pinned file if UIKit is upgraded.

## 2026-10-27 — Saved result reads
- **Findings:** A new public read, `GET /api/grade.php?result=<id>`, loads a JSON file whose name comes from the query string. The id is checked against `^[a-f0-9]{8,64}$` before it is used as a filename, so it cannot leave `var/results/`. The stored document is a whitelist of the grade payload (no IP, no API key). There is no list endpoint. Files stay outside the docroot.
- **Actions taken:** Covered bad ids and secret-stripping in `ResultStoreTest.php` and `GradeServiceTest.php`.
- **Follow-ups:** A retention sweep by file age is intentionally not built yet. Do not describe share links as permanent.

## 2026-06-10 — Phase 0 dependency audit
- **Findings:** First vendored asset added. No package managers used to install
  anything (no `node_modules`, no `package-lock.json`, no Composer deps yet).
- **Vendored:** `@tgwf/co2` **0.18.0** → `src/js/vendor/co2.js` (309 KB, prebuilt
  ESM from jsDelivr), pinned in `src/js/vendor/VERSION`. Verified self-contained
  (no external runtime imports); exports `co2`.
- **Actions taken:** Confirmed no build step introduced; CO2.js loads as a static
  ES module. Added `/vendor/` (Composer) and `/var/*` to `.gitignore`; kept
  `.env.example` while ignoring `.env*`.
- **Follow-ups:**
  - When Composer dev deps (PHPUnit) land, re-audit and justify each.
  - Re-verify SWD grade bands against CO2.js 0.18.0's model before launch.

## 2026-06-09 — Initial scaffold audit
- **Findings:** New repository. No runtime dependencies. No secrets present.
- **Actions taken:** Established `.cursorignore` to keep build artifacts and
  `node_modules` out of context; documented no-build-system policy in
  `050-tooling.mdc`.
- **Follow-ups:**
  - Decide on a static dev server / linter choice (dev-only).
  - Add an a11y + contrast audit once the UI exists.

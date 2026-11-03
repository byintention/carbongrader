# CarbonGrader — Audits

> History of performance, technical-debt, and security checks.
> Append-only. See `.cursor/rules/001-audits.mdc` for what to record and when.

---

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

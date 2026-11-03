# Changelog

All notable changes to CarbonGrader are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/) and this
project adheres to [Semantic Versioning](https://semver.org/).
See `.cursor/rules/200-changelog.mdc` for the workflow.

## [Unreleased]

### Changed
- 2026-11-03 — The header title and strapline are centered on screens narrower than 720px.
- 2026-11-03 — Intention links carry only a source: `carbongradercta` on the contact button and `carbongraderfooter` on the footer credit.
- 2026-11-03 — The contact panel uses UIKit card-body padding.
- 2026-11-03 — Clear history uses the UIKit danger button.
- 2026-11-03 — Headings no longer use `uk-card-title`. "Analyze a URL" and "Results" use the normal heading size.
- 2026-11-03 — Recent grades uses the same divider line as the other section headings. Heading tags no longer use `uk-h4`.
- 2026-11-03 — The share link has a "Share this result" heading above the form.
- 2026-11-03 — The results panel opens with a centered Results heading. The analyzed URL sits under that heading.
- 2026-11-03 — Recent grades sit below the results, with a spacer between the two panels.
- 2026-11-03 — The share copy button uses the UIKit primary button.
- 2026-11-03 — Clear history uses the UIKit secondary button.
- 2026-11-03 — Validation errors show a cross icon, centered on the left of the message.
- 2026-11-03 — The green-hosting card wraps the host name and "Checks this host only." in `.cg-host-detail`.
- 2026-11-03 — The URL field and Grade button sit on one row. Errors and the working status render inside that form card.
- 2026-11-03 — Resource breakdown file counts sit in their own span (`.cg-resource-count`) so they can be styled apart from the byte size.
- 2026-11-03 — Score breakdown uses the same progress bars as the resource breakdown, with sentence-case component names.
- 2026-11-03 — Site title and strapline sit on the left of the header. The theme toggle stays on the right on wider screens.
- 2026-10-27 — UIKit 3.21.6 stylesheet is served from
  `src/assets/css/vendor/uikit.min.css` instead of jsDelivr. `style-src` is
  `'self'` only.

### Added
- 2026-11-03 — When a grade has recommendations, "What to improve" ends with a contact panel: why page speed matters, and a link to Intention.
- 2026-11-03 — Manual live deploy workflow (`.github/workflows/deploy-live.yml`).
  Running it from the Actions tab uploads the repo to `/grader.byintention.co/`
  over FTPS. `.env` stays on the server.
- 2026-10-27 — Dark mode toggle in the header. The page follows the system
  color scheme until a light or dark choice is saved in this browser.
  Secondary text, links, alerts, and the B grade badge stay readable in both themes.
- 2026-10-27 — Saved grade snapshots: each finished grade is stored as one JSON
  file under `var/results/` and can be reopened with `?r=<id>` without calling
  PageSpeed again. The share field uses that link. A URL-and-strategy query
  still starts a new grade. Full suite is 110.
- 2026-10-14 — Recommendation mapping (P4.1): deterministic tips from hosting,
  page weight, and the top PageSpeed opportunities, shown on the results
  dashboard and sorted by impact. A failed Greencheck no longer suggests
  switching hosts. 9 offline tests; full suite is 91.
- 2026-07-07 — Phase 3 UIKit frontend: URL form with mobile/desktop strategy,
  async poll client with backoff, and a results dashboard (EcoGrade, carbon,
  hosting, performance, page weight, resource breakdown). UIKit 3.21.6 via CDN;
  custom `app.css` with dark mode and grade colours.
- 2026-07-07 — Phase 2 carbon model and scoring (JS): `carbon.js` wraps vendored
  CO2.js `perVisit()` and maps grams to SWD carbon bands; `score.js` computes
  the weighted composite EcoGrade (0–100 + letter) from the PHP result payload
  using `scoring-config.js` (mirrors `config/scoring.json`). 17 offline Node
  tests (`CarbonTest.mjs`, `ScoreTest.mjs`); `npm test` runs PHP + JS suites (82).
- 2026-07-07 — Async grading endpoint (P1.5), completing the PHP backend
  (Phase 1): `GradeService` orchestrates validate → rate-limit → cache → job
  create/poll with a single-flight run-on-poll; `config.php` loads `.env` (no
  dependency) and wires a production analyzer that fetches Greencheck + PageSpeed
  in parallel (`Http::getMany`), treating green hosting as best-effort and
  PageSpeed as required; `grade.php` is a thin HTTP wrapper (`POST` create /
  `GET ?id=` poll) with error-code→HTTP-status mapping. 10 offline
  `GradeService` tests plus an HTTP smoke test.
- 2026-07-07 — PHP storage layer (P1.4): file-based `Cache` (TTL, self-expiring),
  `JobStore` (async job lifecycle + `flock` single-flight lock per URL), and
  `RateLimiter` (fixed-window, hashed identifiers), on a shared `Fs` helper
  (atomic writes). Offline tests with an injectable clock (14 cases).
- 2026-07-07 — PHP `PageSpeedClient` (P1.3): calls PageSpeed Insights v5 and
  returns total bytes, performance score, a per-type resource breakdown
  (reconstructed from `network-requests`), and prioritized improvement
  opportunities. Handles PSI runtime errors, quota (429), and API errors with
  typed exceptions. Offline tests against crafted fixtures
  (`tests/PageSpeedClientTest.php`, 11 cases).
- 2026-07-07 — PHP `GreencheckClient` (P1.2): checks green-hosting status via
  Green Web Foundation Greencheck v3, returning `{green, hostedBy}`. Added a
  shared `Http` cURL helper and typed `UpstreamException`. Offline tests against
  fixtures (`tests/GreencheckClientTest.php`, 7 cases).
- 2026-07-07 — PHP `UrlValidator` (P1.1): validates/normalizes URLs, builds a
  canonical cache key (drops `www.`, fragment, trailing slash; keeps query), and
  blocks SSRF by rejecting hosts that are or resolve to private/loopback/
  link-local/CGNAT addresses. Ships with a dependency-free offline test runner
  (`tests/UrlValidatorTest.php`, 23 cases).
- 2026-06-10 — Phase 0 scaffold for the real app: removed the trip-estimator
  starter; created the target layout (`src/api/lib/`, `src/assets/css/`,
  `src/js/vendor/`, out-of-docroot `config/` + `var/`, `tests/fixtures/`); added
  `config/scoring.json` and `config/recommendations.json` (provisional),
  `.env.example`, and vendored CO2.js 0.18.0 (`src/js/vendor/co2.js`, pinned).
  `package.json` `start` now runs `php -S localhost:8000 -t src`.
- 2026-06-09 — Product plan and technical architecture for an Ecograder-style
  website carbon-grading app (CO2.js + Greencheck v3 + PageSpeed Insights v5;
  PHP/JS backend, UIKit frontend): see `docs/architecture.md` and the phased
  roadmap in `docs/planning.md`.
- 2026-06-09 — Initial repository scaffold: `.cursor/rules/` workflow rules,
  `docs/` (planning, audits, changelog), `src/` starter web app, `.cursorignore`,
  and `package.json`.

### Fixed
- 2026-10-14 — Grade failures stay visible. The submit handler was clearing the
  status panel immediately after rendering an error.

### Changed
- 2026-10-27 — Carbon letter bands now use the Sustainable Web Design v4
  percentiles from CO2.js 0.18 (A+ ≤ 0.04 g CO₂e/visit through E ≤ 0.359 g).
  The previous table was the v3 scale, so ordinary pages were graded A or A+.
- 2026-10-27 — Local dev server is fixed on port 8020 (`npm start` / `npm run serve`).
- 2026-06-10 — Pre-build gap review resolved 4 blockers and refined the design:
  carbon now uses CO2.js `perVisit` (matches the per-visit bands); the API is
  async (job + poll) to absorb the slow PageSpeed call; CO2.js is vendored and
  version-pinned (no runtime CDN); and a concrete (provisional) scoring scheme
  was added. Also pinned down URL canonicalization, docroot isolation, privacy,
  and attribution. See `docs/architecture.md` and `docs/planning.md`.
- 2026-06-09 — `docs/planning.md` rewritten from the scaffold placeholder into
  the real product roadmap (the trip-estimator starter in `src/` is now slated
  for removal in task P0.2).

## [0.1.0] - 2026-06-09
### Added
- Project initialized.

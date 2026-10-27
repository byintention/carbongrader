# CarbonGrader — Planning

> Single source of truth for the roadmap, the current task, and the MECE
> breakdown. Workflow rules: `.cursor/rules/000-planning.mdc`.
> Technical design: `docs/architecture.md`.

**Status legend:** `[ ]` TODO · `[~]` IN PROGRESS · `[x]` DONE · `[!]` BLOCKED

---

## Vision

An Ecograder-style web app: a user submits a public URL and receives an
**EcoGrade (A+–F)** for the page's sustainability, built from three open data
sources:

1. **CO2.js** (Green Web Foundation) — carbon per page view from page weight.
2. **Greencheck API v3** — whether the host runs on green energy.
3. **PageSpeed Insights v5** — page weight, resource breakdown, performance.

Stack: **PHP** backend (orchestration + secrets + caching), **JS/CO2.js** for
the carbon model, **UIKit 3** frontend. **No build system** (Prepros only).

## Current task

- [x] **Phase 0 complete** (2026-06-10) — scaffold repurposed, config + env
  added, CO2.js 0.18.0 vendored & pinned. Verified no packages installed.
- [x] **P1.1** done (2026-07-07) — `UrlValidator` (canonicalize + SSRF guard),
  23 offline unit tests passing.
- [x] **P1.2** done (2026-07-07) — `GreencheckClient` + shared `Http`/
  `UpstreamException`, 7 offline tests passing (fixtures).
- [x] **P1.3** done (2026-07-07) — `PageSpeedClient` (bytes/score/resources/
  opportunities), 11 offline tests passing (fixtures).
- [x] **P1.4** done (2026-07-07) — `Cache` + `JobStore` (single-flight lock) +
  `RateLimiter` + shared `Fs`, 14 offline tests.
- [x] **P1.5** done (2026-07-07) — async endpoint: `GradeService` + `config.php`
  + `grade.php`, parallel upstreams via `curl_multi`. **Phase 1 complete.**
- [x] **Phase 2 complete** (2026-07-07) — `carbon.js` (perVisit + bands) and
  `score.js` (composite EcoGrade). 17 JS tests; full suite **82**.
- [x] **Phase 3 complete** (2026-07-07) — UIKit shell, poll client, results
  dashboard (`index.html`, `api.js`, `main.js`, `ui.js`, `app.css`).
- [x] **Phase 4 complete** (2026-10-14) — recommendation mapping on the results
  dashboard. Full suite **91**.
- [~] **P5.1** — Accessibility, dark mode polish, reduced motion, equal-height metric cards.

> Run the app: `npm start` → open `http://localhost:8000/`. Live grading needs
> `PAGESPEED_API_KEY` in `.env`. Full suite: `npm test` (91 tests).

> Decisions locked (2026-06-09): browser-side CO2.js · single-URL scope for v1.
> Decisions locked (2026-06-10): carbon via CO2.js `**perVisit`** · **async
> job+poll** API · CO2.js **vendored + version-pinned** · scoring scheme in
> architecture §6.2 adopted as **provisional** seed values (tunable).

---

## Roadmap (phased, MECE)

Each task lists **acceptance criteria** and the **files** it touches. Subtasks
within a phase are mutually exclusive; phases are ordered by dependency.

### Phase 0 — Foundation & decisions  ✅ (2026-06-10)

- [x] **P0.1 Lock architecture decisions** (2026-06-09)
  - Decided: browser-side CO2.js; single-URL v1 scope; §6 bands/weights approved.
  - Files: `docs/architecture.md`.
- [x] **P0.2 Repurpose scaffold**
  - AC: remove the trip-estimator starter (`src/index.html`, `src/css/`,
  `src/js/main.js`, `src/js/score.js`); create target folders from
  architecture §8, incl. `src/js/vendor/` and out-of-docroot `config/`, `var/`.
  No app logic yet.
  - Files: `src/**`, `config/`, `var/`.
- [x] **P0.3 Config files & env**
  - AC: `config/scoring.json` (band points, weights, efficiency budget, letter
  cutoffs from §6.2), `config/recommendations.json`, root `.env.example`
  created; `.env` + `var/` confirmed gitignored; `package.json` `start` =
  `php -S localhost:8000 -t src`.
  - Files: `config/*`, `.env.example`, `.gitignore`, `package.json`.
- [x] **P0.4 Vendor CO2.js** — pinned `@tgwf/co2` 0.18.0 ESM at
  `src/js/vendor/co2.js` (curl from jsDelivr, no npm); version in
  `src/js/vendor/VERSION` + `docs/audits.md`.

### Phase 1 — PHP backend API

- [x] **P1.1 URL validation + canonicalization + SSRF guard** (2026-07-07)
  - Done: `UrlValidator::validate()` returns `{url, host, canonicalKey}`;
  canonical key lowercases host, drops `www.`/fragment/trailing slash, keeps
  query; rejects non-http(s), credentials, non-default ports, and hosts that
  are/resolve to private/loopback/link-local/CGNAT/mapped-v6 addresses
  (injectable resolver for offline tests). 23 tests pass via
  `php tests/UrlValidatorTest.php`.
  - Files: `src/api/lib/UrlValidator.php`, `src/api/lib/ValidationException.php`,
  `tests/UrlValidatorTest.php`.
- [x] **P1.2 Greencheck client** (2026-07-07)
  - Done: `GreencheckClient::check($host)` → `{green, hostedBy}`; injectable
  transport; throws typed `UpstreamException` (UPSTREAM/TIMEOUT) on non-200,
  invalid JSON, or transport failure. Added shared `Http` (curl GET) and
  `UpstreamException`. 7 tests pass via `php tests/GreencheckClientTest.php`.
  - Files: `src/api/lib/GreencheckClient.php`, `src/api/lib/Http.php`,
  `src/api/lib/UpstreamException.php`, `tests/GreencheckClientTest.php`,
  `tests/fixtures/greencheck_{green,grey}.json`.
- [x] **P1.3 PageSpeed client** (2026-07-07)
  - Done: `PageSpeedClient::analyze($url, $strategy)` → `{totalBytes,
  performanceScore, resources[], opportunities[]}`. Extracts
  `total-byte-weight` (fallback: sum of network requests), performance score,
  per-type totals rebuilt from `network-requests`, and opportunity audits
  (zero-savings filtered, sorted desc). Typed errors: `PSI_ANALYSIS_FAILED`
  (runtimeError/missing LHR), `RATE_LIMITED` (429), `UPSTREAM` (API error/bad
  JSON/invalid strategy). 11 tests via `php tests/PageSpeedClientTest.php`.
  - Files: `src/api/lib/PageSpeedClient.php`, `tests/PageSpeedClientTest.php`,
  `tests/fixtures/pagespeed_{example,runtime_error}.json`.
- [x] **P1.4 Cache + job store + rate limiter** (2026-07-07)
  - Done: file `Cache` (TTL, self-expiring, injectable clock); `JobStore`
  (create/get/update lifecycle, id validation, `flock`-based per-URL
  single-flight lock); `RateLimiter` (fixed 60s window, hashed identifiers);
  shared `Fs` (atomic write + JSON helpers). 14 tests
  (`Cache`/`JobStore`/`RateLimiter`Test).
  - Files: `src/api/lib/Cache.php`, `src/api/lib/JobStore.php`,
  `src/api/lib/RateLimiter.php`, `src/api/lib/Fs.php`, `tests/CacheTest.php`,
  `tests/JobStoreTest.php`, `tests/RateLimiterTest.php`.
- [x] **P1.5 `grade.php` endpoint (async)** (2026-07-07)
  - Done: logic in `GradeService` (create + poll, single-flight run-on-poll,
  cache incl. strategy in key, `refresh` bypass, rate limit on create, typed
  errors). `config.php` = minimal `.env` loader + factory + production analyzer
  (parallel Greencheck+PSI via `Http::getMany`; Greencheck best-effort/`verified`
  flag, PSI failures fatal). `grade.php` = thin HTTP wrapper (code→HTTP status,
  `Retry-After`, safe JSON, no stack traces). 10 `GradeService` tests + HTTP
  smoke test.
  - Files: `src/api/grade.php`, `src/api/config.php`,
  `src/api/lib/GradeService.php`, `src/api/lib/Http.php` (+`getMany`),
  `src/api/lib/GreencheckClient.php` (+`endpointFor`), `tests/GradeServiceTest.php`.
  - Note: `Json.php` from the original plan was unnecessary — JSON emission lives
  in `grade.php`'s `cg_emit()`.

### Phase 2 — Carbon model & scoring (JS)  ✅ (2026-07-07)

- [x] **P2.1 CO2.js wrapper**
  - Done: `estimateGramsPerVisit()` via vendored CO2.js `perVisit()`;
  `carbonBandForGrams()` + `analyzeCarbon()` map to SWD bands from config.
  7 tests via `node tests/CarbonTest.mjs`.
  - Files: `src/js/carbon.js`, `src/js/scoring-config.js`.
- [x] **P2.2 Composite EcoGrade**
  - Done: pure `gradeResult()` combining carbon/perf/hosting/efficiency per
  `scoring.json` weights → composite 0–100 + letter; sub-score helpers exported.
  10 tests via `node tests/ScoreTest.mjs`.
  - Files: `src/js/score.js`, `tests/CarbonTest.mjs`, `tests/ScoreTest.mjs`.

### Phase 3 — Frontend (UIKit)  ✅ (2026-07-07)

- [x] **P3.1 App shell + URL form + poll client**
  - Done: UIKit 3.21.6 via CDN; accessible URL form with mobile/desktop strategy;
  `api.js` POST create + poll with exponential backoff (2–8s, 120s timeout);
  `main.js` orchestrates loading (~30s message), error, and abort on re-submit.
  - Files: `src/index.html`, `src/js/main.js`, `src/js/api.js`, `src/js/config.js`.
- [x] **P3.2 Results dashboard**
  - Done: `ui.js` renders composite EcoGrade badge, carbon/visit + band, perf,
  page weight, green-host badge (incl. unverified), resource breakdown bars,
  component score breakdown; all dynamic text via `textContent`.
  - Files: `src/js/ui.js`, `src/assets/css/app.css`.

### Phase 4 — Recommendations  ✅ (2026-10-14)

- [x] **P4.1 Recommendation mapping** (2026-10-14)
  - Done: `mapRecommendations()` reads `config/recommendations.json` (mirrored
  in `recommendations-config.js`, because `config/` is outside the docroot).
  A grey-host tip requires a verified Greencheck. Image, script, font, and
  page-weight rules use strict thresholds; the weight tip lists the heaviest
  resource types. The top 3 PageSpeed opportunities become individual tips,
  ranked from `savingsMs`. The dashboard shows them under "What to improve",
  highest impact first. 9 offline tests (`RecommendationsTest.mjs`).
  - Also fixed: grade failures stay on screen. The submit handler was clearing
  the status panel in `finally`, which wiped the error just rendered.
  - Files: `src/js/recommendations.js`, `src/js/recommendations-config.js`,
  `src/js/ui.js`, `src/js/main.js`, `src/assets/css/app.css`,
  `config/recommendations.json`, `tests/RecommendationsTest.mjs`, `package.json`.

### Phase 5 — Polish, hardening & docs

- **P5.1 A11y + dark mode + reduced motion** (WCAG AA; charts as accessible
SVG/UIKit progress, no chart lib). Files: `src/assets/css/app.css`, `src/index.html`, `src/js/ui.js`.
- **P5.2 Result history + shareable link** (localStorage, no sensitive data). Files: `src/js/*`.
- **P5.3 Tests** (PHPUnit via Composer + browser JS spec runner + fixtures). Files: `tests/`**.
- **P5.4 Security + docroot audit** (CSP, SSRF cases, secret hygiene, rate
limits, deny non-public dirs) → log in `docs/audits.md`.
- **P5.5 Legal & attribution** (distinct branding vs. Ecograder™; credits for
Google PSI, Green Web Foundation, CO2.js, UIKit; PSI/GWF ToS check; privacy
note). Files: `src/index.html`, `docs/audits.md`.
- **P5.6 Changelog + version bump** per `200-changelog.mdc`.

---

## Decisions log

- **2026-06-09** — Carbon computed **browser-side** (CO2.js as ES module); no
Node microservice for v1.
- **2026-06-09** — v1 scope is **single URL** (multi-page deferred to backlog).
- **2026-06-10** — Carbon uses CO2.js `**perVisit`** (aligns with the per-visit
A+–F bands), not `perByte`.
- **2026-06-10** — API is **async (job + poll)** to absorb the 10–30s PSI call;
job runs inline on first poll with a per-URL single-flight lock.
- **2026-06-10** — CO2.js is **vendored + version-pinned** in `src/js/vendor/`
(no runtime CDN), for reproducible grades.
- **2026-06-10** — Scoring scheme (architecture §6.2) adopted as **provisional**
seed values (headline = composite 0–100; carbon band as sub-metric); tunable.

## Open questions (still to resolve)

- **Deploy host** — must run PHP 8.1+ with a writable `var/`; Prepros does not
serve PHP. (P5.4)
- **PSI quota/rate limits** — confirm key tier (~25k/day, ~240/min) → final
cache TTLs + per-IP limits. (P1.4)
- **Scoring tuning** — finalize weights, band points, efficiency budget, and
letter cutoffs before launch (desktop may need a separate budget). (P2.2)
- **Query-string canonicalization policy** — keep vs. strip for cache key. (P1.1)
- `**.env` loading** — real env vars vs. minimal PHP parser vs. a dotenv dep. (P0.3)
- **UIKit** — load from CDN or vendor it too (CSP/offline). (P3.1)
- **Privacy** — IP hashing + URL/log retention policy. (P5.5)

## Backlog / ideas

- Compare two URLs side by side.
- Historical tracking / trend per URL.
- Carbon budget badges to embed.
- Per-country grid intensity via `perVisitTrace` options.
- Save results to flat file or database and allow sharing with querystring/id


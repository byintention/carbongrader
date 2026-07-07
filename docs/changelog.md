# Changelog

All notable changes to CarbonGrader are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/) and this
project adheres to [Semantic Versioning](https://semver.org/).
See `.cursor/rules/200-changelog.mdc` for the workflow.

## [Unreleased]

### Added
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

### Changed
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

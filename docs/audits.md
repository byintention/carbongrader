# CarbonGrader — Audits

> History of performance, technical-debt, and security checks.
> Append-only. See `.cursor/rules/001-audits.mdc` for what to record and when.

---

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

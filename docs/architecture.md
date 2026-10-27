# CarbonGrader — Architecture

> Technical design for an Ecograder-style web app that grades the sustainability
> of a public web page. Read this with `docs/planning.md` (the task roadmap).
> No build system (see `.cursor/rules/050-tooling.mdc`).

---

## 1. Product summary

User submits a public URL. The app analyses the page and returns:

- An overall **EcoGrade** (A+–F) and a 0–100 score.
- **Carbon per page view** (g CO₂e), via CO2.js.
- **Green hosting** status (Green Web Foundation).
- **Page weight** and resource breakdown (PageSpeed Insights / Lighthouse).
- **Performance score** (Lighthouse performance category).
- **Actionable recommendations** to reduce footprint.

## 2. Tech stack (constrained)

| Layer | Choice | Notes |
|-------|--------|-------|
| Backend API | **PHP 8.1+** | Async job orchestration, secret handling, caching, SSRF guard. Dev server: `php -S`. |
| Carbon model | **CO2.js** (`@tgwf/co2`) in **JS** | SWD model via **`perVisit()`** (matches the per-visit bands). **Vendored + version-pinned** ES module in `src/js/vendor/` (no CDN at runtime, no bundler). |
| Frontend | **UIKit 3** | Loaded via CDN (CSS + JS + icons). Custom CSS overrides only. |
| Data — perf/weight | **PageSpeed Insights API v5** | Server-side (API key is secret). |
| Data — hosting | **Green Web Foundation Greencheck API v3** | Server-side, no key required. |
| Dev tooling | Prepros (optional Sass), Composer for PHP dev deps | No bundlers/transpilers for shipped JS. |

### Why this split ("PHP/JS backend")

- **PHP** owns everything that must not run in the browser: the PageSpeed API
  key, outbound HTTP, SSRF validation, rate limiting, and response caching.
- **JS (CO2.js)** owns the carbon math + grading. CO2.js is a JS library; running
  it as a browser ES module keeps us bundler-free and avoids a Node runtime.

> **Decisions locked (2026-06-10):**
> - Carbon is computed **browser-side** with CO2.js **`perVisit(bytes, green)`**
>   (per-visit metric aligns with the §6.1 bands).
> - Because PSI takes 10–30s, the API is **async (job + poll)**, not a single
>   blocking request — see §3 and §5.
> - CO2.js is **vendored and version-pinned** in `src/js/vendor/` for
>   reproducible grades and to avoid a runtime CDN dependency.
> - *Alternative not taken:* a Node microservice wrapping CO2.js (only needed if
>   we later require server-authoritative scores).

## 3. High-level data flow (async job + poll)

PSI is slow (10–30s), so the request that triggers analysis returns immediately
with a job id; the browser polls for completion. A cache hit short-circuits to
`done` on creation.

```
[Browser/UIKit]
   │  (1) POST /api/grade  { url, strategy }
   ▼
[PHP API]
   ├─ validate + normalize URL (SSRF guard, public http/https only)
   ├─ cache hit? → create job already "done" with cached result
   ├─ else create job "queued", acquire per-URL lock (single-flight)
   └─ return { id, status }                      ◀── fast response
   │
   │  (2) worker runs the job (see §3.1) — Greencheck + PSI via curl_multi
   │
   │  (3) GET /api/grade?id=…   (browser polls every ~2s, with backoff)
   ▼
[PHP API] → { status: queued|running|done|error, result? }
   ▼  (on done)
[Browser/JS: carbon.js]
   ├─ CO2.js perVisit(totalBytes, green) → g CO₂e / visit
   ├─ map grams → carbon band (§6.1); score.js → composite EcoGrade (§6.2)
   └─ ui.js renders dashboard (UIKit)
```

### 3.1 How the job is executed (no queue infra)

To stay infra-light on shared/PHP hosting (no Redis/RabbitMQ required):

- **Default:** the job runs **inside the first poll** — the `GET ?id=` handler
  performs the upstream calls (with timeouts) and writes the result to the job
  store. A per-URL lock prevents duplicate upstream calls (cache stampede).
- The slow work happens in a poll request the browser already expects to wait on
  briefly; subsequent polls just read status. This avoids a separate worker
  process while keeping the create request instant.
- *Upgrade path:* swap the inline runner for a real worker (cron/queue) without
  changing the client contract.

## 4. External API contracts (verified)

### 4.1 PageSpeed Insights v5
- `GET https://www.googleapis.com/pagespeedonline/v5/runPagespeed`
- Query: `url`, `key=PAGESPEED_API_KEY`, `category=performance`, `strategy=mobile|desktop`.
- Fields we use:
  - Total transfer bytes: `lighthouseResult.audits["total-byte-weight"].numericValue`
  - Performance score (0–1): `lighthouseResult.categories.performance.score`
  - Resource breakdown: parse `lighthouseResult.audits["network-requests"].details.items`
    (each item has `transferSize`, `resourceType`, `mimeType`).
    > `resource-summary` was **removed in Lighthouse 11** — reconstruct
    > per-type totals from `network-requests`.
  - Opportunities/diagnostics: audits with `details.type === "opportunity"` for tips.
- Notes: slow (10–30s) and quota-limited → **must cache**; key is secret.

### 4.2 Greencheck v3
- `GET https://api.thegreenwebfoundation.org/api/v3/greencheck/{hostname}`
- `{hostname}` = bare host only (no scheme/port/path).
- Response: `{ "green": bool, "hosted_by": string, ... }`. No API key.

### 4.3 CO2.js (vendored + pinned)
```js
import { co2 } from "./vendor/co2.js"; // pinned ESM build committed to the repo
const swd = new co2();                 // SWD model (default)
const grams = swd.perVisit(totalBytes, isGreen); // g CO2e per visit → matches §6.1 bands
```
- **Vendoring:** download a specific CO2.js ESM release into
  `src/js/vendor/co2.js` and record the exact version in `docs/audits.md` (and a
  `vendor/VERSION` note). Do **not** load from a CDN at runtime.
- **Why `perVisit`:** the §6.1 A+–F thresholds are per-visit values, so `perVisit`
  (not `perByte`) is the correct method. PSI gives empty-cache (first-visit)
  bytes; `perVisit` applies the model's first/return-visit accounting.
- Use `perVisitTrace(bytes, green, options)` later for per-country grid intensity.

## 5. Internal API contract (PHP → frontend, async)

### 5.1 Create a job
`POST /api/grade` body `{ "url": "...", "strategy": "mobile|desktop", "refresh": false }`
→ `201` `{ "ok": true, "id": "abc123", "status": "queued" }`
(or `"status": "done"` immediately on a cache hit). `refresh: true` bypasses cache.

### 5.2 Poll a job
`GET /api/grade?id=abc123`
- While working: `{ "ok": true, "id": "abc123", "status": "queued|running" }`
- On success: `{ "ok": true, "id": "abc123", "status": "done", "result": { … } }`
- On failure: `{ "ok": true, "id": "abc123", "status": "error", "error": { … } }`

### 5.3 `result` payload
```jsonc
{
  "url": "https://example.com/",
  "host": "example.com",
  "strategy": "mobile",
  "fetchedAt": "2026-06-10T11:00:00Z",
  "cached": false,
  "hosting": { "green": true, "hostedBy": "Example Cloud" },
  "page": {
    "totalBytes": 1843200,
    "performanceScore": 0.82,
    "resources": [
      { "type": "image",  "bytes": 980000, "count": 14 },
      { "type": "script", "bytes": 420000, "count": 9 }
    ]
  },
  "opportunities": [
    { "id": "uses-optimized-images", "title": "Efficiently encode images", "savingsMs": 1200 }
  ]
}
```

### 5.4 Errors
`error.code` ∈ `INVALID_URL | BLOCKED_HOST | UPSTREAM | TIMEOUT | RATE_LIMITED |
PSI_ANALYSIS_FAILED | NOT_FOUND`. Carbon grams and EcoGrade are **not** in the
payload — they are computed client-side via §6 from `result`.

## 6. Scoring & grading (config-driven)

All thresholds/weights live in `config/scoring.json` so they can be tuned
without code changes.

### 6.1 Carbon rating bands (per page view, g CO₂e)
Use Sustainable Web Design rating bands (A+–F). **Verify against the current
SWD methodology before shipping** — seed values to confirm:

| Grade | ≤ g CO₂e/view |
|-------|----------------|
| A+ | 0.095 |
| A  | 0.186 |
| B  | 0.341 |
| C  | 0.493 |
| D  | 0.656 |
| E  | 0.846 |
| F  | > 0.846 |

### 6.2 Composite EcoGrade (0–100) — provisional, tunable in `scoring.json`

> Status: **provisional seed scheme** (2026-06-10). Numbers below are the working
> defaults to start from and are expected to be tuned. The **headline metric is
> the composite 0–100 score**; the carbon band (§6.1) is shown as a sub-metric.

**Component sub-scores (each 0–100):**

| Component | Weight | Sub-score |
|-----------|:------:|-----------|
| Carbon footprint | 50% | from carbon band → points (table below) |
| Performance | 25% | `performanceScore × 100` |
| Green hosting | 15% | green = 100, grey = 0 |
| Resource efficiency | 10% | page-weight budget (below) |

**Carbon band → points:** `A+ 100 · A 92 · B 80 · C 68 · D 55 · E 42 · F 25`

**Resource-efficiency budget (mobile):** full 100 at ≤ 1.0 MB, 0 at ≥ 4.0 MB,
linear in between. `score = clamp(0,100, (4.0 − MB) / (4.0 − 1.0) × 100)`.

**Composite → display letter:** `A+ ≥95 · A ≥85 · B ≥70 · C ≥55 · D ≥40 · E ≥25 · F <25`

> Open for tuning: weights, band points, the efficiency budget, and the letter
> cutoffs (desktop may warrant a different budget). Revisit before launch.

## 7. Recommendations engine

Deterministic mapping from results → tips (no LLM needed):
- Non-green host → suggest green hosting / Green Web Foundation directory.
- Large image bytes → compress / next-gen formats / responsive images.
- Large JS bytes → reduce/treeshake/defer; question heavy frameworks.
- Low perf score → surface top PSI opportunities (`savingsMs` sorted).
- Page weight over budget → list heaviest resource types.
Each tip: title, why it matters, est. impact. Source mapping in `config/recommendations.json`.

## 8. File / folder layout (target)

```
src/
├── index.html                # UIKit shell + form + results container
├── assets/
│   └── css/app.css           # overrides on top of UIKit (Prepros-friendly)
├── js/
│   ├── main.js               # form handling, create+poll job, orchestrate states
│   ├── api.js                # client for the PHP endpoint (POST + poll w/ backoff)
│   ├── carbon.js             # CO2.js perVisit wrapper + grade band logic
│   ├── score.js              # composite EcoGrade calculation (pure, testable)
│   ├── ui.js                 # UIKit render helpers (dashboard, badges, charts)
│   ├── config.js             # client config (strategy default, poll interval)
│   └── vendor/
│       ├── co2.js            # pinned CO2.js ESM build (committed, not from CDN)
│       └── VERSION           # exact @tgwf/co2 version vendored
└── api/                      # PHP backend (served from same docroot)
    ├── grade.php             # POST (create job) + GET ?id= (poll/run)
    ├── config.php            # loads env, exposes config()
    └── lib/
        ├── UrlValidator.php   # normalize/canonicalize + SSRF guard (block private IPs)
        ├── PageSpeedClient.php
        ├── GreencheckClient.php
        ├── JobStore.php       # job lifecycle + per-URL lock (single-flight)
        ├── Cache.php          # file or APCu cache, TTL per source
        ├── RateLimiter.php
        └── Json.php           # safe JSON response + escaping

# Outside the public docroot (NOT under src/):
config/
├── scoring.json              # band points + weights + budgets + letter cutoffs
└── recommendations.json      # result → tip mapping
var/                          # writable, non-public: cache/, jobs/, ratelimit/
.env                          # PAGESPEED_API_KEY, TTLs (gitignored; .env.example committed)
```

> **Docroot/security note:** the web server docroot is `src/`. `config/`, `var/`,
> `.env`, `docs/`, and `tests/` must live **outside** the docroot (or be denied
> by server rules) so they are never web-served. `lib/*.php` must not be
> directly routable. See §11.

## 9. Configuration & secrets

- `.env` (project root, **outside** the `src/` docroot, gitignored) holds
  `PAGESPEED_API_KEY` and TTLs. Commit only `.env.example`. Never expose the key
  to the browser. Loading: prefer real environment variables / a minimal PHP
  parser (avoid extra runtime deps; see open questions).
- `config/scoring.json` and `config/recommendations.json` are safe to commit.

## 10. Caching, jobs & canonicalization

- **Cache key = canonical URL.** Canonicalize before keying/cache: lowercase
  scheme+host, force `https` where applicable, strip default ports and URL
  fragment, drop `www.` consistently, normalize trailing slash, and decide a
  query-string policy (default: keep query, since it can change the page).
- Cache normalized PSI + Greencheck responses. TTL: PSI 6–24h, Greencheck
  24–72h. Serve `cached: true` on hit; `refresh: true` bypasses and re-runs.
- **Single-flight lock** per canonical URL so concurrent requests for the same
  uncached URL trigger only one PSI call (cache-stampede protection).
- **Job store** (`var/jobs/`): tracks `queued|running|done|error`, timestamps,
  and result; reaped after a short retention window.
- Quota guardrails: PSI (with key) ~25k/day and ~240/min — set per-IP rate
  limits and TTLs comfortably under these (confirm in open questions).

## 11. Security (see `.cursor/rules/001-audits.mdc`)

- **SSRF guard:** only allow `http`/`https`, resolve host and reject private/loopback/link-local IP ranges before fetching.
- **Secret hygiene:** PSI key server-side only; never in client JS or responses.
- **Input validation:** strict URL parsing; reject malformed/oversized input.
- **Output:** escape all dynamic content inserted into the DOM (`textContent`, no `innerHTML` with untrusted data).
- **CSP:** strict. UIKit may load from its CDN (or vendor it too); CO2.js is
  vendored, so `script-src` needs **no** `esm.sh`/jsdelivr. No `unsafe-inline`/`eval`.
- **Docroot isolation:** docroot is `src/`; deny web access to `config/`, `var/`,
  `.env`, `docs/`, `tests/`, and `api/lib/` via server rules (`.htaccess`/nginx).
- **Rate limiting:** per-IP on job creation to protect quota (storage in `var/`).
- **Privacy:** hash stored IPs; submitted URLs are sent to Google PSI + GWF —
  state this in a short privacy note; define log retention.

## 12. Error & edge handling

- Typed `error.code` (§5.4) → friendly UIKit message, never a stack trace.
- **Async-specific:** poll timeout / job never completes → surface a retry; show
  an honest "~30s" progress state while `queued|running`; exponential poll backoff.
- **PSI failure modes** map to `PSI_ANALYSIS_FAILED`: `NO_FCP`,
  `ERRORED_DOCUMENT_REQUEST`, robots-blocked, login-walled, heavy redirects,
  non-200 — each gets clear copy.
- **Greencheck:** treat unknown/error as grey (non-green) but label it as
  "couldn't verify" rather than a definitive grey, where possible.
- **Single-host green flag** is an approximation (page pulls bytes from many
  hosts); label it as such in the UI.

## 13. Testing strategy

- **PHP (PHPUnit, dev-only via Composer):** `UrlValidator` (incl. SSRF cases),
  `Cache`, `PageSpeedClient`/`GreencheckClient` against recorded JSON fixtures,
  `grade.php` happy/error paths.
- **JS:** pure functions in `score.js` / `carbon.js` with small assertion specs
  (browser-runnable, no bundler).
- Commit API response fixtures under `tests/fixtures/`.

## 14. Dev & run

- Dev server (PHP needed): `php -S localhost:8020 -t src src/router.php` → open `http://localhost:8020/`. Local serving always uses port **8020**.
- Prepros optionally watches `src/assets/css` (Sass) and reloads.
- No bundler; UIKit + CO2.js load via CDN/ESM.

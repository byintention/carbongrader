/**
 * Recent grades kept in localStorage. No API keys, IPs, or other secrets.
 * The stored payload is the grade result the user already asked to see.
 */

const STORAGE_KEY = "cg.history.v1";
const MAX_ENTRIES = 8;

/**
 * @param {Storage} [storage]
 * @returns {Array<{ savedAt: string, result: object }>}
 */
export function loadHistory(storage = globalThis.localStorage) {
  try {
    const raw = JSON.parse(storage.getItem(STORAGE_KEY) || "[]");
    if (!Array.isArray(raw)) return [];
    return raw.filter(isEntry).slice(0, MAX_ENTRIES);
  } catch {
    return [];
  }
}

/**
 * Remember a result, newest first. The same URL + strategy replaces its older row.
 *
 * @param {object} result
 * @param {Storage} [storage]
 * @returns {Array<{ savedAt: string, result: object }>}
 */
export function rememberResult(result, storage = globalThis.localStorage) {
  const clean = sanitizeResult(result);
  if (!clean) return loadHistory(storage);
  const next = [
    { savedAt: new Date().toISOString(), result: clean },
    ...loadHistory(storage).filter(
      (entry) => !(entry.result.url === clean.url && entry.result.strategy === clean.strategy),
    ),
  ].slice(0, MAX_ENTRIES);
  try {
    storage.setItem(STORAGE_KEY, JSON.stringify(next));
  } catch {
    // Private mode or a full quota: the grade still shows, it just isn't kept.
  }
  return next;
}

/**
 * @param {Storage} [storage]
 */
export function clearHistory(storage = globalThis.localStorage) {
  try {
    storage.removeItem(STORAGE_KEY);
  } catch {
    // Ignore storage failures.
  }
}

/**
 * Query string for a shareable link. Only the public URL and strategy.
 *
 * @param {string} url
 * @param {string} strategy
 */
export function shareQuery(url, strategy) {
  const params = new URLSearchParams();
  params.set("url", url);
  params.set("strategy", strategy === "desktop" ? "desktop" : "mobile");
  return params.toString();
}

/**
 * @param {object} result
 * @param {string} origin
 * @param {string} pathname
 */
export function shareHref(result, origin, pathname) {
  return `${origin}${pathname}?${shareQuery(result?.url ?? "", result?.strategy ?? "mobile")}`;
}

/**
 * @param {unknown} item
 */
function isEntry(item) {
  if (!item || typeof item !== "object") return false;
  const result = /** @type {{ result?: object }} */ (item).result;
  return Boolean(sanitizeResult(result));
}

/**
 * @param {object|null|undefined} result
 */
function sanitizeResult(result) {
  if (!result || typeof result.url !== "string" || result.url.trim() === "") return null;
  const strategy = result.strategy === "desktop" ? "desktop" : "mobile";
  const resources = Array.isArray(result.page?.resources) ? result.page.resources : [];
  const opportunities = Array.isArray(result.opportunities) ? result.opportunities : [];
  return {
    url: result.url,
    host: typeof result.host === "string" ? result.host : "",
    strategy,
    fetchedAt: typeof result.fetchedAt === "string" ? result.fetchedAt : "",
    cached: Boolean(result.cached),
    hosting: {
      green: Boolean(result.hosting?.green),
      hostedBy: typeof result.hosting?.hostedBy === "string" ? result.hosting.hostedBy : null,
      verified: result.hosting?.verified !== false,
    },
    page: {
      totalBytes: finiteNumber(result.page?.totalBytes),
      performanceScore: Number.isFinite(result.page?.performanceScore) ? result.page.performanceScore : null,
      resources: resources.slice(0, 20).map((item) => ({
        type: typeof item?.type === "string" ? item.type : "other",
        bytes: finiteNumber(item?.bytes),
        count: finiteNumber(item?.count),
      })),
    },
    opportunities: opportunities.slice(0, 8).map((item) => ({
      id: typeof item?.id === "string" ? item.id : "",
      title: typeof item?.title === "string" ? item.title : "",
      savingsMs: finiteNumber(item?.savingsMs),
    })),
  };
}

/** @param {unknown} value */
function finiteNumber(value) {
  const number = Number(value);
  return Number.isFinite(number) && number >= 0 ? number : 0;
}

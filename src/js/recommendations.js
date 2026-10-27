/**
 * Deterministic recommendation mapping (architecture §7).
 * Pure: result payload + recommendations config → tips sorted by impact.
 */

const IMPACT_ORDER = { high: 0, medium: 1, low: 2 };

/**
 * @param {object} result API result payload
 * @param {{ rules?: object[] }} config
 * @returns {Array<{
 *   id: string,
 *   title: string,
 *   why: string,
 *   impact: "high"|"medium"|"low",
 *   savingsMs?: number,
 *   heaviest?: Array<{ type: string, bytes: number }>
 * }>}
 */
export function mapRecommendations(result, config) {
  const rules = Array.isArray(config?.rules) ? config.rules : [];
  /** @type {ReturnType<typeof mapRecommendations>} */
  const tips = [];

  for (const rule of rules) {
    if (!rule || !matches(rule.when, result)) continue;
    if (rule.source === "psi") {
      tips.push(...expandOpportunities(rule, result));
      continue;
    }
    const tip = tipFromRule(rule, result);
    if (tip) tips.push(tip);
  }

  tips.sort((a, b) => IMPACT_ORDER[a.impact] - IMPACT_ORDER[b.impact]);
  return tips;
}

/**
 * Every recognised condition must hold. Unknown or empty `when` does not match.
 * A grey-host condition matches only when Greencheck verified the host.
 *
 * @param {object} when
 * @param {object} result
 */
function matches(when, result) {
  if (!when || typeof when !== "object") return false;

  let saw = false;
  const hosting = result?.hosting ?? {};
  const verified = hosting.verified === true;
  const green = hosting.green === true;

  if ("hostingVerified" in when) {
    saw = true;
    if (verified !== Boolean(when.hostingVerified)) return false;
  }

  if ("hostingGreen" in when) {
    saw = true;
    if (when.hostingGreen === false && !verified) return false;
    if (green !== Boolean(when.hostingGreen)) return false;
  }

  if (when.resourceTypeOverKB && typeof when.resourceTypeOverKB === "object") {
    saw = true;
    const type = when.resourceTypeOverKB.type;
    const kb = Number(when.resourceTypeOverKB.kb);
    const resources = result?.page?.resources ?? [];
    const found = resources.find((item) => item && item.type === type);
    if (!found || !Number.isFinite(kb) || found.bytes <= kb * 1024) return false;
  }

  if (typeof when.totalMBOver === "number") {
    saw = true;
    const mb = Number(result?.page?.totalBytes ?? 0) / (1024 * 1024);
    if (!(mb > when.totalMBOver)) return false;
  }

  if ("opportunitiesPresent" in when) {
    saw = true;
    const present = Array.isArray(result?.opportunities) && result.opportunities.length > 0;
    if (present !== Boolean(when.opportunitiesPresent)) return false;
  }

  return saw;
}

/**
 * @param {object} rule
 * @param {object} result
 */
function tipFromRule(rule, result) {
  if (typeof rule.title !== "string" || typeof rule.why !== "string") return null;
  const impact = rule.impact === "high" || rule.impact === "medium" || rule.impact === "low"
    ? rule.impact
    : "low";
  /** @type {ReturnType<typeof mapRecommendations>[number]} */
  const tip = {
    id: String(rule.id ?? rule.title),
    title: rule.title,
    why: rule.why,
    impact,
  };
  if (rule.detail === "heaviestResources") {
    const limit = Number.isFinite(rule.heaviestLimit) ? Math.max(0, Math.floor(rule.heaviestLimit)) : 3;
    const heaviest = heaviestResources(result, limit);
    if (heaviest.length > 0) tip.heaviest = heaviest;
  }
  return tip;
}

/**
 * Turn the top PageSpeed opportunities into individual tips.
 * Impact comes from savingsImpactMs on the rule (high / medium thresholds).
 *
 * @param {object} rule
 * @param {object} result
 */
function expandOpportunities(rule, result) {
  const limit = Number.isFinite(rule.limit) ? Math.max(0, Math.floor(rule.limit)) : 3;
  const bands = rule.savingsImpactMs ?? {};
  const highMs = Number.isFinite(bands.high) ? bands.high : 1000;
  const mediumMs = Number.isFinite(bands.medium) ? bands.medium : 300;
  const why = typeof rule.why === "string" ? rule.why : "";

  return [...(result?.opportunities ?? [])]
    .filter((item) => item && Number(item.savingsMs) > 0 && (item.title || item.id))
    .sort((a, b) => Number(b.savingsMs) - Number(a.savingsMs))
    .slice(0, limit)
    .map((item) => {
      const savingsMs = Math.round(Number(item.savingsMs));
      let impact = "low";
      if (savingsMs >= highMs) impact = "high";
      else if (savingsMs >= mediumMs) impact = "medium";
      return {
        id: `psi-${item.id ?? item.title}`,
        title: String(item.title || item.id),
        why,
        impact,
        savingsMs,
      };
    });
}

/**
 * @param {object} result
 * @param {number} limit
 */
function heaviestResources(result, limit) {
  return [...(result?.page?.resources ?? [])]
    .filter((item) => item && typeof item.type === "string" && Number(item.bytes) > 0)
    .sort((a, b) => Number(b.bytes) - Number(a.bytes))
    .slice(0, limit)
    .map((item) => ({ type: item.type, bytes: Number(item.bytes) }));
}

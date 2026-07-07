import { analyzeCarbon } from "./carbon.js";

/**
 * Clamp a value to [min, max].
 * @param {number} value
 * @param {number} min
 * @param {number} max
 */
export function clamp(value, min, max) {
  return Math.min(max, Math.max(min, value));
}

/**
 * Page-weight efficiency sub-score (0–100). Full score at or below
 * fullScoreMaxMB; zero at or above zeroScoreMinMB; linear between.
 *
 * @param {number} totalBytes
 * @param {{ fullScoreMaxMB: number, zeroScoreMinMB: number }} budget
 */
export function efficiencyScore(totalBytes, budget) {
  const mb = totalBytes / (1024 * 1024);
  const { fullScoreMaxMB, zeroScoreMinMB } = budget;
  if (mb <= fullScoreMaxMB) {
    return 100;
  }
  if (mb >= zeroScoreMinMB) {
    return 0;
  }
  const span = zeroScoreMinMB - fullScoreMaxMB;
  return Math.round(clamp(((zeroScoreMinMB - mb) / span) * 100, 0, 100));
}

/**
 * Green-hosting sub-score (0–100).
 * @param {{ green: boolean }} hosting
 * @param {{ green: number, grey: number }} hostingPoints
 */
export function hostingScore(hosting, hostingPoints) {
  return hosting?.green ? hostingPoints.green : hostingPoints.grey;
}

/**
 * Lighthouse performance sub-score (0–100).
 * @param {number|null|undefined} performanceScore 0–1 from PSI
 */
export function performanceSubScore(performanceScore) {
  if (!Number.isFinite(performanceScore)) {
    return 0;
  }
  return Math.round(clamp(performanceScore * 100, 0, 100));
}

/**
 * Weighted composite score (0–100) from component sub-scores.
 *
 * @param {{ carbon: number, performance: number, hosting: number, efficiency: number }} components
 * @param {{ carbon: number, performance: number, hosting: number, efficiency: number }} weights
 */
export function compositeScore(components, weights) {
  const raw =
    components.carbon * weights.carbon +
    components.performance * weights.performance +
    components.hosting * weights.hosting +
    components.efficiency * weights.efficiency;
  return Math.round(clamp(raw, 0, 100));
}

/**
 * Map a 0–100 composite score to a display letter grade.
 *
 * @param {number} score
 * @param {Array<{grade:string,min:number}>} letterCutoffs highest min first
 */
export function letterForScore(score, letterCutoffs) {
  for (const cutoff of letterCutoffs) {
    if (score >= cutoff.min) {
      return cutoff.grade;
    }
  }
  return "F";
}

/**
 * Grade a full API result payload (architecture §5.3) into an EcoGrade.
 *
 * @param {object} result from grade.php when status is "done"
 * @param {object} config scoring config (see scoring-config.js)
 * @returns {{
 *   gramsPerVisit: number,
 *   carbonBand: string,
 *   carbonPoints: number,
 *   components: { carbon: number, performance: number, hosting: number, efficiency: number },
 *   compositeScore: number,
 *   compositeGrade: string,
 * }}
 */
export function gradeResult(result, config) {
  const totalBytes = result?.page?.totalBytes ?? 0;
  const isGreen = Boolean(result?.hosting?.green);
  const carbon = analyzeCarbon(totalBytes, isGreen, config);

  const components = {
    carbon: carbon.carbonPoints,
    performance: performanceSubScore(result?.page?.performanceScore),
    hosting: hostingScore(result.hosting, config.hostingPoints),
    efficiency: efficiencyScore(totalBytes, config.efficiencyBudgetMB),
  };

  const composite = compositeScore(components, config.weights);

  return {
    gramsPerVisit: carbon.gramsPerVisit,
    carbonBand: carbon.carbonBand,
    carbonPoints: carbon.carbonPoints,
    components,
    compositeScore: composite,
    compositeGrade: letterForScore(composite, config.letterCutoffs),
  };
}

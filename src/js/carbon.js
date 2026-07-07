import { co2 } from "./vendor/co2.js";

/**
 * Estimate carbon per page visit (g CO₂e) using the vendored CO2.js SWD model.
 * Uses perVisit() so results align with the per-visit band thresholds (§6.1).
 *
 * @param {number} totalBytes transfer size from PageSpeed (first-visit bytes)
 * @param {boolean} isGreen whether the host is on green energy
 * @returns {number} grams CO₂e per visit (>= 0)
 */
export function estimateGramsPerVisit(totalBytes, isGreen) {
  const bytes = Number(totalBytes);
  if (!Number.isFinite(bytes) || bytes < 0) {
    throw new Error("totalBytes must be a non-negative number");
  }
  const model = new co2();
  return model.perVisit(bytes, Boolean(isGreen));
}

/**
 * Map a grams-per-visit value to an SWD carbon band and its composite points.
 *
 * @param {number} grams
 * @param {Array<{grade:string,maxGramsPerVisit:number|null,points:number}>} carbonBands
 * @returns {{ grade: string, points: number }}
 */
export function carbonBandForGrams(grams, carbonBands) {
  if (!Number.isFinite(grams) || grams < 0) {
    throw new Error("grams must be a non-negative number");
  }
  for (const band of carbonBands) {
    if (band.maxGramsPerVisit === null || grams <= band.maxGramsPerVisit) {
      return { grade: band.grade, points: band.points };
    }
  }
  const fallback = carbonBands[carbonBands.length - 1];
  return { grade: fallback?.grade ?? "F", points: fallback?.points ?? 25 };
}

/**
 * Full carbon analysis: grams + band + points.
 *
 * @param {number} totalBytes
 * @param {boolean} isGreen
 * @param {{ carbonBands: Array }} config scoring config (carbonBands required)
 * @returns {{ gramsPerVisit: number, carbonBand: string, carbonPoints: number }}
 */
export function analyzeCarbon(totalBytes, isGreen, config) {
  const gramsPerVisit = estimateGramsPerVisit(totalBytes, isGreen);
  const { grade, points } = carbonBandForGrams(gramsPerVisit, config.carbonBands);
  return { gramsPerVisit, carbonBand: grade, carbonPoints: points };
}

/**
 * Browser-side scoring config. Keep in sync with `config/scoring.json`.
 * See docs/architecture.md §6.
 */
export default {
  version: 1,
  headlineMetric: "composite",
  strategyDefault: "mobile",
  weights: {
    carbon: 0.5,
    performance: 0.25,
    hosting: 0.15,
    efficiency: 0.1,
  },
  carbonBands: [
    { grade: "A+", maxGramsPerVisit: 0.095, points: 100 },
    { grade: "A", maxGramsPerVisit: 0.186, points: 92 },
    { grade: "B", maxGramsPerVisit: 0.341, points: 80 },
    { grade: "C", maxGramsPerVisit: 0.493, points: 68 },
    { grade: "D", maxGramsPerVisit: 0.656, points: 55 },
    { grade: "E", maxGramsPerVisit: 0.846, points: 42 },
    { grade: "F", maxGramsPerVisit: null, points: 25 },
  ],
  hostingPoints: { green: 100, grey: 0 },
  efficiencyBudgetMB: {
    fullScoreMaxMB: 1.0,
    zeroScoreMinMB: 4.0,
  },
  letterCutoffs: [
    { grade: "A+", min: 95 },
    { grade: "A", min: 85 },
    { grade: "B", min: 70 },
    { grade: "C", min: 55 },
    { grade: "D", min: 40 },
    { grade: "E", min: 25 },
    { grade: "F", min: 0 },
  ],
};

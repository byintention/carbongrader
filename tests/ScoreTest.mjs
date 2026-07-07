/**
 * Offline tests for score.js. Run: `node tests/ScoreTest.mjs`
 */

import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import {
  clamp,
  efficiencyScore,
  hostingScore,
  performanceSubScore,
  compositeScore,
  letterForScore,
  gradeResult,
} from "../src/js/score.js";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const config = JSON.parse(readFileSync(join(root, "config/scoring.json"), "utf8"));

let passed = 0;
let failed = 0;

function check(name, fn) {
  try {
    fn();
    passed++;
    console.log(`  ok  - ${name}`);
  } catch (e) {
    failed++;
    console.log(`FAIL  - ${name}: ${e.message}`);
  }
}

function assertSame(expected, actual, msg = "") {
  if (expected !== actual) {
    throw new Error(
      `${msg ? msg + ": " : ""}expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`,
    );
  }
}

function assertTrue(cond, msg = "") {
  if (!cond) throw new Error(msg || "expected true");
}

/** Fixture mirroring tests/fixtures/pagespeed_example.json result shape. */
const sampleResult = {
  url: "https://example.com/",
  host: "example.com",
  strategy: "mobile",
  hosting: { green: true, hostedBy: "Google Inc.", verified: true },
  page: {
    totalBytes: 1_843_200,
    performanceScore: 0.82,
    resources: [
      { type: "image", bytes: 980_000, count: 14 },
      { type: "script", bytes: 420_000, count: 9 },
    ],
  },
  opportunities: [],
};

console.log("score.js");

check("clamp bounds values", () => {
  assertSame(5, clamp(5, 0, 10));
  assertSame(0, clamp(-1, 0, 10));
  assertSame(10, clamp(99, 0, 10));
});

check("efficiency: full score at 1 MB", () => {
  assertSame(100, efficiencyScore(1 * 1024 * 1024, config.efficiencyBudgetMB));
});

check("efficiency: zero at 4 MB", () => {
  assertSame(0, efficiencyScore(4 * 1024 * 1024, config.efficiencyBudgetMB));
});

check("efficiency: linear midpoint at 2.5 MB", () => {
  assertSame(50, efficiencyScore(2.5 * 1024 * 1024, config.efficiencyBudgetMB));
});

check("hosting: green vs grey", () => {
  assertSame(100, hostingScore({ green: true }, config.hostingPoints));
  assertSame(0, hostingScore({ green: false }, config.hostingPoints));
});

check("performance sub-score from 0–1", () => {
  assertSame(82, performanceSubScore(0.82));
  assertSame(0, performanceSubScore(null));
});

check("composite weighted blend", () => {
  const s = compositeScore(
    { carbon: 80, performance: 82, hosting: 100, efficiency: 50 },
    config.weights,
  );
  // 80*0.5 + 82*0.25 + 100*0.15 + 50*0.1 = 40+20.5+15+5 = 80.5 -> 81
  assertSame(81, s);
});

check("letterForScore uses cutoffs", () => {
  assertSame("A+", letterForScore(95, config.letterCutoffs));
  assertSame("A", letterForScore(90, config.letterCutoffs));
  assertSame("B", letterForScore(70, config.letterCutoffs));
  assertSame("F", letterForScore(10, config.letterCutoffs));
});

check("gradeResult end-to-end on sample payload", () => {
  const g = gradeResult(sampleResult, config);
  assertTrue(g.gramsPerVisit > 0);
  assertSame("B", g.carbonBand);
  assertSame(80, g.carbonPoints);
  assertSame(82, g.components.performance);
  assertSame(100, g.components.hosting);
  assertTrue(g.compositeScore >= 0 && g.compositeScore <= 100);
  assertTrue(typeof g.compositeGrade === "string" && g.compositeGrade.length >= 1);
});

check("grey hosting lowers composite vs green", () => {
  const green = gradeResult(sampleResult, config).compositeScore;
  const grey = gradeResult(
    { ...sampleResult, hosting: { green: false, hostedBy: null, verified: true } },
    config,
  ).compositeScore;
  assertTrue(grey < green, `grey ${grey} should be < green ${green}`);
});

console.log(`\n${passed} passed, ${failed} failed\n`);
process.exit(failed === 0 ? 0 : 1);

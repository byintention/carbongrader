/**
 * Offline tests for carbon.js. Run: `node tests/CarbonTest.mjs`
 */

import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import {
  estimateGramsPerVisit,
  carbonBandForGrams,
  analyseCarbon,
} from "../src/js/carbon.js";

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

console.log("carbon.js");

check("perVisit returns positive grams for a real page size", () => {
  const g = estimateGramsPerVisit(1_843_200, false);
  assertTrue(g > 0 && g < 1, `got ${g}`);
});

check("green hosting lowers emissions vs grey", () => {
  const bytes = 1_000_000;
  const green = estimateGramsPerVisit(bytes, true);
  const grey = estimateGramsPerVisit(bytes, false);
  assertTrue(green < grey, `green ${green} should be < grey ${grey}`);
});

check("rejects negative bytes", () => {
  try {
    estimateGramsPerVisit(-1, false);
    throw new Error("expected throw");
  } catch (e) {
    assertTrue(e.message.includes("non-negative"));
  }
});

check("band A+ at the v4 cutoff", () => {
  const b = carbonBandForGrams(0.04, config.carbonBands);
  assertSame("A+", b.grade);
  assertSame(100, b.points);
});

check("band A just above the A+ cutoff", () => {
  const b = carbonBandForGrams(0.05, config.carbonBands);
  assertSame("A", b.grade);
  assertSame(92, b.points);
});

check("band B inside the v4 B range", () => {
  const b = carbonBandForGrams(0.1, config.carbonBands);
  assertSame("B", b.grade);
  assertSame(80, b.points);
});

check("band E at 0.3g on the v4 scale", () => {
  const b = carbonBandForGrams(0.3, config.carbonBands);
  assertSame("E", b.grade);
  assertSame(42, b.points);
});

check("band F above highest threshold", () => {
  const b = carbonBandForGrams(1.5, config.carbonBands);
  assertSame("F", b.grade);
  assertSame(25, b.points);
});

check("analyseCarbon integrates grams + band", () => {
  const r = analyseCarbon(1_843_200, false, config);
  assertTrue(r.gramsPerVisit > 0);
  assertSame("D", r.carbonBand); // ~0.27g, SWD v4 D is ≤ 0.278
  assertSame(55, r.carbonPoints);
});

console.log(`\n${passed} passed, ${failed} failed\n`);
process.exit(failed === 0 ? 0 : 1);

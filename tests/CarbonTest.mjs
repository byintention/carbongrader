/**
 * Offline tests for carbon.js. Run: `node tests/CarbonTest.mjs`
 */

import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import {
  estimateGramsPerVisit,
  carbonBandForGrams,
  analyzeCarbon,
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

check("band A+ at very low grams", () => {
  const b = carbonBandForGrams(0.05, config.carbonBands);
  assertSame("A+", b.grade);
  assertSame(100, b.points);
});

check("band B at 0.3g", () => {
  const b = carbonBandForGrams(0.3, config.carbonBands);
  assertSame("B", b.grade);
  assertSame(80, b.points);
});

check("band F above highest threshold", () => {
  const b = carbonBandForGrams(1.5, config.carbonBands);
  assertSame("F", b.grade);
  assertSame(25, b.points);
});

check("analyzeCarbon integrates grams + band", () => {
  const r = analyzeCarbon(1_843_200, false, config);
  assertTrue(r.gramsPerVisit > 0);
  assertSame("B", r.carbonBand); // ~0.27g for this fixture size
  assertSame(80, r.carbonPoints);
});

console.log(`\n${passed} passed, ${failed} failed\n`);
process.exit(failed === 0 ? 0 : 1);

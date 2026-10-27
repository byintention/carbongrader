/**
 * Offline tests for recommendations.js. Run: `node tests/RecommendationsTest.mjs`
 */

import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import recommendationsConfig from "../src/js/recommendations-config.js";
import { mapRecommendations } from "../src/js/recommendations.js";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const json = JSON.parse(readFileSync(join(root, "config/recommendations.json"), "utf8"));

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

const KB = 1024;
const MB = 1024 * 1024;

function result(overrides = {}) {
  return {
    url: "https://example.com/",
    host: "example.com",
    strategy: "mobile",
    hosting: { green: true, hostedBy: "Example Green", verified: true },
    page: {
      totalBytes: 1 * MB,
      resources: [],
    },
    opportunities: [],
    ...overrides,
  };
}

console.log("recommendations.js");

check("browser config mirrors recommendations.json rules", () => {
  assertSame(json.version, recommendationsConfig.version);
  assertSame(JSON.stringify(json.rules), JSON.stringify(recommendationsConfig.rules));
});

check("verified grey host recommends switching", () => {
  const tips = mapRecommendations(
    result({ hosting: { green: false, hostedBy: "Grey Host", verified: true } }),
    json,
  );
  assertSame("non-green-host", tips[0]?.id);
  assertSame("high", tips[0]?.impact);
  assertSame(1, tips.length);
});

check("verified green host does not recommend switching", () => {
  const tips = mapRecommendations(result(), json);
  assertTrue(tips.every((tip) => tip.id !== "non-green-host"));
  assertSame(0, tips.length);
});

check("unverified hosting does not recommend switching", () => {
  const tips = mapRecommendations(
    result({ hosting: { green: false, hostedBy: null, verified: false } }),
    json,
  );
  assertTrue(tips.every((tip) => tip.id !== "non-green-host"));
});

check("resource tips use strict KB thresholds", () => {
  const under = mapRecommendations(
    result({
      page: {
        totalBytes: 1 * MB,
        resources: [
          { type: "image", bytes: 500 * KB, count: 1 },
          { type: "script", bytes: 300 * KB, count: 1 },
          { type: "font", bytes: 150 * KB, count: 1 },
        ],
      },
    }),
    json,
  );
  assertSame(0, under.length);

  const over = mapRecommendations(
    result({
      page: {
        totalBytes: 1 * MB,
        resources: [
          { type: "image", bytes: 500 * KB + 1, count: 2 },
          { type: "script", bytes: 300 * KB + 1, count: 2 },
          { type: "font", bytes: 150 * KB + 1, count: 1 },
        ],
      },
    }),
    json,
  );
  assertSame("heavy-images,heavy-scripts,heavy-fonts", over.map((tip) => tip.id).join(","));
  assertSame("high,medium,low", over.map((tip) => tip.impact).join(","));
});

check("page weight tip starts strictly above 2 MB and lists heaviest types", () => {
  const atBudget = mapRecommendations(
    result({ page: { totalBytes: 2 * MB, resources: [{ type: "image", bytes: 2 * MB, count: 1 }] } }),
    json,
  );
  assertTrue(atBudget.every((tip) => tip.id !== "over-budget"));

  const over = mapRecommendations(
    result({
      page: {
        totalBytes: 3 * MB,
        resources: [
          { type: "script", bytes: 1.2 * MB, count: 4 },
          { type: "image", bytes: 1.5 * MB, count: 8 },
          { type: "font", bytes: 0.2 * MB, count: 2 },
          { type: "stylesheet", bytes: 0.1 * MB, count: 1 },
        ],
      },
    }),
    json,
  );
  const tip = over.find((item) => item.id === "over-budget");
  assertTrue(tip, "expected over-budget tip");
  assertSame("image,script,font", tip.heaviest.map((item) => item.type).join(","));
  assertSame(3, tip.heaviest.length);
});

check("PageSpeed opportunities expand to the top three by savings", () => {
  const tips = mapRecommendations(
    result({
      opportunities: [
        { id: "unused-css", title: "Reduce unused CSS", savingsMs: 299 },
        { id: "render-blocking", title: "Eliminate render-blocking resources", savingsMs: 1400 },
        { id: "unused-js", title: "Reduce unused JavaScript", savingsMs: 300 },
        { id: "offscreen", title: "Defer offscreen images", savingsMs: 1000 },
      ],
    }),
    json,
  );
  assertSame(
    "psi-render-blocking,psi-offscreen,psi-unused-js",
    tips.map((tip) => tip.id).join(","),
  );
  assertSame("high,high,medium", tips.map((tip) => tip.impact).join(","));
  assertSame(1400, tips[0].savingsMs);
  assertTrue(tips.every((tip) => tip.id !== "psi-unused-css"));
});

check("mixed tips sort high, then medium, then low, keeping rule order", () => {
  const tips = mapRecommendations(
    result({
      hosting: { green: false, hostedBy: "Grey Host", verified: true },
      page: {
        totalBytes: 3 * MB,
        resources: [
          { type: "image", bytes: 600 * KB, count: 4 },
          { type: "script", bytes: 400 * KB, count: 3 },
          { type: "font", bytes: 200 * KB, count: 2 },
        ],
      },
      opportunities: [
        { id: "big", title: "Compress large images", savingsMs: 2000 },
        { id: "mid", title: "Minify JavaScript", savingsMs: 500 },
        { id: "small", title: "Preload key requests", savingsMs: 100 },
      ],
    }),
    json,
  );
  assertSame(
    [
      "non-green-host",
      "heavy-images",
      "over-budget",
      "psi-big",
      "heavy-scripts",
      "psi-mid",
      "heavy-fonts",
      "psi-small",
    ].join(","),
    tips.map((tip) => tip.id).join(","),
  );
});

check("empty or unrecognised rules produce no tips", () => {
  assertSame(0, mapRecommendations(result(), { rules: [] }).length);
  assertSame(0, mapRecommendations(result(), { rules: [{ id: "x", when: {}, title: "X", why: "Y", impact: "high" }] }).length);
  assertSame(0, mapRecommendations(null, json).length);
});

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) process.exit(1);

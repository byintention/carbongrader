/**
 * Offline tests for history.js. Run: `node tests/HistoryTest.mjs`
 */

import { clearHistory, loadHistory, rememberResult, shareHref, shareQuery } from "../src/js/history.js";

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

function memoryStorage() {
  /** @type {Map<string, string>} */
  const data = new Map();
  return {
    getItem: (key) => (data.has(key) ? data.get(key) : null),
    setItem: (key, value) => data.set(key, String(value)),
    removeItem: (key) => data.delete(key),
  };
}

function sample(url, strategy = "mobile") {
  return {
    url,
    host: "example.com",
    strategy,
    fetchedAt: "2026-10-15T08:00:00Z",
    hosting: { green: false, hostedBy: "Grey Host", verified: true },
    page: {
      totalBytes: 1500000,
      performanceScore: 0.4,
      resources: [{ type: "image", bytes: 900000, count: 4 }],
    },
    opportunities: [{ id: "unused-js", title: "Reduce unused JavaScript", savingsMs: 800 }],
    apiKey: "should-not-be-stored",
  };
}

console.log("history.js");

check("stores a result and drops secrets", () => {
  const storage = memoryStorage();
  rememberResult(sample("https://example.com/"), storage);
  const [entry] = loadHistory(storage);
  assertSame("https://example.com/", entry.result.url);
  assertSame("mobile", entry.result.strategy);
  assertTrue(!("apiKey" in entry.result));
  assertTrue(!JSON.stringify(entry).includes("should-not-be-stored"));
});

check("same url and strategy replaces the older row", () => {
  const storage = memoryStorage();
  rememberResult(sample("https://example.com/"), storage);
  rememberResult(sample("https://other.example/"), storage);
  const again = sample("https://example.com/");
  again.page.totalBytes = 10;
  rememberResult(again, storage);
  const entries = loadHistory(storage);
  assertSame(2, entries.length);
  assertSame("https://example.com/", entries[0].result.url);
  assertSame(10, entries[0].result.page.totalBytes);
});

check("keeps at most eight entries", () => {
  const storage = memoryStorage();
  for (let i = 0; i < 10; i++) rememberResult(sample(`https://example.com/${i}`), storage);
  const entries = loadHistory(storage);
  assertSame(8, entries.length);
  assertSame("https://example.com/9", entries[0].result.url);
  assertSame("https://example.com/2", entries[7].result.url);
});

check("corrupt storage reads as empty", () => {
  const storage = memoryStorage();
  storage.setItem("cg.history.v1", "{not json");
  assertSame(0, loadHistory(storage).length);
});

check("clear removes history", () => {
  const storage = memoryStorage();
  rememberResult(sample("https://example.com/"), storage);
  clearHistory(storage);
  assertSame(0, loadHistory(storage).length);
});

check("share query carries only url and strategy", () => {
  assertSame(
    "url=https%3A%2F%2Fexample.com%2Fpath&strategy=desktop",
    shareQuery("https://example.com/path", "desktop"),
  );
  assertSame(
    "http://localhost/?url=https%3A%2F%2Fexample.com%2F&strategy=mobile",
    shareHref(sample("https://example.com/"), "http://localhost", "/"),
  );
});

check("unverified hosting stays unverified", () => {
  const storage = memoryStorage();
  const result = sample("https://example.com/");
  result.hosting = { green: false, hostedBy: null, verified: false };
  rememberResult(result, storage);
  assertSame(false, loadHistory(storage)[0].result.hosting.verified);
});

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) process.exit(1);

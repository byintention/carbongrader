/**
 * Offline tests for theme.js. Run: `node tests/ThemeTest.mjs`
 */

import { resolveTheme, themeChoiceAfterToggle } from "../src/js/theme.js";

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

function assertSame(expected, actual) {
  if (expected !== actual) {
    throw new Error(`expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
  }
}

check("explicit dark wins over a light system", () => {
  assertSame("dark", resolveTheme("dark", false));
});

check("explicit light wins over a dark system", () => {
  assertSame("light", resolveTheme("light", true));
});

check("no choice follows the system", () => {
  assertSame("dark", resolveTheme(null, true));
  assertSame("light", resolveTheme(undefined, false));
  assertSame("light", resolveTheme("auto", false));
});

check("toggling back to the system clears the saved choice", () => {
  assertSame(null, themeChoiceAfterToggle("dark", false));
  assertSame(null, themeChoiceAfterToggle("light", true));
});

check("toggling away from the system saves the override", () => {
  assertSame("light", themeChoiceAfterToggle("dark", true));
  assertSame("dark", themeChoiceAfterToggle("light", false));
});

console.log(`\n${passed} passed, ${failed} failed`);
if (failed) process.exit(1);

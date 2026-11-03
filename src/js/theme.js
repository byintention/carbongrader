/** localStorage key for an explicit light/dark choice. Absent means follow the system. */
export const THEME_KEY = "cg-theme";

/**
 * @param {string|null|undefined} stored
 * @param {boolean} systemDark
 * @returns {"light"|"dark"}
 */
export function resolveTheme(stored, systemDark) {
  if (stored === "light" || stored === "dark") return stored;
  return systemDark ? "dark" : "light";
}

/**
 * What to persist after a toggle. Null clears the override so the system scheme applies again.
 * @param {"light"|"dark"} current
 * @param {boolean} systemDark
 * @returns {"light"|"dark"|null}
 */
export function themeChoiceAfterToggle(current, systemDark) {
  const next = current === "dark" ? "light" : "dark";
  const system = systemDark ? "dark" : "light";
  return next === system ? null : next;
}

/**
 * Wire the header toggle. The blocking `theme-init.js` script has already set `data-theme`.
 * @param {HTMLButtonElement|null|undefined} button
 */
export function initThemeToggle(button) {
  const storage = safeStorage();
  const media = window.matchMedia("(prefers-color-scheme: dark)");
  const root = document.documentElement;

  const apply = () => {
    const theme = resolveTheme(readStored(storage), media.matches);
    root.setAttribute("data-theme", theme);
    if (button) button.setAttribute("aria-pressed", theme === "dark" ? "true" : "false");
  };

  apply();
  media.addEventListener("change", () => {
    if (readStored(storage) === null) apply();
  });

  button?.addEventListener("click", () => {
    const current = root.getAttribute("data-theme") === "dark" ? "dark" : "light";
    const choice = themeChoiceAfterToggle(current, media.matches);
    try {
      if (choice === null) storage.removeItem(THEME_KEY);
      else storage.setItem(THEME_KEY, choice);
    } catch {
      /* Private mode can reject storage; the toggle still applies for this view. */
    }
    apply();
  });
}

/** @param {Storage} storage */
function readStored(storage) {
  try {
    const value = storage.getItem(THEME_KEY);
    return value === "light" || value === "dark" ? value : null;
  } catch {
    return null;
  }
}

function safeStorage() {
  try {
    return localStorage;
  } catch {
    return memoryStorage();
  }
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

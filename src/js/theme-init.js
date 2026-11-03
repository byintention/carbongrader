/* Sets data-theme before first paint. Keep in step with resolveTheme() in theme.js. */
(function () {
  var stored = null;
  try {
    stored = localStorage.getItem("cg-theme");
  } catch (e) {
    stored = null;
  }
  if (stored !== "light" && stored !== "dark") stored = null;
  var systemDark = false;
  try {
    systemDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
  } catch (e) {
    systemDark = false;
  }
  var theme = stored || (systemDark ? "dark" : "light");
  document.documentElement.setAttribute("data-theme", theme);
})();

import { createGradeJob, fetchSavedResult, waitForGrade } from "./api.js";
import { clearHistory, isResultId, loadHistory, rememberResult, shareHref } from "./history.js";
import recommendationsConfig from "./recommendations-config.js";
import { mapRecommendations } from "./recommendations.js";
import scoringConfig from "./scoring-config.js";
import { gradeResult } from "./score.js";
import { initThemeToggle } from "./theme.js";
import { renderLoading, renderError, renderResults, renderHistory, clearPanel } from "./ui.js";

initThemeToggle(document.getElementById("theme-toggle"));

const form = document.getElementById("grade-form");
const urlInput = document.getElementById("url-input");
const submitBtn = document.getElementById("submit-btn");
const statusPanel = document.getElementById("status-panel");
const resultsPanel = document.getElementById("results-panel");
const historyPanel = document.getElementById("history-panel");

/** @type {AbortController|null} */
let activeController = null;

form?.addEventListener("submit", async (event) => {
  event.preventDefault();
  const url = urlInput?.value?.trim() ?? "";
  if (!url) {
    showError("Please enter a URL.");
    return;
  }

  const strategy = form.querySelector('input[name="strategy"]:checked')?.value ?? "mobile";

  const controller = new AbortController();
  activeController?.abort();
  activeController = controller;

  setBusy(true);
  clearPanel(resultsPanel);
  statusPanel.hidden = false;
  renderLoading(statusPanel, "queued");

  let keepStatus = false;
  try {
    const created = await createGradeJob(url, strategy);
    if (!created.ok || !created.id) {
      keepStatus = true;
      showError(created.error?.message ?? "Could not start analysis.", created.error?.code);
      return;
    }

    const result = await waitForGrade(created.id, {
      signal: controller.signal,
      onStatus: (s) => renderLoading(statusPanel, s),
    });
    showResults(result, { id: created.id });
  } catch (err) {
    if (err.name === "AbortError") return;
    keepStatus = true;
    showError(err.message ?? "Something went wrong.", err.code);
  } finally {
    if (activeController !== controller) return;
    setBusy(false);
    if (!keepStatus) clearPanel(statusPanel);
  }
});

function showResults(result, { remember = true, id = "" } = {}) {
  const shareId = isResultId(id) ? id : isResultId(result?.id) ? result.id : "";
  const eco = gradeResult(result, scoringConfig);
  const tips = mapRecommendations(result, recommendationsConfig);
  const href = shareId ? shareHref(shareId, location.origin, location.pathname) : "";
  renderResults(resultsPanel, result, eco, tips, { shareHref: href });
  resultsPanel.hidden = false;
  resultsPanel.focus();
  if (remember) rememberResult(shareId ? { ...result, id: shareId } : result);
  refreshHistory();
}

function refreshHistory() {
  if (!historyPanel) return;
  const entries = loadHistory().map((item) => ({
    result: item.result,
    grade: gradeResult(item.result, scoringConfig).compositeGrade,
    url: item.result.url,
    strategy: item.result.strategy,
    savedAt: item.savedAt,
  }));
  renderHistory(historyPanel, entries, (result) => showResults(result, { remember: false }), () => {
    clearHistory();
    refreshHistory();
  });
}

function showError(message, code = "") {
  statusPanel.hidden = false;
  renderError(statusPanel, message, code);
}

function setBusy(busy) {
  form?.setAttribute("aria-busy", busy ? "true" : "false");
  if (submitBtn) {
    submitBtn.disabled = busy;
    submitBtn.textContent = busy ? "Analyzing…" : "Grade it";
  }
  if (urlInput) urlInput.disabled = busy;
  form?.querySelectorAll('input[name="strategy"]').forEach((r) => {
    r.disabled = busy;
  });
}

refreshHistory();

const saved = savedResultRequest();
if (saved) {
  if (!saved.id) {
    showError("Saved result not found.", "NOT_FOUND");
  } else {
    openSavedResult(saved.id);
  }
} else {
  const shared = sharedRequest();
  if (shared && urlInput && form) {
    urlInput.value = shared.url;
    const radio = form.querySelector(`input[name="strategy"][value="${shared.strategy}"]`);
    if (radio) radio.checked = true;
    form.requestSubmit();
  }
}

/** `?r=` wins over `?url=`. An empty id means the query was present but not a result id. */
function savedResultRequest() {
  const params = new URLSearchParams(location.search);
  if (!params.has("r")) return null;
  const id = (params.get("r") ?? "").trim();
  return { id: isResultId(id) ? id : "" };
}

async function openSavedResult(id) {
  setBusy(true);
  if (submitBtn) submitBtn.textContent = "Loading…";
  clearPanel(resultsPanel);
  statusPanel.hidden = false;
  renderLoading(statusPanel, "saved");
  try {
    const body = await fetchSavedResult(id);
    if (!body.ok || !body.result) {
      showError(body.error?.message ?? "Saved result not found.", body.error?.code ?? "NOT_FOUND");
      return;
    }
    if (urlInput) urlInput.value = body.result.url ?? "";
    const strategy = body.result.strategy === "desktop" ? "desktop" : "mobile";
    const radio = form?.querySelector(`input[name="strategy"][value="${strategy}"]`);
    if (radio) radio.checked = true;
    showResults(body.result, { id });
    clearPanel(statusPanel);
  } catch (err) {
    showError(err.message ?? "Could not load the saved result.", err.code ?? "");
  } finally {
    setBusy(false);
  }
}

/** A re-grade link carries an http(s) URL and a strategy. */
function sharedRequest() {
  const params = new URLSearchParams(location.search);
  const url = params.get("url")?.trim() ?? "";
  if (!/^https?:\/\//i.test(url)) return null;
  return { url, strategy: params.get("strategy") === "desktop" ? "desktop" : "mobile" };
}

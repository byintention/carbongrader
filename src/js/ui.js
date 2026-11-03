/** @param {number} bytes */
export function formatBytes(bytes) {
  if (!Number.isFinite(bytes) || bytes < 0) return "—";
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

/** @param {number} grams */
export function formatGrams(grams) {
  if (!Number.isFinite(grams)) return "—";
  if (grams < 0.01) return `${grams.toExponential(2)} g`;
  return `${grams.toFixed(3)} g`;
}

/** @param {number|null|undefined} score 0–1 */
export function formatPerfScore(score) {
  if (!Number.isFinite(score)) return "—";
  return `${Math.round(score * 100)}`;
}

/**
 * Create an element with optional class and text (textContent only — safe).
 * @param {string} tag
 * @param {string} [className]
 * @param {string} [text]
 */
export function el(tag, className = "", text = "") {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text) node.textContent = text;
  return node;
}

/** CSS modifier for a letter grade badge. */
export function gradeClass(letter) {
  const base = letter.replace("+", "plus");
  return `cg-grade cg-grade--${base.toLowerCase()}`;
}

/**
 * Render the loading state.
 * @param {HTMLElement} container
 * @param {string} [status] queued|running
 */
export function renderLoading(container, status = "queued") {
  container.replaceChildren();
  const wrap = el("div", "cg-loading uk-text-center");
  const label =
    status === "running" ? "Running analysis…" : status === "saved" ? "Loading saved result…" : "Queued…";
  wrap.appendChild(el("div", "", label));
  if (status !== "saved") {
    wrap.appendChild(
      el("p", "uk-text-meta", "This can take up to 30 seconds while we fetch PageSpeed and hosting data."),
    );
  }
  const spinner = el("div", "cg-spinner", "");
  spinner.setAttribute("role", "status");
  spinner.setAttribute("aria-label", "Working");
  wrap.appendChild(spinner);
  container.appendChild(wrap);
}

/**
 * Render an error alert.
 * @param {HTMLElement} container
 * @param {string} message
 * @param {string} [code]
 */
export function renderError(container, message, code = "") {
  container.replaceChildren();
  const alert = el("div", "uk-alert-danger", "");
  alert.setAttribute("role", "alert");
  const copy = el("div", "cg-alert-copy");
  copy.appendChild(el("p", "uk-margin-remove", message));
  if (code) {
    copy.appendChild(el("p", "uk-text-meta uk-margin-small-top", `Code: ${code}`));
  }
  alert.append(alertIcon(), copy);
  container.appendChild(alert);
}

/** Decorative cross for the danger alert. The message text carries the meaning. */
function alertIcon() {
  const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
  svg.setAttribute("class", "cg-alert-icon");
  svg.setAttribute("viewBox", "0 0 24 24");
  svg.setAttribute("aria-hidden", "true");
  svg.setAttribute("focusable", "false");
  const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
  path.setAttribute("d", "M6 6l12 12M18 6L6 18");
  path.setAttribute("fill", "none");
  path.setAttribute("stroke", "currentColor");
  path.setAttribute("stroke-width", "2.5");
  path.setAttribute("stroke-linecap", "round");
  svg.appendChild(path);
  return svg;
}

/**
 * Clear a panel.
 * @param {HTMLElement} container
 */
export function clearPanel(container) {
  container.replaceChildren();
  container.hidden = true;
}

/**
 * Render the full results dashboard.
 *
 * @param {HTMLElement} container
 * @param {object} result API result payload
 * @param {object} eco output of gradeResult()
 * @param {object[]} [tips] output of mapRecommendations()
 * @param {{ shareHref?: string }} [actions]
 */
export function renderResults(container, result, eco, tips = [], actions = {}) {
  container.replaceChildren();
  container.hidden = false;

  const grid = el("div", "cg-results");

  const header = el("div", "cg-results-head");
  const title = el("h2", "uk-margin-remove", "Results");
  title.id = "results-heading";
  const analyzed = el("p", "uk-text-meta uk-margin-remove");
  const cached = result.cached ? " · cached result" : "";
  analyzed.textContent = `Analyzed ${result.url} (${result.strategy})${cached}`;
  header.append(title, analyzed);
  grid.appendChild(header);

  // — Headline grade —
  const headline = el("div", "uk-width-1-1");
  const hero = el("div", "cg-hero uk-card uk-card-default uk-card-body uk-text-center");
  const badge = el("div", gradeClass(eco.compositeGrade), eco.compositeGrade);
  badge.setAttribute("aria-label", `EcoGrade ${eco.compositeGrade}`);
  hero.appendChild(badge);
  hero.appendChild(el("p", "cg-score uk-margin-small-top", String(eco.compositeScore)));
  hero.appendChild(el("p", "uk-text-meta", "Composite EcoGrade (0–100)"));
  headline.appendChild(hero);
  grid.appendChild(headline);

  // — Metric cards —
  const metrics = el("div", "cg-results-block");
  const metricGrid = el("div", "cg-metrics");

  metricGrid.appendChild(metricCard("Carbon / visit", formatGrams(eco.gramsPerVisit), `Band ${eco.carbonBand}`));
  metricGrid.appendChild(metricCard("Page weight", formatBytes(result.page?.totalBytes ?? 0)));
  metricGrid.appendChild(
    metricCard("Performance", `${formatPerfScore(result.page?.performanceScore)}/100`),
  );
  metricGrid.appendChild(hostingCard(result.hosting));
  metrics.appendChild(metricGrid);
  grid.appendChild(metrics);

  // — Resource breakdown —
  const resources = result.page?.resources ?? [];
  if (resources.length > 0) {
    const resSection = el("div", "cg-results-block");
    resSection.appendChild(el("h3", "uk-heading-divider uk-margin-remove-top", "Resource breakdown"));
    const list = el("ul", "uk-list uk-list-divider");
    const maxBytes = Math.max(...resources.map((r) => r.bytes), 1);
    for (const item of resources) {
      const li = el("li", "cg-resource-row");
      const label = el("span", "cg-resource-label", labelType(item.type));
      const pct = Math.max(0, Math.min(100, Math.round((item.bytes / maxBytes) * 100)));
      const meter = document.createElement("progress");
      meter.className = "cg-meter";
      meter.max = 100;
      meter.value = pct;
      const fileLabel = `${item.count} file${item.count === 1 ? "" : "s"}`;
      const metaText = `${formatBytes(item.bytes)} · ${fileLabel}`;
      meter.setAttribute("aria-label", `${labelType(item.type)}: ${metaText}`);
      const meta = el("span", "cg-resource-meta");
      meta.append(formatBytes(item.bytes), el("span", "cg-resource-count", fileLabel));
      li.append(label, meter, meta);
      list.appendChild(li);
    }
    resSection.appendChild(list);
    grid.appendChild(resSection);
  }

  // — Component scores —
  const compSection = el("div", "cg-results-block");
  compSection.appendChild(el("h3", "uk-heading-divider", "Score breakdown"));
  const compList = el("ul", "uk-list uk-list-divider");
  for (const [key, value] of Object.entries(eco.components)) {
    const li = el("li", "cg-resource-row cg-score-row");
    const name = labelForComponent(key);
    const label = el("span", "cg-resource-label", name);
    const score = Math.max(0, Math.min(100, Math.round(Number(value) || 0)));
    const meter = document.createElement("progress");
    meter.className = "cg-meter";
    meter.max = 100;
    meter.value = score;
    const metaText = `${score}/100`;
    meter.setAttribute("aria-label", `${name}: ${metaText}`);
    const meta = el("span", "cg-resource-meta", metaText);
    li.append(label, meter, meta);
    compList.appendChild(li);
  }
  compSection.appendChild(compList);
  grid.appendChild(compSection);

  grid.appendChild(recommendationsSection(tips));

  if (actions.shareHref) {
    grid.appendChild(shareBlock(actions.shareHref));
  }

  container.appendChild(grid);
}

function metricCard(title, value, sub = "") {
  const card = el("div", "cg-metric");
  const inner = el("div", "cg-metric-card uk-card uk-card-default uk-card-body uk-card-small uk-text-center");
  inner.appendChild(el("div", "uk-text-meta", title));
  inner.appendChild(el("div", "cg-metric-value", value));
  inner.appendChild(el("div", "cg-metric-sub uk-text-small uk-text-muted", sub));
  card.appendChild(inner);
  return card;
}

function hostingCard(hosting) {
  const card = el("div", "cg-metric");
  const inner = el("div", "cg-metric-card uk-card uk-card-default uk-card-body uk-card-small uk-text-center");
  inner.appendChild(el("div", "uk-text-meta", "Green hosting"));
  let labelClass = "uk-label";
  let labelText = "Unknown";
  if (hosting?.verified === false) {
    labelClass += " cg-label-muted";
    labelText = "Couldn't verify";
  } else if (hosting?.green) {
    labelClass += " uk-label-success";
    labelText = "Green";
  } else {
    labelClass += " uk-label-warning";
    labelText = "Not green";
  }
  const label = el("span", labelClass, labelText);
  inner.appendChild(label);
  const detail = el("div", "cg-host-detail");
  if (hosting?.hostedBy) {
    detail.appendChild(el("div", "uk-text-small uk-text-muted uk-margin-small-top", hosting.hostedBy));
  }
  detail.appendChild(el("div", "cg-metric-sub uk-text-small uk-text-muted", "Checks this host only."));
  inner.appendChild(detail);
  card.appendChild(inner);
  return card;
}

/**
 * Recent grades saved in this browser.
 *
 * @param {HTMLElement} container
 * @param {Array<{ grade: string, url: string, strategy: string, savedAt: string, result: object }>} entries
 * @param {(result: object) => void} onOpen
 * @param {() => void} onClear
 */
export function renderHistory(container, entries, onOpen, onClear) {
  container.replaceChildren();
  if (!entries.length) {
    container.hidden = true;
    return;
  }
  container.hidden = false;
  const heading = el("h2", "uk-heading-divider", "Recent grades");
  heading.id = "history-heading";
  container.appendChild(heading);
  const list = el("ul", "uk-list uk-list-divider");
  for (const entry of entries) {
    const li = el("li", "");
    const btn = el("button", "cg-history-btn", "");
    btn.type = "button";
    const letter = entry.grade || "—";
    const tone = letter.replace("+", "plus").toLowerCase();
    const badge = el("span", `cg-history-grade cg-grade--${tone}`, letter);
    badge.setAttribute("aria-hidden", "true");
    const text = el("span", "cg-history-text");
    text.appendChild(el("span", "cg-history-url", entry.url));
    text.appendChild(el("span", "uk-text-meta", `${entry.strategy} · ${formatWhen(entry.savedAt)}`));
    btn.append(badge, text);
    btn.setAttribute("aria-label", `Show saved grade ${letter} for ${entry.url}`);
    btn.addEventListener("click", () => onOpen(entry.result));
    li.appendChild(btn);
    list.appendChild(li);
  }
  container.appendChild(list);
  const clear = el("button", "uk-button uk-button-danger uk-margin-small-top", "Clear history");
  clear.type = "button";
  clear.addEventListener("click", onClear);
  container.appendChild(clear);
}

/** @param {string} href */
function shareBlock(href) {
  const wrap = el("div", "cg-results-block cg-share");
  wrap.appendChild(el("h2", "uk-heading-divider", "Share this result"));
  const label = el("label", "uk-form-label", "Share link");
  label.htmlFor = "share-link";
  const row = el("div", "cg-share-row");
  const input = document.createElement("input");
  input.className = "uk-input";
  input.id = "share-link";
  input.readOnly = true;
  input.value = href;
  const btn = el("button", "uk-button uk-button-primary", "Copy");
  btn.type = "button";
  btn.addEventListener("click", async () => {
    try {
      await navigator.clipboard.writeText(href);
      btn.textContent = "Copied";
    } catch {
      input.focus();
      input.select();
      btn.textContent = "Select the link";
    }
  });
  row.append(input, btn);
  wrap.append(label, row);
  const note = el(
    "p",
    "uk-text-meta uk-margin-small-top uk-margin-remove-bottom",
    "Opens this saved snapshot. It does not run a new analysis.",
  );
  wrap.appendChild(note);
  return wrap;
}

/** @param {string} iso */
function formatWhen(iso) {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  return date.toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
}

/**
 * @param {object[]} tips
 */
function recommendationsSection(tips) {
  const section = el("div", "cg-results-block");
  section.appendChild(el("h3", "uk-heading-divider", "What to improve"));

  if (!tips.length) {
    section.appendChild(
      el("p", "uk-text-meta uk-margin-remove", "No specific recommendations for this page."),
    );
    return section;
  }

  const list = el("ul", "uk-list uk-list-divider cg-recs");
  for (const tip of tips) {
    const li = el("li", "cg-rec");
    const head = el("div", "cg-rec-head");
    head.appendChild(el("span", "cg-rec-title", tip.title));
    const impact = tip.impact === "high" || tip.impact === "medium" || tip.impact === "low"
      ? tip.impact
      : "low";
    head.appendChild(el("span", `cg-impact cg-impact--${impact}`, impactLabel(impact)));
    li.appendChild(head);
    if (tip.why) li.appendChild(el("p", "cg-rec-why", tip.why));
    const extra = tipDetail(tip);
    if (extra) li.appendChild(el("p", "cg-rec-detail", extra));
    list.appendChild(li);
  }
  section.appendChild(list);
  section.appendChild(helpCta());
  return section;
}

function helpCta() {
  const panel = el("aside", "cg-help-cta uk-card-body");
  panel.appendChild(el("h2", "", "Need help fixing this?"));
  panel.appendChild(
    el(
      "p",
      "",
      "Page speed is part of this grade. A slow page sends more data, uses more energy, and people are more likely to leave before it loads. We can help you fix it!",
    ),
  );
  const link = el("a", "uk-button cg-help-cta-btn", "Get in touch");
  link.href = "https://byintention.co/contact?utm_source=carbongradercta";
  panel.appendChild(link);
  return panel;
}

/** @param {"high"|"medium"|"low"} impact */
function impactLabel(impact) {
  return { high: "High impact", medium: "Medium impact", low: "Low impact" }[impact];
}

/** @param {{ savingsMs?: number, heaviest?: Array<{ type: string, bytes: number }> }} tip */
function tipDetail(tip) {
  if (Number.isFinite(tip.savingsMs) && tip.savingsMs > 0) {
    return `Estimated savings: ${formatSavings(tip.savingsMs)}`;
  }
  if (Array.isArray(tip.heaviest) && tip.heaviest.length > 0) {
    const parts = tip.heaviest.map((item) => `${labelType(item.type)} (${formatBytes(item.bytes)})`);
    return `Heaviest: ${parts.join(", ")}.`;
  }
  return "";
}

/** @param {number} ms */
function formatSavings(ms) {
  if (ms < 1000) return `${Math.round(ms)} ms`;
  const seconds = ms / 1000;
  return `${seconds >= 10 ? Math.round(seconds) : seconds.toFixed(1)} s`;
}

/** @param {string} type */
function labelType(type) {
  if (!type) return "Other";
  return type.charAt(0).toUpperCase() + type.slice(1);
}

function labelForComponent(key) {
  return (
    {
      carbon: "Carbon footprint",
      performance: "Performance",
      hosting: "Green hosting",
      efficiency: "Resource efficiency",
    }[key] ?? key
  );
}

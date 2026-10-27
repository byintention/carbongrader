import appConfig from "./config.js";

/**
 * POST to create a grading job.
 * @returns {Promise<{ok:boolean,id?:string,status?:string,error?:object}>}
 */
export async function createGradeJob(url, strategy = appConfig.strategyDefault, refresh = false) {
  const res = await fetch(appConfig.apiBase, {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({ url, strategy, refresh }),
  });
  return res.json();
}

/**
 * GET to poll a job by id.
 * @returns {Promise<{ok:boolean,id?:string,status?:string,result?:object,error?:object}>}
 */
export async function pollGradeJob(id) {
  const res = await fetch(`${appConfig.apiBase}?id=${encodeURIComponent(id)}`, {
    headers: { Accept: "application/json" },
  });
  return res.json();
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Poll until the job is done or errors. Calls `onStatus` on each tick.
 *
 * @param {string} id job id
 * @param {{ onStatus?: (status: string) => void, signal?: AbortSignal }} options
 * @returns {Promise<object>} the result payload
 */
export async function waitForGrade(id, { onStatus, signal } = {}) {
  const start = Date.now();
  let interval = appConfig.pollIntervalMs;

  while (Date.now() - start < appConfig.pollTimeoutMs) {
    if (signal?.aborted) {
      throw new DOMException("Aborted", "AbortError");
    }

    const body = await pollGradeJob(id);
    if (!body.ok) {
      const err = new Error(body.error?.message ?? "Request failed");
      err.code = body.error?.code;
      err.retryAfter = body.error?.retryAfter;
      throw err;
    }

    const status = body.status ?? "queued";
    onStatus?.(status);

    if (status === "done") {
      return body.result;
    }
    if (status === "error") {
      const err = new Error(body.error?.message ?? "Analysis failed");
      err.code = body.error?.code;
      throw err;
    }

    await sleep(interval);
    interval = Math.min(interval * 1.5, appConfig.pollMaxIntervalMs);
  }

  const err = new Error("Analysis timed out. Please try again.");
  err.code = "TIMEOUT";
  throw err;
}

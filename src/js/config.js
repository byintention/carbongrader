/** Client-side app config (poll timing, API path). */
export default {
  apiBase: "/api/grade.php",
  strategyDefault: "mobile",
  /** First poll delay (ms). */
  pollIntervalMs: 2000,
  /** Backoff cap between polls (ms). */
  pollMaxIntervalMs: 8000,
  /** Give up after this long (ms). */
  pollTimeoutMs: 120_000,
};

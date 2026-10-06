// The Node website reads numbered availability from the separate PHP backend.
// Any backend HTTP credentials remain on the server. Forward numbers only.

/** @param {string} [method] */
export async function boothAvailability(method = "GET") {
  const headers = {
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "no-store",
    "X-Content-Type-Options": "nosniff",
  };
  /** @param {number} statusCode @param {unknown} body */
  const result = (statusCode, body) => ({ statusCode, headers, body: JSON.stringify(body) });
  if (method !== "GET") return result(405, { ok: false, error: "method" });
  const unavailable = () => result(503, { ok: false, error: "availability_unavailable" });
  if (!process.env.BOOTH_API_URL) return unavailable();
  try {
    const endpoint = new URL(process.env.BOOTH_API_URL);
    const local = ["localhost", "127.0.0.1", "[::1]"].includes(endpoint.hostname);
    if (endpoint.protocol !== "https:" && !(local && endpoint.protocol === "http:")) {
      return unavailable();
    }
    if (endpoint.username || endpoint.password) return unavailable();
    /** @type {Record<string, string>} */
    const upstreamHeaders = { Accept: "application/json" };
    const username = process.env.BOOTH_API_USERNAME;
    const password = process.env.BOOTH_API_PASSWORD;
    if (username || password) {
      if (!username || !password) return unavailable();
      upstreamHeaders.Authorization = `Basic ${Buffer.from(`${username}:${password}`).toString("base64")}`;
    }
    const response = await fetch(endpoint, {
      headers: upstreamHeaders,
      redirect: "error",
      cache: "no-store",
      signal: AbortSignal.timeout(5000),
    });
    if (!response.ok) return unavailable();
    /** @type {{ ok?: unknown, booked?: unknown }} */
    const state = await response.json();
    if (
      state?.ok !== true ||
      !Array.isArray(state.booked) ||
      state.booked.length > 44 ||
      !state.booked.every((number) => Number.isInteger(number) && number >= 1 && number <= 44)
    ) {
      return unavailable();
    }
    return result(200, { ok: true, booked: [...new Set(state.booked)].sort((a, b) => a - b) });
  } catch {
    return unavailable();
  }
}

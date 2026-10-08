// Use the same PHP backend and server-only HTTP credentials as booth availability.
/** @param {string} route @param {URLSearchParams} query */
export function backendConnection(route, query) {
  const base = process.env.WEBSITE_API_URL || process.env.BOOTH_API_URL;
  if (!base) throw new Error("Backend is not configured");
  const endpoint = new URL(route, base);
  const local = ["localhost", "127.0.0.1", "[::1]"].includes(endpoint.hostname);
  if (endpoint.protocol !== "https:" && !(local && endpoint.protocol === "http:"))
    throw new Error("Invalid backend URL");
  if (endpoint.username || endpoint.password) throw new Error("Invalid backend URL");
  endpoint.search = query.toString();
  /** @type {Record<string, string>} */
  const upstreamHeaders = { Accept: "application/json" };
  const username = process.env.BOOTH_API_USERNAME;
  const password = process.env.BOOTH_API_PASSWORD;
  if (username || password) {
    if (!username || !password) throw new Error("Incomplete backend credentials");
    upstreamHeaders.Authorization = `Basic ${Buffer.from(`${username}:${password}`).toString("base64")}`;
  }
  return { endpoint, upstreamHeaders };
}
/** @param {string} route @param {URLSearchParams} [query] */
export async function websiteState(route, query = new URLSearchParams()) {
  const headers = { "Content-Type": "application/json", "Cache-Control": "no-store" };
  const unavailable = () => ({
    statusCode: 503,
    headers,
    body: '{"ok":false,"error":"settings_unavailable"}',
  });
  if (!["site-state.php", "config.php"].includes(route)) return unavailable();
  try {
    const { endpoint, upstreamHeaders } = backendConnection(route, query);
    const response = await fetch(endpoint, {
      headers: upstreamHeaders,
      redirect: "error",
      cache: "no-store",
      signal: AbortSignal.timeout(5000),
    });
    if (!response.ok) return unavailable();
    const state = await response.json();
    if (state?.ok !== true) return unavailable();
    if (route === "site-state.php") {
      if (typeof state.registrationOpen !== "boolean" || typeof state.programHidden !== "boolean")
        return unavailable();
      return {
        statusCode: 200,
        headers,
        body: JSON.stringify({
          ok: true,
          registrationOpen: state.registrationOpen,
          programHidden: state.programHidden,
        }),
      };
    }
    if (typeof state.open !== "boolean" || !state.prices || !state.lunch) return unavailable();
    // The config endpoint's documented fields are all public.
    const { open, reason, message, messages, prices, lunch, gateway } = state;
    return {
      statusCode: 200,
      headers,
      body: JSON.stringify({ ok: true, open, reason, message, messages, prices, lunch, gateway }),
    };
  } catch {
    return unavailable();
  }
}

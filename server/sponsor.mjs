import { backendConnection } from "./website-state.mjs";

/** Send the sponsorship / exhibition booth request to the backend that keeps
 * them (api/sponsor.php), like the registration form.
 * @param {import('node:http').IncomingMessage} req */
export async function sponsorRequest(req) {
  /** @param {number} statusCode @param {unknown} value */
  const reply = (statusCode, value) => ({
    statusCode,
    headers: { "Content-Type": "application/json", "Cache-Control": "no-store" },
    body: JSON.stringify(value),
  });
  if (req.method !== "POST") return reply(405, { ok: false, error: "method" });
  const allowed = (process.env.ALLOWED_ORIGIN || "https://ismile.krd,https://www.ismile.krd")
    .split(",")
    .map((value) => value.trim());
  if (req.headers.origin && !allowed.includes(req.headers.origin))
    return reply(403, { ok: false, error: "err_server" });
  /** @type {Buffer[]} */
  const chunks = [];
  let size = 0;
  for await (const chunk of req) {
    const bytes = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk);
    size += bytes.length;
    if (size <= 100 * 1024) chunks.push(bytes);
  }
  if (size > 100 * 1024) return reply(413, { ok: false, error: "err_server" });
  try {
    const { endpoint, upstreamHeaders } = backendConnection("sponsor.php", new URLSearchParams());
    const response = await fetch(endpoint, {
      method: "POST",
      headers: { ...upstreamHeaders, "Content-Type": "application/json", Origin: endpoint.origin },
      body: new Uint8Array(Buffer.concat(chunks)),
      redirect: "error",
      signal: AbortSignal.timeout(20000),
    });
    const data = await response.json();
    if (data?.ok === true && response.ok && typeof data.ref === "string") {
      return reply(200, { ok: true, ref: data.ref });
    }
    if (
      data?.ok === false &&
      typeof data.error === "string" &&
      response.status >= 400 &&
      response.status < 500
    ) {
      return reply(response.status, { ok: false, error: data.error, field: data.field });
    }
  } catch {
    /* Return the public error, without backend credentials or diagnostics. */
  }
  return reply(503, { ok: false, error: "err_server" });
}

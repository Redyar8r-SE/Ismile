import { backendConnection } from "./website-state.mjs";

/** Send the public form to the same backend that controls registration.
 * @param {import('node:http').IncomingMessage} req */
export async function registrationRequest(req) {
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
    if (size <= 12 * 1024 * 1024) chunks.push(bytes);
  }
  if (size > 12 * 1024 * 1024) return reply(413, { ok: false, error: "err_student_id_size" });
  try {
    const { endpoint, upstreamHeaders } = backendConnection("register.php", new URLSearchParams());
    const response = await fetch(endpoint, {
      method: "POST",
      headers: {
        ...upstreamHeaders,
        "Content-Type": req.headers["content-type"] || "application/octet-stream",
        Origin: endpoint.origin,
      },
      body: new Uint8Array(Buffer.concat(chunks)),
      redirect: "error",
      signal: AbortSignal.timeout(30000),
    });
    const data = await response.json();
    if (data?.ok === true && response.ok) {
      const { ref, statusUrl, redirect, payError } = data;
      return reply(200, { ok: true, ref, statusUrl, redirect, payError });
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

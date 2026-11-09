import { readFileSync } from "node:fs";
import { backendConnection } from "./website-state.mjs";

/** @param {unknown} value */
function catalog(value) {
  return (
    Array.isArray(value) &&
    value.every(
      (w) =>
        w &&
        typeof w.id === "string" &&
        /^[a-z0-9-]{2,40}$/.test(w.id) &&
        typeof w.title === "object" &&
        Number.isInteger(w.price) &&
        Number.isInteger(w.seatsLeft),
    )
  );
}

/** The backend is authoritative; an outage never restores stale demo workshops.
 * @param {string} localFile */
export async function workshopsFile(localFile) {
  const headers = {
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "no-store",
  };
  try {
    if (!process.env.WEBSITE_API_URL && !process.env.BOOTH_API_URL) {
      const local = JSON.parse(readFileSync(localFile, "utf8"));
      if (!catalog(local)) throw new Error("Invalid workshop catalog");
      return { statusCode: 200, headers, body: JSON.stringify(local) };
    }
    const { endpoint, upstreamHeaders } = backendConnection("workshops.php", new URLSearchParams());
    const response = await fetch(endpoint, {
      headers: upstreamHeaders,
      redirect: "error",
      cache: "no-store",
      signal: AbortSignal.timeout(12000),
    });
    const data = await response.json();
    if (!response.ok || !catalog(data)) throw new Error("Workshop catalog unavailable");
    return { statusCode: 200, headers, body: JSON.stringify(data) };
  } catch {
    return { statusCode: 503, headers, body: '{"ok":false,"error":"workshops_unavailable"}' };
  }
}

/** @param {import('node:http').IncomingMessage} req */
export async function workshopRegistration(req) {
  /** @param {number} statusCode @param {unknown} data */
  const reply = (statusCode, data) => ({
    statusCode,
    headers: { "Content-Type": "application/json", "Cache-Control": "no-store" },
    body: JSON.stringify(data),
  });
  if (req.method !== "POST") return reply(405, { ok: false, error: "method" });
  const allowed = (process.env.ALLOWED_ORIGIN || "https://ismile.krd,https://www.ismile.krd")
    .split(",")
    .map((v) => v.trim());
  if (!req.headers.origin || !allowed.includes(req.headers.origin))
    return reply(403, { ok: false, error: "wd_err_server" });
  /** @type {Buffer[]} */
  const chunks = [];
  let size = 0;
  for await (const chunk of req) {
    const data = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk);
    size += data.length;
    if (size <= 32768) chunks.push(data);
  }
  if (size > 32768) return reply(413, { ok: false, error: "wd_err_email" });
  try {
    const { endpoint, upstreamHeaders } = backendConnection(
      "workshop-register.php",
      new URLSearchParams(),
    );
    const response = await fetch(endpoint, {
      method: "POST",
      headers: {
        ...upstreamHeaders,
        "Content-Type": req.headers["content-type"] || "application/octet-stream",
        Origin: endpoint.origin,
      },
      body: new Uint8Array(Buffer.concat(chunks)),
      redirect: "error",
      signal: AbortSignal.timeout(20000),
    });
    const data = await response.json();
    if (response.ok && data?.ok === true) {
      if (data.cashRequested === true) return reply(200, { ok: true, cashRequested: true });
      const redirect = paymentRedirect(data.redirect);
      if (!redirect) return reply(503, { ok: false, error: "wd_err_server" });
      return reply(200, { ok: true, redirect });
    }
    if (response.status >= 400 && response.status < 500 && typeof data?.error === "string")
      return reply(response.status, { ok: false, error: data.error, field: data.field });
  } catch {
    /* Public response excludes connection details. */
  }
  return reply(503, { ok: false, error: "wd_err_server" });
}

/** @param {unknown} value */
function paymentRedirect(value) {
  if (typeof value !== "string") return null;
  try {
    const url = new URL(value);
    if (
      url.protocol !== "https:" ||
      url.username ||
      url.password ||
      (url.port && url.port !== "443")
    )
      return null;
    if (/(^|\.)psoola\.com$/i.test(url.hostname)) return url.href;
    if (
      ["ismile.krd", "www.ismile.krd"].includes(url.hostname) &&
      url.pathname === "/workshop.html" &&
      /^[a-f0-9]{64}$/.test(url.searchParams.get("payment") || "")
    )
      return url.href;
  } catch {
    /* Invalid provider redirect. */
  }
  return null;
}

/** Public payment status exposes no attendee data or provider credentials.
 * @param {URLSearchParams} query */
export async function workshopPayment(query) {
  const headers = {
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "no-store",
    "Referrer-Policy": "no-referrer",
  };
  const token = query.get("token") || "";
  if (!/^[a-f0-9]{64}$/.test(token))
    return { statusCode: 404, headers, body: '{"ok":false,"error":"wd_err_missing"}' };
  try {
    const { endpoint, upstreamHeaders } = backendConnection(
      "workshop-payment.php",
      new URLSearchParams({ token }),
    );
    const response = await fetch(endpoint, {
      headers: upstreamHeaders,
      redirect: "error",
      cache: "no-store",
      signal: AbortSignal.timeout(20000),
    });
    const data = await response.json();
    if (
      response.ok &&
      data?.ok === true &&
      typeof data.workshopId === "string" &&
      typeof data.confirmed === "boolean" &&
      [
        "created",
        "unknown",
        "awaiting_payment",
        "paid",
        "expired",
        "cancelled",
        "refunded",
        "review",
      ].includes(data.status) &&
      (!data.confirmed || data.status === "paid")
    ) {
      return {
        statusCode: 200,
        headers,
        body: JSON.stringify({
          ok: true,
          workshopId: data.workshopId,
          status: data.status,
          confirmed: data.confirmed,
          checkoutUrl:
            data.status === "awaiting_payment" ? paymentRedirect(data.checkoutUrl) : null,
        }),
      };
    }
    if (response.status === 404)
      return { statusCode: 404, headers, body: '{"ok":false,"error":"wd_err_missing"}' };
  } catch {
    /* Never turn an unverified return URL into a paid registration. */
  }
  return { statusCode: 503, headers, body: '{"ok":false,"error":"wd_err_server"}' };
}

import { sessionFor } from "../netlify/functions/api.mjs";
import { backendConnection } from "./website-state.mjs";

/** Opens the website editor signed in, from the database's one-time "Site
 * content" pass: the backend (api/editor-handoff.php) says whose pass it is.
 * @param {import('node:http').IncomingMessage} req */
export async function editorHandoff(req) {
  /** @param {number} statusCode @param {unknown} value */
  const reply = (statusCode, value) => ({
    statusCode,
    headers: { "Content-Type": "application/json", "Cache-Control": "no-store" },
    body: JSON.stringify(value),
  });
  if (req.method !== "POST") return reply(405, { error: "method" });
  if (!process.env.SESSION_SECRET) return reply(503, { error: "The editor server is not set up." });
  /** @type {Buffer[]} */
  const chunks = [];
  let size = 0;
  for await (const chunk of req) {
    const bytes = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk);
    size += bytes.length;
    if (size <= 2048) chunks.push(bytes);
  }
  let pass = "";
  try {
    pass = String(JSON.parse(Buffer.concat(chunks).toString()).pass || "");
  } catch {
    /* not JSON: refused below */
  }
  if (size > 2048 || !/^[A-Za-z0-9_-]{10,400}\.[A-Za-z0-9_-]{10,100}$/.test(pass)) {
    return reply(401, {
      error: "This sign-in link is not valid. Open Site content from the database again.",
    });
  }
  try {
    const { endpoint, upstreamHeaders } = backendConnection(
      "editor-handoff.php",
      new URLSearchParams({ t: pass }),
    );
    const response = await fetch(endpoint, {
      headers: upstreamHeaders,
      redirect: "error",
      signal: AbortSignal.timeout(10000),
    });
    const data = await response.json();
    if (
      response.ok &&
      data?.ok === true &&
      typeof data.email === "string" &&
      data.email.includes("@")
    ) {
      return reply(200, await sessionFor(data.email));
    }
  } catch {
    /* the database could not be asked: same answer, no details */
  }
  return reply(401, {
    error: "This sign-in link has expired. Open Site content from the database again.",
  });
}

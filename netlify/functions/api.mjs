// The small server behind the admin page.
//
// It does two things the browser must not do by itself:
//   1. checks an email + password against secrets kept on the server;
//   2. writes to GitHub with a token that never reaches the browser.
//
// Environment variables (set in the Netlify dashboard):
//   ADMIN_EMAIL           the address allowed to sign in
//   ADMIN_PASSWORD_HASH   pbkdf2$<iterations>$<saltBase64>$<hashBase64>
//   SESSION_SECRET        any long random text; signs the sign-in tickets
//   GITHUB_TOKEN          a fine-grained token with Contents: read and write
//   GITHUB_REPO           owner/name, e.g. Redyar8r-SE/Ismile
//   GITHUB_BRANCH         optional, defaults to main
//   ALLOWED_ORIGIN        optional, defaults to allowing the GitHub Pages site

const DAY = 86400;
const SESSION_HOURS = 12;

const enc = new TextEncoder();
const b64 = (bytes) => Buffer.from(bytes).toString("base64");
const unb64 = (text) => Buffer.from(text, "base64");
const b64url = (text) => Buffer.from(text).toString("base64url");

function cors(origin) {
  const allowed = (process.env.ALLOWED_ORIGIN || "https://redyar8r-se.github.io").split(",").map((o) => o.trim());
  const ok = origin && (allowed.includes(origin) || origin.endsWith(".netlify.app") || origin.startsWith("http://localhost"));
  return {
    "Access-Control-Allow-Origin": ok ? origin : allowed[0],
    "Access-Control-Allow-Headers": "Content-Type, Authorization",
    "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
    "Access-Control-Max-Age": "86400",
  };
}

const json = (status, body, origin) => ({
  statusCode: status,
  headers: { "Content-Type": "application/json", ...cors(origin) },
  body: JSON.stringify(body),
});

// ---------- password ----------
async function pbkdf2(password, salt, iterations) {
  const key = await crypto.subtle.importKey("raw", enc.encode(password), "PBKDF2", false, ["deriveBits"]);
  const bits = await crypto.subtle.deriveBits({ name: "PBKDF2", salt, iterations, hash: "SHA-256" }, key, 256);
  return b64(new Uint8Array(bits));
}

async function passwordMatches(password) {
  const stored = process.env.ADMIN_PASSWORD_HASH || "";
  const [scheme, iterations, salt, hash] = stored.split("$");
  if (scheme !== "pbkdf2" || !hash) return false;
  const made = await pbkdf2(password, unb64(salt), Number(iterations));
  // constant-time-ish compare
  if (made.length !== hash.length) return false;
  let diff = 0;
  for (let i = 0; i < made.length; i++) diff |= made.charCodeAt(i) ^ hash.charCodeAt(i);
  return diff === 0;
}

// ---------- sign-in tickets ----------
async function sign(payload) {
  const body = b64url(JSON.stringify(payload));
  const key = await crypto.subtle.importKey("raw", enc.encode(process.env.SESSION_SECRET || ""), { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
  const mac = await crypto.subtle.sign("HMAC", key, enc.encode(body));
  return `${body}.${Buffer.from(mac).toString("base64url")}`;
}

async function verify(ticket) {
  if (!ticket) return null;
  const [body, mac] = ticket.split(".");
  if (!body || !mac) return null;
  const key = await crypto.subtle.importKey("raw", enc.encode(process.env.SESSION_SECRET || ""), { name: "HMAC", hash: "SHA-256" }, false, ["verify"]);
  const ok = await crypto.subtle.verify("HMAC", key, Buffer.from(mac, "base64url"), enc.encode(body));
  if (!ok) return null;
  const payload = JSON.parse(Buffer.from(body, "base64url").toString());
  return payload.exp > Math.floor(Date.now() / 1000) ? payload : null;
}

// ---------- GitHub ----------
const repo = () => process.env.GITHUB_REPO || "";
const branch = () => process.env.GITHUB_BRANCH || "main";

async function github(path, init = {}) {
  const response = await fetch(`https://api.github.com/repos/${repo()}/${path}`, {
    ...init,
    headers: {
      Accept: "application/vnd.github+json",
      Authorization: `Bearer ${process.env.GITHUB_TOKEN}`,
      "X-GitHub-Api-Version": "2022-11-28",
      ...(init.body ? { "Content-Type": "application/json" } : {}),
      ...init.headers,
    },
  });
  return response;
}

// ---------- routes ----------
export async function handler(event) {
  const origin = event.headers.origin || event.headers.Origin || "";
  const route = (event.path || "").replace(/^.*\/api\//, "").replace(/^.*\/functions\/api\/?/, "");

  if (event.httpMethod === "OPTIONS") return { statusCode: 204, headers: cors(origin), body: "" };

  const missing = ["ADMIN_EMAIL", "ADMIN_PASSWORD_HASH", "SESSION_SECRET", "GITHUB_TOKEN", "GITHUB_REPO"]
    .filter((name) => !process.env[name]);
  if (missing.length) return json(500, { error: `The server is missing: ${missing.join(", ")}` }, origin);

  // --- sign in ---
  if (route === "login" && event.httpMethod === "POST") {
    const { email = "", password = "" } = JSON.parse(event.body || "{}");
    const emailOk = email.trim().toLowerCase() === (process.env.ADMIN_EMAIL || "").trim().toLowerCase();
    const passOk = await passwordMatches(password);
    if (!emailOk || !passOk) {
      await new Promise((r) => setTimeout(r, 400));   // slow down guessing
      return json(401, { error: "Wrong email or password." }, origin);
    }
    const exp = Math.floor(Date.now() / 1000) + SESSION_HOURS * 3600;
    return json(200, { token: await sign({ email: email.trim().toLowerCase(), exp }), email, exp }, origin);
  }

  const session = await verify((event.headers.authorization || "").replace(/^Bearer /, ""));
  if (!session) return json(401, { error: "Please sign in again." }, origin);

  if (route === "me") return json(200, { email: session.email, exp: session.exp }, origin);

  // --- read a file (to get its id before writing) ---
  if (route === "file" && event.httpMethod === "GET") {
    const path = event.queryStringParameters?.path;
    if (!path) return json(400, { error: "No file asked for." }, origin);
    const response = await github(`contents/${encodeURI(path)}?ref=${branch()}`);
    if (response.status === 404) return json(404, { error: "Not found" }, origin);
    if (!response.ok) return json(response.status, { error: await response.text() }, origin);
    const data = await response.json();
    return json(200, { sha: data.sha, content: data.content }, origin);
  }

  // --- write a file (text or picture) ---
  if (route === "save" && event.httpMethod === "POST") {
    const { path, contentBase64, message } = JSON.parse(event.body || "{}");
    if (!path || !contentBase64) return json(400, { error: "Nothing to save." }, origin);
    if (path.includes("..") || path.startsWith("/")) return json(400, { error: "Bad file name." }, origin);
    // The admin may only touch content, never the code that runs the site.
    const allowed = /^(data\/|assets\/uploads\/)/.test(path);
    if (!allowed) return json(403, { error: "That file cannot be changed from the admin." }, origin);

    const head = await github(`contents/${encodeURI(path)}?ref=${branch()}`);
    const sha = head.ok ? (await head.json()).sha : undefined;
    const response = await github(`contents/${encodeURI(path)}`, {
      method: "PUT",
      body: JSON.stringify({
        message: message || `Admin: update ${path}`,
        content: contentBase64,
        branch: branch(),
        ...(sha ? { sha } : {}),
        committer: { name: "iSmile admin", email: session.email },
      }),
    });
    if (!response.ok) return json(response.status, { error: await response.text() }, origin);
    const saved = await response.json();
    return json(200, { path, commit: saved.commit?.sha }, origin);
  }

  // --- list the uploaded pictures ---
  if (route === "photos" && event.httpMethod === "GET") {
    const response = await github(`contents/assets/uploads?ref=${branch()}`);
    if (response.status === 404) return json(200, { photos: [] }, origin);
    if (!response.ok) return json(response.status, { error: await response.text() }, origin);
    const items = await response.json();
    const photos = (Array.isArray(items) ? items : [])
      .filter((item) => item.type === "file" && /\.(webp|png|jpe?g|gif)$/i.test(item.name))
      .map((item) => item.path)
      .sort()
      .reverse();
    return json(200, { photos }, origin);
  }

  return json(404, { error: "Unknown request." }, origin);
}

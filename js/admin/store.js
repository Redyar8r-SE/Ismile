// Where the admin saves its changes.
//
// There is one way in: the small server in netlify/functions/api.mjs. It checks
// your email and password, then writes to GitHub with a token that never
// reaches this browser. Nothing secret is kept here — only a signed sign-in
// ticket that expires on its own.

const SESSION_KEY = "ismile-admin-session";
const state = { api: "", token: "", email: "", repo: "", problem: "" };

const saved = () => {
  try { return JSON.parse(localStorage.getItem(SESSION_KEY) || "null"); } catch { return null; }
};
const keep = (session) => {
  try {
    if (session) localStorage.setItem(SESSION_KEY, JSON.stringify(session));
    else localStorage.removeItem(SESSION_KEY);
  } catch { /* private browsing: the sign-in just is not remembered */ }
};

// Where the server lives: the address written in data/admin-server.json, or the
// same address as this page when the server itself is serving the admin.
async function findServer() {
  let base = "";
  try {
    const response = await fetch(`data/admin-server.json?t=${Date.now()}`);
    if (response.ok) base = (await response.json()).api || "";
  } catch { /* the file is optional */ }
  return (base || `${location.origin}/api`).replace(/\/+$/, "");
}

// Returns "" when the server is ready, or a sentence saying what is wrong.
export async function init() {
  state.api = await findServer();
  // 401 means the server is there and simply wants a sign-in.
  // 503 means it is there but its settings are not finished.
  try {
    const response = await fetch(`${state.api}/me`, { headers: { Authorization: "Bearer none" } });
    const data = await response.json();
    if (response.status >= 500) state.problem = data.error || `The server answered ${response.status}.`;
    else if (data.repo) state.repo = data.repo;
  } catch {
    state.problem = "There is no admin server at this address. Open the admin at your Netlify address instead (for example ismile-2026.netlify.app/admin.html), or write that address in data/admin-server.json.";
  }

  const session = saved();
  if (!state.problem && session?.token && session.exp * 1000 > Date.now()) {
    state.token = session.token;
    state.email = session.email;
    state.repo = session.repo || state.repo;
  }
  return state.problem;
}

export const ready = () => Boolean(state.token);
export const who = () => state.email;
export const repo = () => state.repo;
export const problem = () => state.problem;

async function call(path, options = {}) {
  const response = await fetch(`${state.api}/${path}`, {
    ...options,
    headers: {
      ...(options.body ? { "Content-Type": "application/json" } : {}),
      ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}),
      ...options.headers,
    },
  });
  const data = await response.json().catch(() => ({}));
  if (response.status === 401 && path !== "login") {
    state.token = "";
    keep(null);
    throw new Error("Your sign-in ended. Please sign in again.");
  }
  if (!response.ok) throw new Error(data.error || `Server error ${response.status}`);
  return data;
}

// ---------- signing in ----------
export async function signIn(email, password) {
  const data = await call("login", { method: "POST", body: JSON.stringify({ email, password }) });
  state.token = data.token;
  state.email = data.email;
  state.repo = data.repo || state.repo;
  keep({ token: data.token, email: data.email, repo: state.repo, exp: data.exp });
  return data.email;
}

export function signOut() {
  state.token = "";
  state.email = "";
  keep(null);
}

// ---------- files ----------
const decode = (base64) => new TextDecoder().decode(Uint8Array.from(atob(base64.replace(/\n/g, "")), (c) => c.charCodeAt(0)));
const encode = (text) => {
  const bytes = new TextEncoder().encode(text);
  let binary = "";
  bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
  return btoa(binary);
};

export async function readFile(path) {
  const data = await call(`file?path=${encodeURIComponent(path)}`);
  return { sha: data.sha, text: decode(data.content) };
}

export async function writeText(path, text, message) {
  return call("save", { method: "POST", body: JSON.stringify({ path, contentBase64: encode(text), message }) });
}

export async function writeBinary(path, bytes, message) {
  let binary = "";
  bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
  return call("save", { method: "POST", body: JSON.stringify({ path, contentBase64: btoa(binary), message }) });
}

export async function listPhotos() {
  return (await call("photos")).photos || [];
}

// Where the admin saves its changes. Two ways, picked automatically:
//
//   "server" — a server (server/admin.php on your own hosting, or the Netlify
//              function) checks your email and password and writes the files.
//              Nothing secret is kept in this browser.
//   "key"    — no server answers, so the browser talks to GitHub itself with a
//              key you paste once. Quicker to set up; the key lives in this
//              browser, so whoever uses this computer can save to the site.
import * as gh from "./github.js?v=18";

const SESSION_KEY = "ismile-admin-session";
const state = { mode: "key", api: "", token: "", email: "", repo: "", problem: "" };

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
  if (!base) base = new URL("api", location.href.replace(/[^/]*$/, "")).href;
  return base.replace(/\/+$/, "");
}

// Returns "" when the server is ready, or a sentence saying what is wrong.
export async function init() {
  state.api = await findServer();
  // 401 means a server is there and simply wants a sign-in.
  // 503 means it is there but its settings are not finished.
  try {
    const response = await fetch(`${state.api}/me`, { headers: { Authorization: "Bearer none" } });
    const data = await response.json();
    state.mode = "server";
    if (response.status >= 500) state.problem = data.error || `The server answered ${response.status}.`;
    else if (data.repo) state.repo = data.repo;
  } catch {
    // No server here, so save through GitHub with a key instead.
    state.mode = "key";
    state.repo = `${gh.REPO.owner}/${gh.REPO.name}`;
  }

  const session = saved();
  if (state.mode === "server" && !state.problem && session?.token && session.exp * 1000 > Date.now()) {
    state.token = session.token;
    state.email = session.email;
    state.repo = session.repo || state.repo;
  }
  return state.problem;
}

export const mode = () => state.mode;
export const ready = () => (state.mode === "server" ? Boolean(state.token) : Boolean(gh.getToken()));
export const who = () => (state.mode === "server" ? state.email : "GitHub key");
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
  if (state.mode === "key") gh.setToken("");
}

// Key mode only: prove the key works and remember it in this browser.
export async function connectKey(token) {
  gh.setToken(token);
  try {
    return await gh.whoAmI();
  } catch (error) {
    gh.setToken("");
    throw error;
  }
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
  if (state.mode === "key") return gh.readFile(path);
  const data = await call(`file?path=${encodeURIComponent(path)}`);
  return { sha: data.sha, text: decode(data.content) };
}

export async function writeText(path, text, message) {
  if (state.mode === "key") {
    let sha;
    try { sha = (await gh.readFile(path)).sha; } catch { /* a file that is not there yet */ }
    return gh.writeFile(path, text, message, sha);
  }
  return call("save", { method: "POST", body: JSON.stringify({ path, contentBase64: encode(text), message }) });
}

export async function writeBinary(path, bytes, message) {
  if (state.mode === "key") return gh.writeFileBinary(path, bytes, message);
  let binary = "";
  bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
  return call("save", { method: "POST", body: JSON.stringify({ path, contentBase64: btoa(binary), message }) });
}

export async function listPhotos() {
  if (state.mode === "key") {
    const items = await gh.listFolder("assets/uploads");
    return items
      .filter((item) => item.type === "file" && /\.(webp|png|jpe?g|gif)$/i.test(item.name))
      .map((item) => item.path)
      .sort()
      .reverse();
  }
  return (await call("photos")).photos || [];
}

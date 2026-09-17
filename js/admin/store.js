// Where the admin saves its changes.
//
// Two ways, picked automatically when the page opens:
//   "server" — a small server (Netlify) checks your email and password and
//              holds the GitHub token. Nothing secret is kept in the browser.
//   "key"    — no server found, so the browser talks to GitHub with a key you
//              paste once. Kept as a fallback.
import * as gh from "./github.js";

const TOKEN_KEY = "ismile-admin-session";
const state = { mode: "key", api: "", token: "", email: "" };

const saved = () => {
  try { return JSON.parse(localStorage.getItem(TOKEN_KEY) || "null"); } catch { return null; }
};
const keep = (session) => {
  try {
    if (session) localStorage.setItem(TOKEN_KEY, JSON.stringify(session));
    else localStorage.removeItem(TOKEN_KEY);
  } catch { /* private browsing */ }
};

// Where the server lives: same address as this page, or written in
// data/admin-server.json when the admin is opened from GitHub Pages.
async function findServer() {
  let base = "";
  try {
    const response = await fetch(`data/admin-server.json?t=${Date.now()}`);
    if (response.ok) base = (await response.json()).api || "";
  } catch { /* file is optional */ }
  if (!base) base = `${location.origin}/api`;
  try {
    const ping = await fetch(`${base}/me`, { headers: { Authorization: "Bearer none" } });
    // 401 means the server is there and simply wants a sign-in.
    if (ping.status === 401 || ping.ok) return base;
  } catch { /* no server */ }
  return "";
}

export async function init() {
  state.api = await findServer();
  state.mode = state.api ? "server" : "key";
  const session = saved();
  if (state.mode === "server" && session?.token && session.exp * 1000 > Date.now()) {
    state.token = session.token;
    state.email = session.email;
  }
  return state.mode;
}

export const mode = () => state.mode;
export const ready = () => (state.mode === "server" ? Boolean(state.token) : Boolean(gh.getToken()));
export const who = () => (state.mode === "server" ? state.email : "GitHub key");

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
  keep({ token: data.token, email: data.email, exp: data.exp });
  return data.email;
}

export function signOut() {
  state.token = "";
  state.email = "";
  keep(null);
  if (state.mode === "key") gh.setToken("");
}

// Key mode only.
export async function connectKey(token) {
  gh.setToken(token);
  return gh.whoAmI();
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
    try { sha = (await gh.readFile(path)).sha; } catch { /* new file */ }
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

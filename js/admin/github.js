// Saving straight to GitHub from the browser.
// The key (a fine-grained personal access token with "Contents: Read and write"
// on this repository only) is kept in this browser and is never sent anywhere
// except api.github.com.

const API = "https://api.github.com";
const TOKEN_KEY = "ismile-admin-token";

export const REPO = { owner: "Redyar8r-SE", name: "Ismile", branch: "main" };

export function getToken() {
  try { return localStorage.getItem(TOKEN_KEY) || ""; } catch { return ""; }
}

export function setToken(token) {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token);
    else localStorage.removeItem(TOKEN_KEY);
  } catch { /* private browsing: the key simply is not remembered */ }
}

function headers() {
  return {
    Accept: "application/vnd.github+json",
    Authorization: `Bearer ${getToken()}`,
    "X-GitHub-Api-Version": "2022-11-28",
  };
}

async function fail(response) {
  let detail = "";
  try { detail = (await response.json()).message || ""; } catch { /* no body */ }
  const map = {
    401: "The key was refused. Create a new key and paste it again.",
    403: "The key is not allowed to write to this repository.",
    404: "Repository or file not found. Check the key has access to this repository.",
    409: "Someone else changed the file. Reload the page and try again.",
    422: "GitHub refused the change.",
  };
  return new Error(`${map[response.status] || "GitHub error"}${detail ? ` (${detail})` : ""}`);
}

// Who the key belongs to: also proves the key works.
export async function whoAmI() {
  const response = await fetch(`${API}/user`, { headers: headers() });
  if (!response.ok) throw await fail(response);
  return (await response.json()).login;
}

const toBase64 = (text) => {
  const bytes = new TextEncoder().encode(text);
  let binary = "";
  bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
  return btoa(binary);
};

export async function readFile(path, { binary = false } = {}) {
  const url = `${API}/repos/${REPO.owner}/${REPO.name}/contents/${encodeURI(path)}?ref=${REPO.branch}`;
  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw await fail(response);
  const data = await response.json();
  if (binary) return { sha: data.sha, text: "" };
  return { sha: data.sha, text: new TextDecoder().decode(Uint8Array.from(atob(data.content.replace(/\n/g, "")), (c) => c.charCodeAt(0))) };
}

export async function writeFile(path, text, message, sha) {
  const url = `${API}/repos/${REPO.owner}/${REPO.name}/contents/${encodeURI(path)}`;
  const response = await fetch(url, {
    method: "PUT",
    headers: { ...headers(), "Content-Type": "application/json" },
    body: JSON.stringify({ message, content: toBase64(text), branch: REPO.branch, ...(sha ? { sha } : {}) }),
  });
  if (!response.ok) throw await fail(response);
  return (await response.json()).commit?.sha;
}

// Binary upload (photos). GitHub replaces the file when it already exists.
export async function writeFileBinary(path, bytes, message) {
  let binary = "";
  bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
  const url = `${API}/repos/${REPO.owner}/${REPO.name}/contents/${encodeURI(path)}`;
  let sha;
  try { sha = (await readFile(path, { binary: true })).sha; } catch { /* new file */ }
  const response = await fetch(url, {
    method: "PUT",
    headers: { ...headers(), "Content-Type": "application/json" },
    body: JSON.stringify({ message, content: btoa(binary), branch: REPO.branch, ...(sha ? { sha } : {}) }),
  });
  if (!response.ok) throw await fail(response);
  return path;
}

// What is inside a folder. A folder that does not exist yet counts as empty.
export async function listFolder(path) {
  const url = `${API}/repos/${REPO.owner}/${REPO.name}/contents/${encodeURI(path)}?ref=${REPO.branch}`;
  const response = await fetch(url, { headers: headers() });
  if (response.status === 404) return [];
  if (!response.ok) throw await fail(response);
  const data = await response.json();
  return Array.isArray(data) ? data : [];
}

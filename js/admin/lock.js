// A simple lock for the admin page: email + password.
//
// What it does: stops someone who opens the admin page (or sits at your
// already-connected browser) from changing the website.
// What it does NOT do: it is not real security. The check happens inside the
// browser, so a person who knows how to read a web page can get past it. The
// real protection is the GitHub key, which never leaves your own browser.
// The password itself is never stored — only a scrambled form of it.

const FILE = "data/admin-lock.json";
const UNLOCK_KEY = "ismile-admin-unlocked";
const DAYS = 7;

const toB64 = (bytes) => btoa(String.fromCharCode(...new Uint8Array(bytes)));
const fromB64 = (text) => Uint8Array.from(atob(text), (c) => c.charCodeAt(0));

// PBKDF2 so a stolen file cannot be turned back into the password quickly.
async function scramble(password, salt) {
  const key = await crypto.subtle.importKey("raw", new TextEncoder().encode(password), "PBKDF2", false, ["deriveBits"]);
  const bits = await crypto.subtle.deriveBits(
    { name: "PBKDF2", salt, iterations: 150000, hash: "SHA-256" },
    key,
    256,
  );
  return toB64(bits);
}

export async function loadLock() {
  try {
    const response = await fetch(`${FILE}?t=${Date.now()}`);
    if (!response.ok) return null;
    const lock = await response.json();
    return lock && lock.hash ? lock : null;
  } catch {
    return null;
  }
}

export async function makeLock(email, password) {
  const salt = crypto.getRandomValues(new Uint8Array(16));
  return { email: email.trim().toLowerCase(), salt: toB64(salt), hash: await scramble(password, salt) };
}

export async function check(lock, email, password) {
  if (!lock) return false;
  if (email.trim().toLowerCase() !== lock.email) return false;
  return (await scramble(password, fromB64(lock.salt))) === lock.hash;
}

// "Stay unlocked on this browser" for a week. Tied to the current password, so
// changing the password logs everyone out.
export function remember(lock) {
  try {
    localStorage.setItem(UNLOCK_KEY, JSON.stringify({ until: Date.now() + DAYS * 864e5, hash: lock.hash.slice(0, 16) }));
  } catch { /* private browsing */ }
}

export function isRemembered(lock) {
  try {
    const saved = JSON.parse(localStorage.getItem(UNLOCK_KEY) || "null");
    return Boolean(saved && saved.until > Date.now() && saved.hash === lock.hash.slice(0, 16));
  } catch {
    return false;
  }
}

export function forget() {
  try { localStorage.removeItem(UNLOCK_KEY); } catch { /* private browsing */ }
}

export { FILE as LOCK_FILE };

// Who may open the admin page.
//
// The accounts live in data/admin-accounts.json. Each one keeps the email and a
// PBKDF2 hash of the password — never the password itself, so the file cannot
// be read back into a password.
//
// Be honest about what this is: the check happens here, in the browser, so
// somebody who knows how a web page works can get past it. It keeps ordinary
// visitors out of the admin screen. It is not a safe for secrets.

const FILE = "data/admin-accounts.json";
const UNLOCK_KEY = "ismile-admin-unlocked";   // only to clear the old one
const ITERATIONS = 150000;

const toB64 = (bytes) => btoa(String.fromCharCode(...new Uint8Array(bytes)));
const fromB64 = (text) => Uint8Array.from(atob(text), (c) => c.charCodeAt(0));
const tidy = (email) => (email || "").trim().toLowerCase();

async function scramble(password, salt) {
  const key = await crypto.subtle.importKey("raw", new TextEncoder().encode(password), "PBKDF2", false, ["deriveBits"]);
  const bits = await crypto.subtle.deriveBits({ name: "PBKDF2", salt, iterations: ITERATIONS, hash: "SHA-256" }, key, 256);
  return toB64(bits);
}

// A missing or empty file means "no accounts yet": the admin opens freely, so
// you can never shut yourself out of your own page.
export async function loadAccounts() {
  try {
    const response = await fetch(`${FILE}?t=${Date.now()}`);
    if (!response.ok) return [];
    const data = await response.json();
    const list = Array.isArray(data) ? data : data.accounts;
    return (list || []).filter((account) => account && account.email && account.salt && account.hash);
  } catch {
    return [];
  }
}

export async function makeAccount(email, password) {
  const salt = crypto.getRandomValues(new Uint8Array(16));
  return { email: tidy(email), salt: toB64(salt), hash: await scramble(password, salt) };
}

export async function check(accounts, email, password) {
  const account = accounts.find((one) => one.email === tidy(email));
  if (!account) return false;
  try {
    return (await scramble(password, fromB64(account.salt))) === account.hash;
  } catch {
    return false;
  }
}

// What the file should look like on disk.
export const fileText = (accounts) => `${JSON.stringify({ accounts }, null, 2)}\n`;

// Nothing is remembered between visits on purpose: every time the admin page
// is opened or refreshed it asks again. An old key from when it did remember
// is cleared away here.
export function forget() {
  try { localStorage.removeItem(UNLOCK_KEY); } catch { /* private browsing */ }
}

export { FILE as ACCOUNTS_FILE };

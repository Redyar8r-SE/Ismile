// Turns an email and a password into the two lines the server needs.
//
// The password itself never leaves this page and is never stored: only a
// PBKDF2 hash of it, which cannot be turned back into the password.

const toB64 = (bytes) => btoa(String.fromCharCode(...new Uint8Array(bytes)));
const ITERATIONS = 150000;

async function hash(password, salt) {
  const key = await crypto.subtle.importKey("raw", new TextEncoder().encode(password), "PBKDF2", false, ["deriveBits"]);
  const bits = await crypto.subtle.deriveBits({ name: "PBKDF2", salt, iterations: ITERATIONS, hash: "SHA-256" }, key, 256);
  return toB64(bits);
}

// -> { email, line } where `line` is the ADMIN_PASSWORD_HASH value.
export async function makeSettings(email, password) {
  const salt = crypto.getRandomValues(new Uint8Array(16));
  return {
    email: email.trim().toLowerCase(),
    line: `pbkdf2$${ITERATIONS}$${toB64(salt)}$${await hash(password, salt)}`,
  };
}

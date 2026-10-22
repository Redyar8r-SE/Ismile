// Remove the obsolete browser-only unlock flag. Real authentication is server-side.
export function forget() {
  try { localStorage.removeItem("ismile-admin-unlocked"); } catch { /* private browsing */ }
}

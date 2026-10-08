export async function loadJSON(path) {
  // no-cache: ask the server whether the file changed, so edits made in the
  // admin show up straight away instead of after the browser cache expires.
  const response = await fetch(path, { cache: "no-cache" });
  if (!response.ok) throw new Error(`Could not load ${path} (${response.status})`);
  const data = await response.json();
  if (path === "data/program.json") {
    // Visibility belongs to database Settings. A stale JSON switch cannot
    // reveal a program while the database is unavailable.
    try {
      const state = await fetch("api/site-state.php", { cache: "no-store" });
      const controls = await state.json();
      data.toBeAnnounced = !state.ok || controls.ok !== true || controls.programHidden !== false;
    } catch {
      data.toBeAnnounced = true;
    }
  }
  return data;
}

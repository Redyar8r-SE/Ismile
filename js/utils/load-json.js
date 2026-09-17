export async function loadJSON(path) {
  // no-cache: ask the server whether the file changed, so edits made in the
  // admin show up straight away instead of after the browser cache expires.
  const response = await fetch(path, { cache: "no-cache" });
  if (!response.ok) throw new Error(`Could not load ${path} (${response.status})`);
  return response.json();
}

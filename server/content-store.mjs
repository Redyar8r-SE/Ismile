import { randomUUID } from "node:crypto";
import {
  existsSync,
  mkdirSync,
  readdirSync,
  readFileSync,
  renameSync,
  writeFileSync,
} from "node:fs";
import path from "node:path";

/** Only content and images may be saved; executable uploads are forbidden.
 * @param {string} name */
export function editableContent(name) {
  return /^(data\/(?:i18n\/)?[a-z0-9-]+\.json|assets\/uploads\/[a-zA-Z0-9._-]+\.(?:png|jpe?g|webp|gif))$/.test(
    name,
  );
}

/** @param {string} root @param {string} name */
export function contentFile(root, name) {
  return editableContent(name) ? path.join(root, name) : null;
}

/** Auth is checked by the caller before entering this function.
 * @param {string} root @param {string} fallbackRoot @param {string} route
 * @param {string} method @param {Record<string, string>} query @param {string} body */
export function contentRequest(root, fallbackRoot, route, method, query, body) {
  /** @param {number} statusCode @param {unknown} value */
  const reply = (statusCode, value) => ({
    statusCode,
    headers: { "Content-Type": "application/json", "Cache-Control": "no-store" },
    body: JSON.stringify(value),
  });
  if (route === "file" && method === "GET") {
    const file = contentFile(root, query.path || "");
    const fallback = contentFile(fallbackRoot, query.path || "");
    if (!file || !fallback) return reply(403, { error: "That file cannot be edited." });
    const source = existsSync(file) ? file : fallback;
    if (!existsSync(source)) return reply(404, { error: "Not found" });
    return reply(200, { sha: null, content: readFileSync(source).toString("base64") });
  }
  if (route === "save" && method === "POST") {
    let input;
    try {
      input = JSON.parse(body);
    } catch {
      return reply(400, { error: "Invalid save request." });
    }
    if (typeof input?.path !== "string" || typeof input?.contentBase64 !== "string") {
      return reply(400, { error: "Nothing to save." });
    }
    const file = contentFile(root, input.path);
    if (!file) return reply(403, { error: "That file cannot be edited." });
    let bytes = Buffer.from(input.contentBase64, "base64");
    if (!bytes.length || bytes.length > 10 * 1024 * 1024)
      return reply(400, { error: "Invalid file size." });
    if (input.path.endsWith(".json")) {
      try {
        const value = JSON.parse(bytes.toString("utf8"));
        if (!value || typeof value !== "object") throw new Error("Invalid content");
        // These old file switches no longer control the website.
        if (input.path === "data/tickets.json") delete value.registrationClosed;
        if (input.path === "data/program.json") delete value.toBeAnnounced;
        bytes = Buffer.from(`${JSON.stringify(value, null, 2)}\n`);
      } catch {
        return reply(400, { error: "The content must be valid JSON." });
      }
    }
    mkdirSync(path.dirname(file), { recursive: true });
    const temporary = `${file}.${randomUUID()}.writing`;
    writeFileSync(temporary, bytes, { flag: "wx" });
    renameSync(temporary, file);
    return reply(200, { path: input.path, live: true });
  }
  if (route === "photos" && method === "GET") {
    const photos = new Set();
    for (const base of [fallbackRoot, root]) {
      const folder = path.join(base, "assets/uploads");
      if (!existsSync(folder)) continue;
      for (const item of readdirSync(folder)) {
        const name = `assets/uploads/${item}`;
        if (editableContent(name)) photos.add(name);
      }
    }
    return reply(200, { photos: [...photos].sort().reverse() });
  }
  return reply(405, { error: "Method not allowed." });
}

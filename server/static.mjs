/**
 * @typedef {import('node:http').ServerResponse} ServerResponse
 */

import { createReadStream, existsSync, readFileSync, statSync } from "node:fs";
import path from "node:path";

/** @type {Record<string, string>} */
export const TYPES = {
  ".html": "text/html; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".js": "text/javascript; charset=utf-8",
  ".mjs": "text/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".svg": "image/svg+xml",
  ".png": "image/png",
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
  ".webp": "image/webp",
  ".gif": "image/gif",
  ".ico": "image/x-icon",
  ".woff": "font/woff",
  ".woff2": "font/woff2",
  ".pdf": "application/pdf",
  ".txt": "text/plain; charset=utf-8",
  ".xml": "application/xml; charset=utf-8",
  ".map": "application/json; charset=utf-8",
};

/**
 * @param {string} filePath
 * @returns {string}
 */
export function contentType(filePath) {
  return TYPES[path.extname(filePath).toLowerCase()] || "application/octet-stream";
}

/**
 * Resolve a request path under root, or null if it escapes.
 * @param {string} root
 * @param {string} requestPath
 * @returns {string | null}
 */
export function safeJoin(root, requestPath) {
  const decoded = decodeURIComponent(requestPath.split("?")[0] || "");
  const relative = decoded.replace(/^[/\\]+/, "");
  const resolvedRoot = path.resolve(root);
  const full = path.resolve(resolvedRoot, relative);
  const rootWithSep = resolvedRoot.endsWith(path.sep) ? resolvedRoot : `${resolvedRoot}${path.sep}`;
  if (full !== resolvedRoot && !full.startsWith(rootWithSep)) return null;
  return full;
}

/**
 * Only the website itself is public. Everything else in the deploy folder
 * (the .env secrets, server and function code, docs, package files) must
 * never be served, so this is an allow-list, not a block-list.
 */
export const PUBLIC_FILES = new Set([
  "index.html",
  "admin.html",
  "register.html",
  "sponsor.html",
  "workshops.html",
  "payment.html",
  "favicon.ico",
  "robots.txt",
]);
export const PUBLIC_DIRS = new Set(["assets", "css", "js", "data"]);

/**
 * @param {string} pathname
 * @returns {boolean}
 */
export function isPublicPath(pathname) {
  let decoded;
  try {
    decoded = decodeURIComponent(pathname.split("?")[0] || "");
  } catch {
    return false;
  }
  const segments = decoded.split(/[/\\]+/).filter(Boolean);
  if (segments.length === 0) return true;
  // No hidden files or folders anywhere (.env, .git, .github, ...).
  if (segments.some((segment) => segment.startsWith("."))) return false;
  if (segments.length === 1) return PUBLIC_FILES.has(segments[0]);
  return PUBLIC_DIRS.has(segments[0]);
}

/**
 * @param {string} root
 * @param {string} pathname
 * @returns {{ status: number, filePath?: string, message?: string }}
 */
export function resolveStaticFile(root, pathname) {
  if (!isPublicPath(pathname)) return { status: 404, message: "Not found" };
  let filePath = safeJoin(root, pathname === "/" ? "/index.html" : pathname);
  if (!filePath) return { status: 400, message: "Bad path" };
  if (existsSync(filePath) && statSync(filePath).isDirectory()) {
    filePath = path.join(filePath, "index.html");
  }
  if (!existsSync(filePath) || !statSync(filePath).isFile()) {
    return { status: 404, message: "Not found" };
  }
  return { status: 200, filePath };
}

/**
 * @param {ServerResponse} res
 * @param {string} filePath
 */
export function sendFile(res, filePath) {
  const type = contentType(filePath);
  res.writeHead(200, { "Content-Type": type });
  createReadStream(filePath).pipe(res);
}

/**
 * Load KEY=VALUE lines into process.env without overwriting existing keys.
 * @param {string} filePath
 * @param {string} [text] optional contents (for tests); otherwise read from disk
 */
export function loadEnvFile(filePath, text) {
  /** @type {string | undefined} */
  let contents = text;
  if (contents === undefined) {
    if (!existsSync(filePath)) return;
    contents = readFileSync(filePath, "utf8");
  }
  for (const line of contents.split("\n")) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith("#") || !trimmed.includes("=")) continue;
    const eq = trimmed.indexOf("=");
    const key = trimmed.slice(0, eq).trim();
    let value = trimmed.slice(eq + 1).trim();
    if (
      (value.startsWith("'") && value.endsWith("'")) ||
      (value.startsWith('"') && value.endsWith('"'))
    ) {
      value = value.slice(1, -1);
    }
    if (key && process.env[key] === undefined) process.env[key] = value;
  }
}

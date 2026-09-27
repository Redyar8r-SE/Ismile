import { createServer } from "node:http";
import { readFileSync, createReadStream, existsSync, statSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { handler } from "../netlify/functions/api.mjs";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, "..");

function loadEnvFile(filePath) {
  if (!existsSync(filePath)) return;
  for (const line of readFileSync(filePath, "utf8").split("\n")) {
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

loadEnvFile(path.join(ROOT, ".env"));

const PORT = Number(process.env.PORT || process.env.APP_PORT || 3000);

const TYPES = {
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

function contentType(filePath) {
  return TYPES[path.extname(filePath).toLowerCase()] || "application/octet-stream";
}

async function readBody(req) {
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  return Buffer.concat(chunks).toString("utf8");
}

function queryFromUrl(url) {
  const params = {};
  for (const [key, value] of url.searchParams.entries()) params[key] = value;
  return params;
}

async function handleApi(req, res, url) {
  const body = ["POST", "PUT", "PATCH"].includes(req.method || "")
    ? await readBody(req)
    : "";
  const event = {
    path: url.pathname,
    httpMethod: req.method || "GET",
    headers: Object.fromEntries(
      Object.entries(req.headers).map(([key, value]) => [
        key,
        Array.isArray(value) ? value.join(",") : value || "",
      ])
    ),
    body,
    queryStringParameters: queryFromUrl(url),
  };
  const result = await handler(event);
  const headers = result.headers || {};
  res.writeHead(result.statusCode || 500, headers);
  res.end(result.body || "");
}

function safeJoin(root, requestPath) {
  const decoded = decodeURIComponent(requestPath.split("?")[0]);
  const cleaned = path.normalize(decoded).replace(/^(\.\.[/\\])+/, "");
  const full = path.join(root, cleaned);
  if (!full.startsWith(root)) return null;
  return full;
}

function sendFile(res, filePath) {
  const type = contentType(filePath);
  res.writeHead(200, { "Content-Type": type });
  createReadStream(filePath).pipe(res);
}

const server = createServer(async (req, res) => {
  try {
    const url = new URL(req.url || "/", `http://${req.headers.host || "localhost"}`);
    if (url.pathname === "/api" || url.pathname.startsWith("/api/")) {
      await handleApi(req, res, url);
      return;
    }

    let filePath = safeJoin(ROOT, url.pathname === "/" ? "/index.html" : url.pathname);
    if (!filePath) {
      res.writeHead(400).end("Bad path");
      return;
    }
    if (existsSync(filePath) && statSync(filePath).isDirectory()) {
      filePath = path.join(filePath, "index.html");
    }
    if (!existsSync(filePath) || !statSync(filePath).isFile()) {
      res.writeHead(404, { "Content-Type": "text/plain; charset=utf-8" }).end("Not found");
      return;
    }
    sendFile(res, filePath);
  } catch (error) {
    console.error(error);
    res.writeHead(500, { "Content-Type": "text/plain; charset=utf-8" }).end("Server error");
  }
});

server.listen(PORT, "0.0.0.0", () => {
  console.log(`ismile listening on ${PORT} root=${ROOT}`);
});

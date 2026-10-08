import { existsSync } from "node:fs";
import { createServer } from "node:http";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { handler } from "../netlify/functions/api.mjs";
import { boothAvailability } from "./booths.mjs";
import { contentFile, contentRequest } from "./content-store.mjs";
import { registrationRequest } from "./registration.mjs";
import { loadEnvFile, resolveStaticFile, sendFile } from "./static.mjs";
import { websiteState } from "./website-state.mjs";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, "..");

loadEnvFile(path.join(ROOT, ".env"));

const PORT = Number(process.env.PORT || process.env.APP_PORT || 3000);
const CONTENT_ROOT = process.env.CONTENT_ROOT || path.join(ROOT, ".site-content");

/**
 * @param {import('node:http').IncomingMessage} req
 * @returns {Promise<string>}
 */
async function readBody(req) {
  /** @type {Buffer[]} */
  const chunks = [];
  for await (const chunk of req) chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk));
  return Buffer.concat(chunks).toString("utf8");
}

/**
 * @param {URL} url
 * @returns {Record<string, string>}
 */
function queryFromUrl(url) {
  /** @type {Record<string, string>} */
  const params = {};
  for (const [key, value] of url.searchParams.entries()) params[key] = value;
  return params;
}

/**
 * @param {import('node:http').IncomingMessage} req
 * @param {import('node:http').ServerResponse} res
 * @param {URL} url
 */
async function handleApi(req, res, url) {
  const body = ["POST", "PUT", "PATCH"].includes(req.method || "") ? await readBody(req) : "";
  const event = {
    path: url.pathname,
    httpMethod: req.method || "GET",
    headers: Object.fromEntries(
      Object.entries(req.headers).map(([key, value]) => [
        key,
        Array.isArray(value) ? value.join(",") : value || "",
      ]),
    ),
    body,
    queryStringParameters: queryFromUrl(url),
  };
  const route = url.pathname.replace(/^\/api\//, "");
  let result;
  if (["file", "save", "photos"].includes(route)) {
    const auth = await handler({ ...event, path: "/api/me", httpMethod: "GET" });
    result =
      auth.statusCode === 200
        ? contentRequest(
            CONTENT_ROOT,
            ROOT,
            route,
            event.httpMethod,
            event.queryStringParameters,
            body,
          )
        : auth;
  } else {
    result = await handler(event);
  }
  const headers = result.headers || {};
  res.writeHead(result.statusCode || 500, headers);
  res.end(result.body || "");
}

export function createAppServer() {
  return createServer(async (req, res) => {
    try {
      const url = new URL(req.url || "/", `http://${req.headers.host || "localhost"}`);
      if (url.pathname === "/api/register.php") {
        const result = await registrationRequest(req);
        res.writeHead(result.statusCode, result.headers);
        res.end(result.body);
        return;
      }
      if (["/api/site-state.php", "/api/config.php"].includes(url.pathname)) {
        if (req.method !== "GET") {
          res
            .writeHead(405, { "Content-Type": "application/json", "Cache-Control": "no-store" })
            .end('{"ok":false,"error":"method"}');
          return;
        }
        const result = await websiteState(url.pathname.slice(5), url.searchParams);
        res.writeHead(result.statusCode, result.headers);
        res.end(result.body);
        return;
      }
      if (url.pathname === "/api/booths.php") {
        const result = await boothAvailability(req.method);
        res.writeHead(result.statusCode, result.headers);
        res.end(result.body);
        return;
      }
      if (url.pathname === "/api" || url.pathname.startsWith("/api/")) {
        await handleApi(req, res, url);
        return;
      }

      const override = contentFile(CONTENT_ROOT, url.pathname.slice(1));
      if (override && existsSync(override)) {
        res.setHeader("Cache-Control", "no-store");
        sendFile(res, override);
        return;
      }
      const resolved = resolveStaticFile(ROOT, url.pathname);
      if (resolved.status !== 200 || !resolved.filePath) {
        res
          .writeHead(resolved.status, { "Content-Type": "text/plain; charset=utf-8" })
          .end(resolved.message || "Error");
        return;
      }
      sendFile(res, resolved.filePath);
    } catch (error) {
      console.error(error);
      res.writeHead(500, { "Content-Type": "text/plain; charset=utf-8" }).end("Server error");
    }
  });
}

// PM2 and plain `node server/node-server.mjs` both need to listen.
// Skip only when Vitest (or an explicit flag) imports this module.
const shouldListen = process.env.VITEST !== "true" && process.env.ISMILE_NO_LISTEN !== "1";

if (shouldListen) {
  const server = createAppServer();
  server.listen(PORT, "0.0.0.0", () => {
    console.log(`ismile listening on ${PORT} root=${ROOT}`);
  });
}

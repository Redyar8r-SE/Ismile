import { createServer } from "node:http";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { handler } from "../netlify/functions/api.mjs";
import { loadEnvFile, resolveStaticFile, sendFile } from "./static.mjs";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, "..");

loadEnvFile(path.join(ROOT, ".env"));

const PORT = Number(process.env.PORT || process.env.APP_PORT || 3000);

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
  const result = await handler(event);
  const headers = result.headers || {};
  res.writeHead(result.statusCode || 500, headers);
  res.end(result.body || "");
}

export function createAppServer() {
  return createServer(async (req, res) => {
    try {
      const url = new URL(req.url || "/", `http://${req.headers.host || "localhost"}`);
      if (url.pathname === "/api" || url.pathname.startsWith("/api/")) {
        await handleApi(req, res, url);
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

const isMain =
  process.argv[1] && path.resolve(fileURLToPath(import.meta.url)) === path.resolve(process.argv[1]);

if (isMain) {
  const server = createAppServer();
  server.listen(PORT, "0.0.0.0", () => {
    console.log(`ismile listening on ${PORT} root=${ROOT}`);
  });
}

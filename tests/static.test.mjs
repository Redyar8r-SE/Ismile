import { mkdirSync, mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { afterEach, describe, expect, it } from "vitest";
import {
  contentType,
  isPublicPath,
  loadEnvFile,
  parseRange,
  resolveStaticFile,
  safeJoin,
} from "../server/static.mjs";

describe("static helpers", () => {
  /** @type {string[]} */
  const keys = [];

  afterEach(() => {
    for (const key of keys) delete process.env[key];
    keys.length = 0;
  });

  it("maps common extensions", () => {
    expect(contentType("x.html")).toContain("text/html");
    expect(contentType("x.webp")).toBe("image/webp");
    expect(contentType("x.bin")).toBe("application/octet-stream");
    expect(contentType("x.mp4")).toBe("video/mp4");
  });

  it("parses byte ranges for video on phones", () => {
    expect(parseRange(undefined, 100)).toBeNull();
    expect(parseRange("bytes=0-1", 100)).toEqual({ start: 0, end: 1 });
    expect(parseRange("bytes=10-", 100)).toEqual({ start: 10, end: 99 });
    expect(parseRange("bytes=-20", 100)).toEqual({ start: 80, end: 99 });
    expect(parseRange("bytes=50-500", 100)).toEqual({ start: 50, end: 99 });
    expect(parseRange("bytes=100-", 100)).toBe("invalid");
    expect(parseRange("bytes=0-1,5-6", 100)).toBeNull();
  });

  it("blocks path traversal outside root", () => {
    const root = "/opt/ismile";
    expect(safeJoin(root, "/index.html")).toBe(path.join(root, "index.html"));
    expect(safeJoin(root, "/../etc/passwd")).toBeNull();
    expect(safeJoin(root, "/%2e%2e/etc/passwd")).toBeNull();
  });

  it("resolves existing public files and 404s missing ones", () => {
    const root = mkdtempSync(path.join(tmpdir(), "ismile-static-"));
    writeFileSync(path.join(root, "index.html"), "<h1>ok</h1>");
    mkdirSync(path.join(root, "css"));
    writeFileSync(path.join(root, "css", "main.css"), "body{}");

    expect(resolveStaticFile(root, "/").status).toBe(200);
    expect(resolveStaticFile(root, "/css/main.css").filePath).toBe(
      path.join(root, "css", "main.css"),
    );
    expect(resolveStaticFile(root, "/missing.html").status).toBe(404);
    expect(resolveStaticFile(root, "/../outside").status).toBe(404);
  });

  it("never serves secrets, code or internal documents", () => {
    const root = mkdtempSync(path.join(tmpdir(), "ismile-static-"));
    writeFileSync(path.join(root, ".env"), "GITHUB_TOKEN=secret");
    writeFileSync(path.join(root, "package.json"), "{}");
    writeFileSync(path.join(root, "ecosystem.config.cjs"), "");
    for (const dir of ["docs", "server", "netlify", "css"]) mkdirSync(path.join(root, dir));
    writeFileSync(path.join(root, "docs", "plan.html"), "private");
    writeFileSync(path.join(root, "server", "node-server.mjs"), "code");
    writeFileSync(path.join(root, "css", ".env"), "secret");

    for (const bad of [
      "/.env",
      "/%2eenv",
      "/.git/config",
      "/package.json",
      "/ecosystem.config.cjs",
      "/docs/plan.html",
      "/docs",
      "/server/node-server.mjs",
      "/netlify/functions/api.mjs",
      "/css/.env",
      "/%2e%2e/%2e%2e/etc/passwd",
    ]) {
      expect(resolveStaticFile(root, bad).status, bad).toBe(404);
      expect(isPublicPath(bad), bad).toBe(false);
    }
    for (const good of [
      "/",
      "/index.html",
      "/register.html",
      "/css/main.css",
      "/js/main.js",
      "/data/i18n/ku.json",
      "/assets/logo.png",
    ]) {
      expect(isPublicPath(good), good).toBe(true);
    }
  });

  it("loads env without overwriting existing values", () => {
    process.env.KEEP_ME = "original";
    keys.push("KEEP_ME", "NEW_KEY");
    loadEnvFile("/unused", "KEEP_ME=replaced\nNEW_KEY=hello\n# comment\n");
    expect(process.env.KEEP_ME).toBe("original");
    expect(process.env.NEW_KEY).toBe("hello");
  });
});

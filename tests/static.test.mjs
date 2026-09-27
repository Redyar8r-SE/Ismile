import { mkdirSync, mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { afterEach, describe, expect, it } from "vitest";
import { contentType, loadEnvFile, resolveStaticFile, safeJoin } from "../server/static.mjs";

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
  });

  it("blocks path traversal outside root", () => {
    const root = "/opt/ismile";
    expect(safeJoin(root, "/index.html")).toBe(path.join(root, "index.html"));
    expect(safeJoin(root, "/../etc/passwd")).toBeNull();
    expect(safeJoin(root, "/%2e%2e/etc/passwd")).toBeNull();
  });

  it("resolves existing files and 404s missing ones", () => {
    const root = mkdtempSync(path.join(tmpdir(), "ismile-static-"));
    writeFileSync(path.join(root, "index.html"), "<h1>ok</h1>");
    mkdirSync(path.join(root, "docs"));
    writeFileSync(path.join(root, "docs", "index.html"), "<h1>docs</h1>");

    expect(resolveStaticFile(root, "/").status).toBe(200);
    expect(resolveStaticFile(root, "/docs").filePath).toBe(path.join(root, "docs", "index.html"));
    expect(resolveStaticFile(root, "/missing.html").status).toBe(404);
    expect(resolveStaticFile(root, "/../outside").status).toBe(400);
  });

  it("loads env without overwriting existing values", () => {
    process.env.KEEP_ME = "original";
    keys.push("KEEP_ME", "NEW_KEY");
    loadEnvFile("/unused", "KEEP_ME=replaced\nNEW_KEY=hello\n# comment\n");
    expect(process.env.KEEP_ME).toBe("original");
    expect(process.env.NEW_KEY).toBe("hello");
  });
});

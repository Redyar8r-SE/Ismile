import { createHmac } from "node:crypto";
import { mkdtempSync, readFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { afterEach, describe, expect, it, vi } from "vitest";
import { contentRequest, editableContent } from "../server/content-store.mjs";
import { websiteState } from "../server/website-state.mjs";

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe("website content and database controls", () => {
  it("saves live translations and reads the same content after restart", async () => {
    const root = mkdtempSync(path.join(tmpdir(), "ismile-content-"));
    vi.stubEnv("CONTENT_ROOT", root);
    vi.stubEnv("ISMILE_NO_LISTEN", "1");
    for (const key of ["ADMIN_EMAIL", "ADMIN_PASSWORD_HASH", "GITHUB_TOKEN", "GITHUB_REPO"])
      vi.stubEnv(key, "test-only");
    vi.stubEnv("SESSION_SECRET", "test-secret");
    vi.resetModules();
    const { createAppServer } = await import("../server/node-server.mjs");
    const payload = Buffer.from(
      JSON.stringify({ email: "test@example.invalid", exp: Math.floor(Date.now() / 1000) + 60 }),
    ).toString("base64url");
    const token = `${payload}.${createHmac("sha256", "test-secret").update(payload).digest("base64url")}`;
    const server = createAppServer();
    await new Promise((resolve) => server.listen(0, "127.0.0.1", () => resolve(undefined)));
    const address = server.address();
    if (!address || typeof address === "string") throw new Error("No test address");
    const base = `http://127.0.0.1:${address.port}`;
    const savedText = { hero_title: "Changed live", arabic: "نص جديد" };
    try {
      const body = JSON.stringify({
        path: "data/i18n/en.json",
        contentBase64: Buffer.from(JSON.stringify(savedText)).toString("base64"),
      });
      const denied = await fetch(`${base}/api/save`, { method: "POST", body });
      expect(denied.status).toBe(401);
      const saved = await fetch(`${base}/api/save`, {
        method: "POST",
        body,
        headers: { Authorization: `Bearer ${token}` },
      });
      expect(saved.status).toBe(200);
      const publicFile = await fetch(`${base}/data/i18n/en.json`);
      expect(publicFile.headers.get("Cache-Control")).toBe("no-store");
      expect(await publicFile.json()).toEqual(savedText);
      expect(JSON.parse(readFileSync(path.join(root, "data/i18n/en.json"), "utf8"))).toEqual(
        savedText,
      );
      expect(
        JSON.parse(
          contentRequest(root, "unused", "file", "GET", { path: "data/i18n/en.json" }, "").body,
        ).content,
      ).toBe(Buffer.from(`${JSON.stringify(savedText, null, 2)}\n`).toString("base64"));
    } finally {
      await new Promise((resolve, reject) =>
        server.close((error) => (error ? reject(error) : resolve(undefined))),
      );
    }
  });

  it.each([
    "../secret",
    "data/../config.json",
    "assets/uploads/script.php",
    "data/code.js",
    "data/i18n/../../secret.json",
  ])("rejects executable files and traversal: %s", (name) => {
    expect(editableContent(name)).toBe(false);
  });

  it("does not save malformed JSON or old registration/program switches", () => {
    const root = mkdtempSync(path.join(tmpdir(), "ismile-content-"));
    const request = (/** @type {string} */ name, /** @type {string} */ text) =>
      contentRequest(
        root,
        "unused",
        "save",
        "POST",
        {},
        JSON.stringify({ path: name, contentBase64: Buffer.from(text).toString("base64") }),
      );
    expect(request("data/program.json", "broken").statusCode).toBe(400);
    expect(request("data/program.json", '{"toBeAnnounced":false,"days":[]}').statusCode).toBe(200);
    expect(JSON.parse(readFileSync(path.join(root, "data/program.json"), "utf8"))).toEqual({
      days: [],
    });
    expect(
      request("data/tickets.json", '{"registrationClosed":false,"professional":50000}').statusCode,
    ).toBe(200);
    expect(JSON.parse(readFileSync(path.join(root, "data/tickets.json"), "utf8"))).toEqual({
      professional: 50000,
    });
  });

  it("reads database switches without exposing private fields or credentials", async () => {
    vi.stubEnv("BOOTH_API_URL", "https://backend.example/api/booths.php");
    vi.stubEnv("BOOTH_API_USERNAME", "server-only");
    vi.stubEnv("BOOTH_API_PASSWORD", "server-password");
    const mock = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          ok: true,
          registrationOpen: false,
          programHidden: true,
          private: "hidden",
        }),
      ),
    );
    vi.stubGlobal("fetch", mock);
    const response = await websiteState("site-state.php");
    expect(JSON.parse(response.body)).toEqual({
      ok: true,
      registrationOpen: false,
      programHidden: true,
    });
    expect(mock.mock.calls[0][0].href).toBe("https://backend.example/api/site-state.php");
    expect(response.headers["Cache-Control"]).toBe("no-store");
    expect(response.body).not.toContain("server-password");
  });

  it("fails closed on unavailable or malformed database state", async () => {
    vi.stubEnv("BOOTH_API_URL", "https://backend.example/api/booths.php");
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(new Response('{"ok":true,"programHidden":"false"}')),
    );
    expect((await websiteState("site-state.php")).statusCode).toBe(503);
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("Offline")));
    expect((await websiteState("site-state.php")).statusCode).toBe(503);
  });
});

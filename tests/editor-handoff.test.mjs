import { Readable } from "node:stream";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { handler } from "../netlify/functions/api.mjs";
import { editorHandoff } from "../server/editor-handoff.mjs";

describe("opening the website editor from the database (one sign-in)", () => {
  const pass = `${"a".repeat(40)}.${"b".repeat(43)}`;
  beforeEach(() => {
    vi.stubEnv("WEBSITE_API_URL", "https://backend.example/api/");
    vi.stubEnv("BOOTH_API_USERNAME", "staff-server");
    vi.stubEnv("BOOTH_API_PASSWORD", "test-server-password");
    vi.stubEnv("SESSION_SECRET", "test-session-secret");
    for (const name of ["ADMIN_EMAIL", "ADMIN_PASSWORD_HASH", "GITHUB_TOKEN", "GITHUB_REPO"])
      vi.stubEnv(name, "x");
  });
  afterEach(() => {
    vi.unstubAllEnvs();
    vi.unstubAllGlobals();
  });
  /** A request body the route can read, like Node's own. @param {unknown} body @param {string} [method] */
  const request = (body, method = "POST") =>
    /** @type {import('node:http').IncomingMessage} */ (
      /** @type {unknown} */ (
        Object.assign(
          Readable.from([Buffer.from(typeof body === "string" ? body : JSON.stringify(body))]),
          { method },
        )
      )
    );
  /** @param {unknown} body @param {number} [status] */
  const upstream = (body, status = 200) => {
    const mock = vi.fn().mockResolvedValue(new Response(JSON.stringify(body), { status }));
    vi.stubGlobal("fetch", mock);
    return mock;
  };

  it("signs in the person the database names, with a working editor session", async () => {
    const fetchMock = upstream({ ok: true, email: "Owner@Example.com" });
    const response = await editorHandoff(request({ pass }));
    expect(response.statusCode).toBe(200);
    const session = JSON.parse(response.body);
    expect(session.email).toBe("owner@example.com");
    const url = new URL(fetchMock.mock.calls[0][0]);
    expect(url.pathname).toBe("/api/editor-handoff.php");
    expect(url.searchParams.get("t")).toBe(pass);
    expect(fetchMock.mock.calls[0][1].headers.Authorization).toContain("Basic ");
    const me = await handler({
      path: "/api/me",
      httpMethod: "GET",
      headers: { authorization: `Bearer ${session.token}` },
    });
    expect(me.statusCode).toBe(200);
    expect(JSON.parse(me.body).email).toBe("owner@example.com");
  });
  it("refuses a pass the database rejects (used, late or forged)", async () => {
    upstream({ ok: false, error: "handoff" }, 401);
    const response = await editorHandoff(request({ pass }));
    expect(response.statusCode).toBe(401);
    expect(response.body).not.toContain("token");
  });
  it("never asks the database about something that is not a pass", async () => {
    const fetchMock = upstream({ ok: true, email: "owner@example.com" });
    for (const body of [
      { pass: "short" },
      { pass: "../../etc" },
      "not json",
      { pass: `${pass}?x=1` },
    ]) {
      expect((await editorHandoff(request(body))).statusCode).toBe(401);
    }
    expect(fetchMock).not.toHaveBeenCalled();
  });
  it("answers only POST, and only when the editor server is set up", async () => {
    upstream({ ok: true, email: "owner@example.com" });
    expect((await editorHandoff(request({ pass }, "GET"))).statusCode).toBe(405);
    vi.stubEnv("SESSION_SECRET", "");
    expect((await editorHandoff(request({ pass }))).statusCode).toBe(503);
  });
  it("gives no session when the database cannot be reached", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("offline")));
    expect((await editorHandoff(request({ pass }))).statusCode).toBe(401);
  });
});

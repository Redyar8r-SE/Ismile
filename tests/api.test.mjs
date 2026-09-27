import { pbkdf2Sync, randomBytes } from "node:crypto";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { handler } from "../netlify/functions/api.mjs";

const PASSWORD = "test-password-please";

/** @param {string} password */
function makeHash(password) {
  const salt = randomBytes(16);
  const hash = pbkdf2Sync(password, salt, 150000, 32, "sha256").toString("base64");
  return `pbkdf2$150000$${salt.toString("base64")}$${hash}`;
}

/** @param {Record<string, unknown>} [partial] */
function event(partial = {}) {
  return {
    path: "/api/me",
    httpMethod: "GET",
    headers: { origin: "https://ismile.krd" },
    body: "",
    queryStringParameters: {},
    ...partial,
  };
}

describe("admin API handler", () => {
  /** @type {Record<string, string | undefined>} */
  let saved;

  beforeEach(() => {
    saved = {
      ADMIN_EMAIL: process.env.ADMIN_EMAIL,
      ADMIN_PASSWORD_HASH: process.env.ADMIN_PASSWORD_HASH,
      SESSION_SECRET: process.env.SESSION_SECRET,
      GITHUB_TOKEN: process.env.GITHUB_TOKEN,
      GITHUB_REPO: process.env.GITHUB_REPO,
      GITHUB_BRANCH: process.env.GITHUB_BRANCH,
      ALLOWED_ORIGIN: process.env.ALLOWED_ORIGIN,
      ISMILE_LOGIN_DELAY_MS: process.env.ISMILE_LOGIN_DELAY_MS,
    };
    process.env.ADMIN_EMAIL = "admin@ismile.krd";
    process.env.ADMIN_PASSWORD_HASH = makeHash(PASSWORD);
    process.env.SESSION_SECRET = "unit-test-session-secret";
    process.env.GITHUB_TOKEN = "ghs_test_token";
    process.env.GITHUB_REPO = "Redyar8r-SE/Ismile";
    process.env.GITHUB_BRANCH = "main";
    process.env.ALLOWED_ORIGIN = "https://ismile.krd,https://www.ismile.krd";
    process.env.ISMILE_LOGIN_DELAY_MS = "0";
  });

  afterEach(() => {
    for (const [key, value] of Object.entries(saved)) {
      if (value === undefined) delete process.env[key];
      else process.env[key] = value;
    }
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("returns 503 when required secrets are missing", async () => {
    delete process.env.GITHUB_TOKEN;
    const res = await handler(event({ path: "/api/me" }));
    expect(res.statusCode).toBe(503);
    expect(JSON.parse(res.body).error).toMatch(/GITHUB_TOKEN/);
  });

  it("answers OPTIONS with CORS headers", async () => {
    const res = await handler(event({ httpMethod: "OPTIONS", path: "/api/login" }));
    expect(res.statusCode).toBe(204);
    expect(res.headers["Access-Control-Allow-Origin"]).toBe("https://ismile.krd");
  });

  it("rejects bad login credentials", async () => {
    const res = await handler(
      event({
        path: "/api/login",
        httpMethod: "POST",
        body: JSON.stringify({ email: "admin@ismile.krd", password: "wrong" }),
      }),
    );
    expect(res.statusCode).toBe(401);
  });

  it("logs in and serves /me with the session token", async () => {
    const login = await handler(
      event({
        path: "/api/login",
        httpMethod: "POST",
        body: JSON.stringify({ email: "admin@ismile.krd", password: PASSWORD }),
      }),
    );
    expect(login.statusCode).toBe(200);
    const { token, repo } = JSON.parse(login.body);
    expect(token).toBeTruthy();
    expect(repo).toBe("Redyar8r-SE/Ismile");

    const me = await handler(
      event({
        path: "/api/me",
        headers: {
          origin: "https://ismile.krd",
          authorization: `Bearer ${token}`,
        },
      }),
    );
    expect(me.statusCode).toBe(200);
    expect(JSON.parse(me.body).email).toBe("admin@ismile.krd");
  });

  it("rejects /me without a valid token", async () => {
    const res = await handler(
      event({
        path: "/api/me",
        headers: { origin: "https://ismile.krd", authorization: "Bearer none" },
      }),
    );
    expect(res.statusCode).toBe(401);
  });

  it("blocks save outside data/ and assets/uploads/", async () => {
    const login = await handler(
      event({
        path: "/api/login",
        httpMethod: "POST",
        body: JSON.stringify({ email: "admin@ismile.krd", password: PASSWORD }),
      }),
    );
    const { token } = JSON.parse(login.body);

    const res = await handler(
      event({
        path: "/api/save",
        httpMethod: "POST",
        headers: {
          origin: "https://ismile.krd",
          authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          path: "server/node-server.mjs",
          contentBase64: Buffer.from("x").toString("base64"),
        }),
      }),
    );
    expect(res.statusCode).toBe(403);
  });

  it("saves allowed content paths via GitHub Contents API", async () => {
    const login = await handler(
      event({
        path: "/api/login",
        httpMethod: "POST",
        body: JSON.stringify({ email: "admin@ismile.krd", password: PASSWORD }),
      }),
    );
    const { token } = JSON.parse(login.body);

    const fetchMock = vi.fn(async (_url, init) => {
      if (init?.method === "PUT") {
        return new Response(JSON.stringify({ commit: { sha: "abc123" } }), { status: 200 });
      }
      // HEAD/get existing file
      return new Response("Not Found", { status: 404 });
    });
    vi.stubGlobal("fetch", fetchMock);

    const res = await handler(
      event({
        path: "/api/save",
        httpMethod: "POST",
        headers: {
          origin: "https://ismile.krd",
          authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          path: "data/site.json",
          contentBase64: Buffer.from("{}").toString("base64"),
          message: "test save",
        }),
      }),
    );
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.body).commit).toBe("abc123");
    expect(fetchMock).toHaveBeenCalled();
    const putCall = fetchMock.mock.calls.find((c) => c[1]?.method === "PUT");
    expect(putCall?.[0]).toContain("/contents/data/site.json");
  });

  it("lists photos and ignores non-images", async () => {
    const login = await handler(
      event({
        path: "/api/login",
        httpMethod: "POST",
        body: JSON.stringify({ email: "admin@ismile.krd", password: PASSWORD }),
      }),
    );
    const { token } = JSON.parse(login.body);

    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response(
            JSON.stringify([
              { type: "file", name: "a.webp", path: "assets/uploads/a.webp" },
              { type: "file", name: "notes.txt", path: "assets/uploads/notes.txt" },
              { type: "dir", name: "nested", path: "assets/uploads/nested" },
            ]),
            { status: 200 },
          ),
      ),
    );

    const res = await handler(
      event({
        path: "/api/photos",
        headers: {
          origin: "https://ismile.krd",
          authorization: `Bearer ${token}`,
        },
      }),
    );
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.body).photos).toEqual(["assets/uploads/a.webp"]);
  });

  it("returns 404 for unknown routes", async () => {
    const login = await handler(
      event({
        path: "/api/login",
        httpMethod: "POST",
        body: JSON.stringify({ email: "admin@ismile.krd", password: PASSWORD }),
      }),
    );
    const { token } = JSON.parse(login.body);
    const res = await handler(
      event({
        path: "/api/nope",
        headers: {
          origin: "https://ismile.krd",
          authorization: `Bearer ${token}`,
        },
      }),
    );
    expect(res.statusCode).toBe(404);
  });
});

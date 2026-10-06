import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { boothAvailability } from "../server/booths.mjs";

describe("live booth availability on the Node website", () => {
  beforeEach(() => {
    vi.stubEnv("BOOTH_API_URL", "https://backend.example/api/booths.php");
    vi.stubEnv("BOOTH_API_USERNAME", "");
    vi.stubEnv("BOOTH_API_PASSWORD", "");
  });
  afterEach(() => {
    vi.unstubAllEnvs();
    vi.unstubAllGlobals();
  });
  /** @param {unknown} body @param {number} [status] */
  const upstream = (body, status = 200) => {
    const mock = vi.fn().mockResolvedValue(new Response(JSON.stringify(body), { status }));
    vi.stubGlobal("fetch", mock);
    return mock;
  };

  it("forwards only numbered availability and prevents caching", async () => {
    upstream({ ok: true, booked: [9, 8, 8], company: "Private company", contact: "private" });
    const response = await boothAvailability();
    expect(response.statusCode).toBe(200);
    expect(JSON.parse(response.body)).toEqual({ ok: true, booked: [8, 9] });
    expect(response.headers["Cache-Control"]).toBe("no-store");
  });
  it("keeps backend HTTP credentials on the server", async () => {
    vi.stubEnv("BOOTH_API_USERNAME", "staff-server");
    vi.stubEnv("BOOTH_API_PASSWORD", "test-server-password");
    const fetchMock = upstream({ ok: true, booked: [8] });
    const response = await boothAvailability();
    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe(
      `Basic ${Buffer.from("staff-server:test-server-password").toString("base64")}`,
    );
    expect(fetchMock.mock.calls[0][1].redirect).toBe("error");
    expect(response.body).not.toContain("staff-server");
    expect(response.body).not.toContain("password");
  });
  it("never lets visitors write reservations through the Node route", async () => {
    const fetchMock = upstream({ ok: true, booked: [] });
    expect((await boothAvailability("POST")).statusCode).toBe(405);
    expect(fetchMock).not.toHaveBeenCalled();
  });
  it("reports missing configuration as unavailable", async () => {
    vi.stubEnv("BOOTH_API_URL", "");
    expect((await boothAvailability()).statusCode).toBe(503);
  });
  it("reports backend failure without exposing its response", async () => {
    upstream({ error: "private server detail" }, 500);
    const response = await boothAvailability();
    expect(response.statusCode).toBe(503);
    expect(response.body).not.toContain("private server");
  });
  it("reports a failed connection without claiming booths are free", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("private connection details")));
    const response = await boothAvailability();
    expect(response.statusCode).toBe(503);
    expect(JSON.parse(response.body)).toEqual({ ok: false, error: "availability_unavailable" });
  });
  it.each([["8"], [0], [45], ["private company"], Array(45).fill(8)])(
    "rejects malformed or out-of-range booked numbers: %j",
    async (...values) => {
      upstream({ ok: true, booked: values });
      expect((await boothAvailability()).statusCode).toBe(503);
    },
  );
  it("refuses to send backend credentials over a remote HTTP connection", async () => {
    vi.stubEnv("BOOTH_API_URL", "http://backend.example/api/booths.php");
    const fetchMock = upstream({ ok: true, booked: [] });
    expect((await boothAvailability()).statusCode).toBe(503);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

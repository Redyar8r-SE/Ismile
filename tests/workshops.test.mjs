import { Readable } from "node:stream";
import { afterEach, describe, expect, it, vi } from "vitest";
import { workshopPayment, workshopRegistration, workshopsFile } from "../server/workshops.mjs";

afterEach(() => {
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});
const sample = {
  id: "crown-vs-veneer",
  title: { en: "Crown vs Veneer" },
  price: 120000,
  seatsLeft: 7,
};
function connection() {
  vi.stubEnv("WEBSITE_API_URL", "https://backend.example/api/");
  vi.stubEnv("BOOTH_API_USERNAME", "private-user");
  vi.stubEnv("BOOTH_API_PASSWORD", "private-password");
}
/** @param {string} origin @param {string} body */
function request(origin, body) {
  return /** @type {import('node:http').IncomingMessage} */ (
    /** @type {unknown} */ (
      Object.assign(Readable.from([Buffer.from(body)]), {
        method: "POST",
        headers: { origin, "content-type": "application/x-www-form-urlencoded" },
      })
    )
  );
}

describe("workshop backend integration", () => {
  it("rejects a cash acknowledgement because website registration requires a checkout redirect", async () => {
    connection();
    const fetcher = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          ok: true,
          cashRequested: true,
          email: "private@example.invalid",
          amount: 120000,
        }),
      ),
    );
    vi.stubGlobal("fetch", fetcher);
    const body = "workshop=crown-vs-veneer&email=paid%40example.invalid&payment_method=cash";
    const result = await workshopRegistration(request("https://ismile.krd", body));
    expect(result.statusCode).toBe(503);
    expect(JSON.parse(result.body)).toEqual({ ok: false, error: "wd_err_server" });
    expect(Buffer.from(fetcher.mock.calls[0][1].body).toString()).toBe(body);
  });
  it("preserves the backend rejection of public cash registration", async () => {
    connection();
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue(new Response('{"ok":false,"error":"wd_err_server"}', { status: 400 })),
    );
    const result = await workshopRegistration(request("https://ismile.krd", "payment_method=cash"));
    expect(result.statusCode).toBe(400);
  });
  it("serves the current database catalog instead of stale local demos", async () => {
    connection();
    const fetcher = vi.fn().mockResolvedValue(new Response(JSON.stringify([sample])));
    vi.stubGlobal("fetch", fetcher);
    const result = await workshopsFile("missing-local-demo-file.json");
    expect(result.statusCode).toBe(200);
    expect(JSON.parse(result.body)).toEqual([sample]);
    expect(fetcher.mock.calls[0][0].href).toBe("https://backend.example/api/workshops.php");
    expect(result.body).not.toContain("private-password");
  });
  it("does not restore stale seats when the configured backend fails", async () => {
    connection();
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(new Response("unavailable", { status: 503 })));
    expect((await workshopsFile("data/workshops.json")).statusCode).toBe(503);
  });
  it("preserves the server's paid-summit eligibility rejection", async () => {
    connection();
    const fetcher = vi
      .fn()
      .mockResolvedValue(
        new Response('{"ok":false,"error":"wd_err_not_registered"}', { status: 404 }),
      );
    vi.stubGlobal("fetch", fetcher);
    const result = await workshopRegistration(
      request("https://ismile.krd", "workshop=crown-vs-veneer&email=guest%40example.invalid"),
    );
    expect(result.statusCode).toBe(404);
    expect(JSON.parse(result.body).error).toBe("wd_err_not_registered");
    expect(fetcher.mock.calls[0][0].pathname).toBe("/api/workshop-register.php");
  });
  it("blocks cross-origin registration without calling the backend", async () => {
    connection();
    const fetcher = vi.fn();
    vi.stubGlobal("fetch", fetcher);
    expect(
      (await workshopRegistration(request("https://attacker.example", "email=x"))).statusCode,
    ).toBe(403);
    expect(fetcher).not.toHaveBeenCalled();
  });
  it("returns the PSOOLA checkout address without marking registration complete", async () => {
    connection();
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            ok: true,
            redirect: "https://psoola.com/checkout/example",
            buyer: "private",
          }),
        ),
      ),
    );
    const result = await workshopRegistration(
      request("https://ismile.krd", "email=paid%40example.invalid"),
    );
    expect(JSON.parse(result.body)).toEqual({
      ok: true,
      redirect: "https://psoola.com/checkout/example",
    });
  });
  it("rejects an unexpected checkout hostname", async () => {
    connection();
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue(
          new Response(
            JSON.stringify({ ok: true, redirect: "https://psoola.com.attacker.example/pay" }),
          ),
        ),
    );
    expect(
      (await workshopRegistration(request("https://ismile.krd", "email=paid%40example.invalid")))
        .statusCode,
    ).toBe(503);
  });
  it("does not treat a return query parameter as payment confirmation", async () => {
    const fetcher = vi.fn();
    vi.stubGlobal("fetch", fetcher);
    const result = await workshopPayment(
      new URLSearchParams({ psoolaStatus: "paid", psoolaSession: "forged" }),
    );
    expect(result.statusCode).toBe(404);
    expect(fetcher).not.toHaveBeenCalled();
  });
  it("shows pending until the backend confirms payment and strips attendee data", async () => {
    connection();
    const fetcher = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          ok: true,
          workshopId: "crown-vs-veneer",
          status: "awaiting_payment",
          confirmed: false,
          checkoutUrl: "https://psoola.com/checkout/example",
          email: "private@example.invalid",
        }),
      ),
    );
    vi.stubGlobal("fetch", fetcher);
    const result = await workshopPayment(
      new URLSearchParams({ token: "a".repeat(64), psoolaStatus: "paid" }),
    );
    expect(JSON.parse(result.body)).toEqual({
      ok: true,
      workshopId: "crown-vs-veneer",
      status: "awaiting_payment",
      confirmed: false,
      checkoutUrl: "https://psoola.com/checkout/example",
    });
    expect(fetcher.mock.calls[0][0].searchParams.has("psoolaStatus")).toBe(false);
  });
  it("exposes the verified paid workshop result", async () => {
    connection();
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            ok: true,
            workshopId: "crown-vs-veneer",
            status: "paid",
            confirmed: true,
            buyer: "private",
          }),
        ),
      ),
    );
    const result = await workshopPayment(new URLSearchParams({ token: "a".repeat(64) }));
    expect(JSON.parse(result.body)).toEqual({
      ok: true,
      workshopId: "crown-vs-veneer",
      status: "paid",
      confirmed: true,
      checkoutUrl: null,
    });
  });
  it("rejects oversized workshop forms without forwarding them", async () => {
    connection();
    const fetcher = vi.fn();
    vi.stubGlobal("fetch", fetcher);
    expect(
      (await workshopRegistration(request("https://ismile.krd", "x".repeat(32769)))).statusCode,
    ).toBe(413);
    expect(fetcher).not.toHaveBeenCalled();
  });
});

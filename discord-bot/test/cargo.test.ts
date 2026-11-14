import { describe, expect, it } from "vitest";

import { CargoApiError, CargoClient, normalisePath, trimResult } from "../src/cargo.js";
import { mayAsk, RemoteSettings } from "../src/settings.js";

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

const config = {
  enabled: true,
  company: "Eisenlink",
  provider: "deepseek",
  model: "deepseek-v4-pro",
  api_key: "sk-1",
  thinking: false,
  discord_ids: ["111111111111111111"],
};

describe("normalisePath", () => {
  it("accepts plain and prefixed paths", () => {
    expect(normalisePath("trips")).toBe("trips");
    expect(normalisePath("/api/v1/dashboard/business/")).toBe("dashboard/business");
    expect(normalisePath("https://cargo.example/api/v1/trips/01H/history")).toBe("trips/01H/history");
  });

  it("refuses traversal, odd characters, binaries, auth routes and its own settings", () => {
    for (const bad of ["../etc", "trips?x=1", "", "billing/abc/document.pdf", "billing/export", "login", "access/roles", "me/password", "assistant/bot-config"]) {
      expect(() => normalisePath(bad)).toThrow(CargoApiError);
    }
  });
});

describe("trimResult", () => {
  it("leaves small results alone and truncates big ones with advice", () => {
    expect(trimResult({ a: 1 })).toBe('{"a":1}');

    const out = trimResult({ rows: "x".repeat(500) }, 100);
    expect(out.startsWith('{"rows":"xxx')).toBe(true);
    expect(out).toContain("narrower filters");
  });
});

describe("CargoClient", () => {
  it("sends the bot token and the query", async () => {
    const calls: { url: string; auth?: string }[] = [];
    const fetcher = (async (input: RequestInfo | URL, init?: RequestInit) => {
      calls.push({ url: String(input), auth: (init?.headers as Record<string, string>).Authorization });
      return json(200, { data: [{ id: 1 }] });
    }) as typeof fetch;

    const client = new CargoClient("https://cargo.test/api/v1", "7|bot-token", fetcher);

    await expect(client.get("trips", { from: "2026-10-01", status: "", per_page: 5 })).resolves.toEqual({ data: [{ id: 1 }] });
    expect(calls).toEqual([{ url: "https://cargo.test/api/v1/trips?from=2026-10-01&per_page=5", auth: "Bearer 7|bot-token" }]);
  });

  it("says where to get a new token when it is rejected", async () => {
    const client = new CargoClient("https://cargo.test/api/v1", "old", (async () => json(401, { message: "Unauthenticated." })) as typeof fetch);

    await expect(client.get("trips")).rejects.toThrow("Assistant Settings");
  });

  it("reports the API's own message on failure", async () => {
    const client = new CargoClient(
      "https://cargo.test/api/v1",
      "t",
      (async () => json(403, { message: "This account does not hold payroll.view." })) as typeof fetch,
    );

    await expect(client.get("payroll")).rejects.toThrow("403 on payroll: This account does not hold payroll.view.");
  });
});

describe("RemoteSettings", () => {
  function remote(responses: (() => Response)[], clock: { t: number }) {
    let calls = 0;
    const fetcher = (async (input: RequestInfo | URL) => {
      expect(String(input)).toBe("https://cargo.test/api/v1/assistant/bot-config");
      calls++;
      const next = responses.shift();
      if (!next) throw new Error("offline");
      return next();
    }) as typeof fetch;

    const settings = new RemoteSettings(new CargoClient("https://cargo.test/api/v1", "t", fetcher), 60_000, () => clock.t);

    return { settings, calls: () => calls };
  }

  it("reads the settings once a minute, picking up changes made on the page", async () => {
    const clock = { t: 0 };
    const { settings, calls } = remote(
      [() => json(200, { data: config }), () => json(200, { data: { ...config, discord_ids: ["111111111111111111", "222222222222222222"] } })],
      clock,
    );

    expect((await settings.get()).discord_ids).toHaveLength(1);
    clock.t = 30_000;
    await settings.get();
    expect(calls()).toBe(1);

    clock.t = 61_000;
    const later = await settings.get();
    expect(calls()).toBe(2);
    expect(mayAsk(later, "222222222222222222")).toBe(true);
    expect(mayAsk(later, "333333333333333333")).toBe(false);
  });

  it("keeps working on the last settings it had when Cargo Rush is briefly unreachable", async () => {
    const clock = { t: 0 };
    const { settings } = remote([() => json(200, { data: config })], clock);

    await settings.get();
    clock.t = 120_000;

    await expect(settings.get()).resolves.toMatchObject({ model: "deepseek-v4-pro" });
  });

  it("fails clearly when it never got settings", async () => {
    const { settings } = remote([() => json(401, { message: "Unauthenticated." })], { t: 0 });

    await expect(settings.get()).rejects.toThrow("Assistant Settings");
  });
});

import { mkdtempSync, rmSync, statSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, describe, expect, it } from "vitest";

import { CredentialStore, pair } from "../src/pairing.js";

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

describe("pair", () => {
  it("asks to connect, shows the code, and waits for approval", async () => {
    const lines: string[] = [];
    const polls: (string | null)[] = [];
    const statuses = [{ status: "pending" }, { status: "pending" }, { status: "approved", token: "9|bot" }];

    const fetcher = (async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      if (init?.method === "POST") return json(201, { data: { id: "p1", code: "K7QM-4ZP9", secret: "s3cret", expires_at: "" } });
      polls.push((init?.headers as Record<string, string>)["X-Pairing-Secret"] ?? null);
      expect(url).toBe("https://cargo.test/api/v1/assistant/pairings/p1");
      return json(200, { data: statuses.shift() });
    }) as typeof fetch;

    const token = await pair("https://cargo.test/api/v1", { fetcher, log: (l) => lines.push(l), sleep: async () => undefined });

    expect(token).toBe("9|bot");
    expect(polls).toEqual(["s3cret", "s3cret", "s3cret"]);
    expect(lines.join("\n")).toContain("Connection code: K7QM-4ZP9");
    expect(lines.join("\n")).toContain("Assistant Settings");
  });

  it("gets a fresh code when the old one expires or is dismissed", async () => {
    const codes: string[] = [];
    let requests = 0;
    const statuses = [{ status: "expired" }, { status: "dismissed" }, { status: "approved", token: "10|bot" }];

    const fetcher = (async (_input: RequestInfo | URL, init?: RequestInit) => {
      if (init?.method === "POST") {
        requests++;
        return json(201, { data: { id: `p${requests}`, code: `CODE-000${requests}`, secret: "s", expires_at: "" } });
      }
      return json(200, { data: statuses.shift() });
    }) as typeof fetch;

    const token = await pair("https://cargo.test/api/v1", {
      fetcher,
      log: (l) => {
        const m = l.match(/Connection code: (\S+)/);
        if (m?.[1]) codes.push(m[1]);
      },
      sleep: async () => undefined,
    });

    expect(token).toBe("10|bot");
    expect(codes).toEqual(["CODE-0001", "CODE-0002", "CODE-0003"]);
  });

  it("keeps trying when Cargo Rush is down or busy", async () => {
    let calls = 0;
    const fetcher = (async (_input: RequestInfo | URL, init?: RequestInit) => {
      calls++;
      if (calls === 1) throw new Error("ECONNREFUSED");
      if (calls === 2) return json(429, { message: "Too many bots are waiting to connect." });
      if (init?.method === "POST") return json(201, { data: { id: "p", code: "AAAA-BBBB", secret: "s", expires_at: "" } });
      return json(200, { data: { status: "approved", token: "11|bot" } });
    }) as typeof fetch;

    const lines: string[] = [];
    await expect(pair("https://cargo.test/api/v1", { fetcher, log: (l) => lines.push(l), sleep: async () => undefined })).resolves.toBe("11|bot");
    expect(lines.join("\n")).toContain("Cannot reach Cargo Rush");
    expect(lines.join("\n")).toContain("Too many bots are waiting");
  });
});

describe("CredentialStore", () => {
  let dir = "";
  afterEach(() => {
    if (dir) rmSync(dir, { recursive: true, force: true });
  });

  it("remembers the token across restarts, privately, and forgets it on disconnect", () => {
    dir = mkdtempSync(join(tmpdir(), "rush-cred-"));
    const file = join(dir, "data", "credentials.json");

    expect(new CredentialStore(file).load()).toBeNull();

    new CredentialStore(file).save("12|bot");
    expect(new CredentialStore(file).load()).toBe("12|bot");
    if (process.platform !== "win32") expect(statSync(file).mode & 0o777).toBe(0o600);

    new CredentialStore(file).clear();
    expect(new CredentialStore(file).load()).toBeNull();
  });
});

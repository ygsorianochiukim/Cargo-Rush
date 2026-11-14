import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, describe, expect, it } from "vitest";

import { finalText } from "../src/assistant.js";
import { loadConfig } from "../src/config.js";
import { ConversationMemory, KeyedQueue } from "../src/memory.js";
import { stampFor } from "../src/prompt.js";
import { DISCORD_LIMIT, splitMessage } from "../src/split.js";

describe("splitMessage", () => {
  it("keeps short replies whole", () => {
    expect(splitMessage("Sales are up 14%.")).toEqual(["Sales are up 14%."]);
  });

  it("splits long replies under the Discord limit on paragraph breaks", () => {
    const text = Array.from({ length: 40 }, (_, i) => `Paragraph ${i} ${"word ".repeat(20)}`).join("\n\n");
    const parts = splitMessage(text);

    expect(parts.length).toBeGreaterThan(1);
    for (const part of parts) expect(part.length).toBeLessThanOrEqual(DISCORD_LIMIT);
    expect(parts.join(" ").replace(/\s+/g, " ")).toBe(text.replace(/\s+/g, " ").trim());
  });

  it("closes and reopens a code block that crosses a split", () => {
    const rows = Array.from({ length: 120 }, (_, i) => `Truck ${i}   ₱${i}0,000.00`).join("\n");
    const parts = splitMessage("Here you go:\n```text\n" + rows + "\n```\nDone.");

    expect(parts.length).toBeGreaterThan(1);
    for (const part of parts) {
      expect(part.length).toBeLessThanOrEqual(DISCORD_LIMIT);
      expect((part.match(/^```/gm) ?? []).length % 2).toBe(0);
    }
    expect(parts[1]?.startsWith("```text\n")).toBe(true);
  });
});

describe("ConversationMemory", () => {
  let dir = "";
  afterEach(() => {
    if (dir) rmSync(dir, { recursive: true, force: true });
  });

  it("keeps the latest turns per conversation and forgets on request", () => {
    const memory = new ConversationMemory(2);
    for (let i = 1; i <= 3; i++) memory.record("ch:1", `q${i}`, `a${i}`);

    expect(memory.history("ch:1").map((t) => t.text)).toEqual(["q2", "a2", "q3", "a3"]);
    expect(memory.history("ch:2")).toEqual([]);

    memory.forget("ch:1");
    expect(memory.history("ch:1")).toEqual([]);
  });

  it("survives a restart", () => {
    dir = mkdtempSync(join(tmpdir(), "rush-"));
    const file = join(dir, "history.json");

    const first = new ConversationMemory(10, file);
    first.record("dm:7", "How were sales?", "Up 14%.");
    first.flush();

    expect(new ConversationMemory(10, file).history("dm:7")).toEqual([
      { role: "user", text: "How were sales?" },
      { role: "assistant", text: "Up 14%." },
    ]);
  });
});

describe("KeyedQueue", () => {
  it("answers one message at a time per conversation, in order, even after a failure", async () => {
    const queue = new KeyedQueue();
    const seen: string[] = [];
    const wait = (ms: number) => new Promise((r) => setTimeout(r, ms));

    const a = queue.run("ch:1", async () => {
      await wait(20);
      seen.push("a");
      throw new Error("boom");
    });
    const b = queue.run("ch:1", async () => void seen.push("b"));
    const other = queue.run("ch:2", async () => void seen.push("other"));

    await expect(a).rejects.toThrow("boom");
    await Promise.all([b, other]);

    expect(seen).toEqual(["other", "a", "b"]);
  });
});

describe("finalText", () => {
  it("returns the reply, and answers politely when filtered or empty", () => {
    expect(finalText("  **₱80,141.67** this month. ", "stop")).toBe("**₱80,141.67** this month.");
    expect(finalText("", "content_filter")).toContain("can't help");
    expect(finalText(null, "stop")).toContain("rephrase");
  });
});

describe("loadConfig", () => {
  it("needs nothing but the Cargo Rush address", () => {
    const config = loadConfig({ CARGO_API_URL: "https://cargo.test/api/v1/" });

    expect(config.CARGO_API_URL).toBe("https://cargo.test/api/v1");
    expect(config.CARGO_BOT_TOKEN).toBeUndefined();
    expect(config.DISCORD_TOKEN).toBeUndefined();
    expect(config.DISCORD_CLIENT_ID).toBe("1571205865602687158");
    expect(config.TIMEZONE).toBe("Asia/Manila");
  });

  it("names what is missing", () => {
    expect(() => loadConfig({})).toThrow("CARGO_API_URL");
  });
});

describe("stampFor", () => {
  it("tells the assistant who is asking and when, in Manila time", () => {
    const stamp = stampFor("Yvert", new Date("2026-11-15T01:30:00Z"), "Asia/Manila");

    expect(stamp).toContain("Yvert");
    expect(stamp).toContain("November 15, 2026");
    expect(stamp).toMatch(/9:30/);
  });
});

import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { ChannelType, type Client } from "discord.js";
import { expect, it, vi } from "vitest";

import type { CargoClient } from "../src/cargo.js";
import { FinanceReports } from "../src/finance-reports.js";
import { type BotSettings, channelScope } from "../src/settings.js";

const settings: BotSettings = {
  enabled: true, company_id: "company", provider: "deepseek", model: "test", api_key: "test", thinking: false, discord_ids: [],
  discord_assistant_channel_id: "assistant", discord_finance_channel_id: "finance",
  discord_finance_alerts_channel_id: "alerts", finance_reports_enabled: true,
};
const afterMidnight = new Date("2026-11-20T16:01:00Z");

it("routes configured conversations to their channel and ignores DMs and the alert channel", () => {
  expect(channelScope(settings, "assistant")).toBe("general");
  expect(channelScope(settings, "finance")).toBe("finance");
  expect(channelScope(settings, "alerts")).toBeNull();
  expect(channelScope(settings, "dm")).toBeNull();
});

it("delivers every page of the local-day digest and deduplicates alerts and reports after a restart", async () => {
  const directory = mkdtempSync(join(tmpdir(), "cargo-finance-"));
  const previousDay = "2026-11-20";
  const first = { id: "payment-1", direction: "receivable", amount_cents: 10000, currency: "PHP", paid_on: previousDay, voided: false, occurred_at: "2026-11-20T15:59:00Z" };
  const second = { ...first, id: "payment-2" };
  const failed = { id: "failed-run", status: "failed", changed_count: 0, occurred_at: "2026-11-20T16:00:00Z" };
  const cargo = { financeActivity: async ({ kind, day, alerts, after }: Record<string, unknown>) => {
    if (day === previousDay && kind === "payments" && !alerts) {
      return { data: after ? { events: [second], next: null } : { events: [first], next: first.id } };
    }
    return { data: { events: alerts && kind === "runs" && day === "2026-11-21" ? [failed] : [], next: null } };
  } } as unknown as CargoClient;
  const finance = vi.fn().mockResolvedValue(undefined);
  const alerts = vi.fn().mockResolvedValue(undefined);
  const client = { channels: { fetch: async (id: string) => ({
    type: ChannelType.GuildText, guild: { roles: { everyone: {} } },
    permissionsFor: () => ({ has: () => false }),
    send: id === "alerts" ? alerts : finance,
  }) } } as unknown as Client;
  const file = join(directory, "deliveries.json");

  try {
    expect(await new FinanceReports(cargo, file, "Asia/Manila").tick(client, settings, afterMidnight)).toEqual([]);
    expect(alerts).toHaveBeenCalledExactlyOnceWith(expect.objectContaining({
      content: expect.stringContaining("Finance Bot failed"), enforceNonce: true,
    }));
    expect(finance).toHaveBeenCalledTimes(3);
    expect(finance.mock.calls[0]?.[0].files[0].attachment.toString()).toContain(first.id);
    expect(finance.mock.calls[1]?.[0].files[0].attachment.toString()).toContain(second.id);
    expect(finance.mock.calls[2]?.[0].content).toContain("2 recorded payments");

    expect(await new FinanceReports(cargo, file, "Asia/Manila").tick(client, settings, afterMidnight)).toEqual([]);
    expect(alerts).toHaveBeenCalledTimes(1);
    expect(finance).toHaveBeenCalledTimes(3);
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});

it("does not post a report to a publicly visible text channel", async () => {
  const directory = mkdtempSync(join(tmpdir(), "cargo-finance-"));
  const cargo = { financeActivity: async () => ({ data: { events: [], next: null } }) } as unknown as CargoClient;
  const send = vi.fn();
  const client = { channels: { fetch: async () => ({
    type: ChannelType.GuildText, guild: { roles: { everyone: {} } },
    permissionsFor: () => ({ has: () => true }), send,
  }) } } as unknown as Client;

  try {
    const errors = await new FinanceReports(cargo, join(directory, "deliveries.json"), "Asia/Manila").tick(client, settings, afterMidnight);
    expect(errors[0]).toContain("private server text channel");
    expect(send).not.toHaveBeenCalled();
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});

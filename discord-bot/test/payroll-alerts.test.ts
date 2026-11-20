import { ChannelType, type Client } from "discord.js";
import { expect, it, vi } from "vitest";

import type { CargoClient } from "../src/cargo.js";
import { deliverPayrollAlerts } from "../src/payroll-alerts.js";
import type { BotSettings } from "../src/settings.js";

const settings: BotSettings = {
  enabled: true, provider: "deepseek", model: "test", api_key: null, thinking: false, discord_ids: [],
  hr_alerts_enabled: true, discord_hr_channel_id: "hr",
};

function fixture(publicChannel: boolean) {
  const send = vi.fn().mockResolvedValue(undefined);
  const markPayrollAlertDelivered = vi.fn().mockResolvedValue(undefined);
  const cargo = {
    payrollAlerts: async () => ({ data: [{
      id: "alert", event: "draft", status: "draft", scope: "crew", reference: "CP-1",
      period: "6–15 Nov 2026", pay_date: "2026-11-18", paid_date: null,
      gross_cents: 50000, total_cost_cents: 53000, net_cents: 48000,
      payments: [{ name: "Test Crew Member", net_cents: 48000 }],
    }] }),
    markPayrollAlertDelivered,
  } as unknown as CargoClient;
  const client = { channels: { fetch: async () => ({
    type: ChannelType.GuildText, guild: { roles: { everyone: {} } },
    permissionsFor: () => ({ has: () => publicChannel }), send,
  }) } } as unknown as Client;

  return { client, cargo, send, markPayrollAlertDelivered };
}

it("sends names, net payments, and total cost to the private HR channel before acknowledging", async () => {
  const { client, cargo, send, markPayrollAlertDelivered } = fixture(false);
  await deliverPayrollAlerts(client, cargo, settings);
  expect(send).toHaveBeenCalledWith(expect.objectContaining({
    content: expect.stringMatching(/Crew payroll[\s\S]*₱530.00[\s\S]*Test Crew Member — ₱480.00/),
    allowedMentions: { parse: [] },
  }));
  expect(markPayrollAlertDelivered).toHaveBeenCalledWith("alert", "hr");
  expect(send).toHaveBeenCalledBefore(markPayrollAlertDelivered);
});

it("keeps payroll data and acknowledgements out of publicly visible channels", async () => {
  const { client, cargo, send, markPayrollAlertDelivered } = fixture(true);
  await expect(deliverPayrollAlerts(client, cargo, settings)).rejects.toThrow("private server text channel");
  expect(send).not.toHaveBeenCalled();
  expect(markPayrollAlertDelivered).not.toHaveBeenCalled();
});

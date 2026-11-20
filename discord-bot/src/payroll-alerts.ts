import { createHash } from "node:crypto";
import { type Client, escapeMarkdown } from "discord.js";
import { z } from "zod";

import type { CargoClient } from "./cargo.js";
import { privateTextChannel } from "./channels.js";
import type { BotSettings } from "./settings.js";

const alerts = z.object({
  data: z.array(z.object({
    id: z.string(),
    event: z.enum(["draft", "approved", "paid", "due"]),
    status: z.enum(["draft", "approved", "paid"]),
    scope: z.enum(["office", "crew"]),
    reference: z.string(),
    period: z.string(),
    pay_date: z.string(),
    paid_date: z.string().nullable(),
    gross_cents: z.number(),
    total_cost_cents: z.number(),
    net_cents: z.number(),
    payments: z.array(z.object({ name: z.string(), net_cents: z.number() })),
  })),
});

const labels = { draft: "Draft prepared", approved: "Approved", paid: "Marked paid", due: "Pay date reminder" };
const amounts = new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" });
const money = (cents: number) => amounts.format(cents / 100);
const singleLine = (value: string) => value.replace(/\s+/g, " ").trim();

export async function deliverPayrollAlerts(client: Client, cargo: CargoClient, settings: BotSettings): Promise<void> {
  const id = settings.discord_hr_channel_id;
  if (!settings.hr_alerts_enabled || !id) return;

  const { data } = alerts.parse(await cargo.payrollAlerts(id));
  if (data.length === 0) return;

  const channel = await privateTextChannel(client, id);
  for (const alert of data) {
    const scope = alert.scope === "crew" ? "Crew payroll" : "Office payroll";
    const header = "**" + scope + " · " + labels[alert.event] + "**\n" +
      escapeMarkdown(alert.reference + " · " + alert.period) + "\n" +
      "Planned pay date " + alert.pay_date + (alert.paid_date ? " · Paid " + alert.paid_date : "") + "\n" +
      "Current status: " + alert.status + "\n" +
      "Total payroll cost **" + money(alert.total_cost_cents) + "** · Gross " + money(alert.gross_cents) +
      " · Net payments **" + money(alert.net_cents) + "**";
    const rows = alert.payments.map(({ name, net_cents }) => "• " + escapeMarkdown(singleLine(name)) + " — " + money(net_cents));
    const full = header + "\n\n" + (rows.length ? rows.join("\n") : "No employees on this run.");
    const nonce = createHash("sha256").update(id + ":" + alert.id).digest("hex").slice(0, 24);
    await channel.send({
      content: full.length <= 2000 ? full : header + "\n\nThe employee payment list is attached.",
      files: full.length <= 2000 ? [] : [{
        attachment: Buffer.from(alert.payments.map(({ name, net_cents }) => singleLine(name) + " — " + money(net_cents)).join("\n")),
        name: scope.toLowerCase().replace(" ", "-") + "-payments.txt",
      }],
      nonce, enforceNonce: true, allowedMentions: { parse: [] },
    });
    await cargo.markPayrollAlertDelivered(alert.id, id);
  }
}

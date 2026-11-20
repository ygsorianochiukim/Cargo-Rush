import { createHash } from "node:crypto";
import { mkdirSync, readFileSync, renameSync, writeFileSync } from "node:fs";
import { dirname } from "node:path";
import type { Client, MessageCreateOptions } from "discord.js";
import { z } from "zod";

import type { CargoClient } from "./cargo.js";
import { privateTextChannel } from "./channels.js";
import type { BotSettings } from "./settings.js";

const base = z.object({ id: z.string(), occurred_at: z.string() });
const run = base.extend({ status: z.string(), changed_count: z.coerce.number() });
const payment = base.extend({
  amount_cents: z.coerce.number(), currency: z.string(), direction: z.string(), paid_on: z.string().nullable(),
  voided: z.boolean(),
});
const change = base.extend({
  invoice_id: z.string(),
  invoice_number: z.string().nullable(),
  previous_status: z.string().nullable(),
  new_status: z.string().nullable(),
});
const receiptExpense = base.extend({
  expense_id: z.string(), amount: z.string(), currency: z.string(), reference: z.string().nullable(),
});

const dateKey = z.string().regex(/^\d{4}-\d{2}-\d{2}$/);
const progress = z.object({
  firstAlertDay: dateKey,
  lastReportDay: dateKey.nullable(),
  sent: z.record(z.string(), dateKey),
});
type Progress = z.infer<typeof progress>;
const storeSchema = z.record(z.string(), progress);

export function localDay(now: Date, timezone: string): string {
  const parts = new Intl.DateTimeFormat("en", {
    timeZone: timezone, year: "numeric", month: "2-digit", day: "2-digit",
  }).formatToParts(now);
  return ["year", "month", "day"].map((part) => parts.find((p) => p.type === part)?.value).join("-");
}

export function moveDay(day: string, offset: number): string {
  const date = new Date(day + "T00:00:00Z");
  date.setUTCDate(date.getUTCDate() + offset);
  return date.toISOString().slice(0, 10);
}

function paymentLine(event: z.infer<typeof payment>): string {
  const direction = event.direction === "payable" ? "Payment sent" : "Payment received";
  const amount = new Intl.NumberFormat("en-PH", { style: "currency", currency: event.currency }).format(event.amount_cents / 100);
  return direction + " · " + amount + (event.paid_on ? " · payment date " + event.paid_on : "") +
    " · " + event.id + (event.voided ? " · voided" : "");
}

function runLine(event: z.infer<typeof run>): string {
  return "Overdue invoice check " + event.status + " · " + event.changed_count + " invoices marked overdue · run " + event.id;
}

export class FinanceReports {
  private saved: Record<string, Progress> = {};
  private stateError = false;

  constructor(private readonly cargo: CargoClient, private readonly file: string, private readonly timezone: string) {
    try {
      this.saved = storeSchema.parse(JSON.parse(readFileSync(file, "utf8")));
    } catch (error) {
      this.stateError = (error as NodeJS.ErrnoException).code !== "ENOENT";
    }
  }

  async tick(client: Client, settings: BotSettings, now = new Date()): Promise<string[]> {
    const finance = settings.discord_finance_channel_id;
    if (!settings.finance_reports_enabled || !finance || !settings.company_id) return [];
    if (this.stateError) return ["The finance delivery history could not be read. Check the bot's data directory."];

    const alerts = settings.discord_finance_alerts_channel_id || finance;
    const today = localDay(now, this.timezone);
    const yesterday = moveDay(today, -1);
    const key = settings.company_id + ":" + finance + ":" + this.timezone;
    const state = this.saved[key] ??= { firstAlertDay: today, lastReportDay: null, sent: {} };
    const reportDay = state.lastReportDay ? moveDay(state.lastReportDay, 1) : yesterday;
    const oldest = reportDay < yesterday ? reportDay : yesterday;
    for (const [id, day] of Object.entries(state.sent)) {
      if (day < oldest) delete state.sent[id];
    }

    const failures: string[] = [];
    try {
      for (const day of [yesterday, today]) {
        if (day < state.firstAlertDay) continue;
        for await (const page of this.pages("runs", day, run, true)) {
          for (const event of page.events) {
            await this.send(client, alerts, state, day, "run:" + event.id, {
              content: (event.status === "failed" ? "**Finance Bot failed**\n" : "**New overdue invoices**\n") + runLine(event),
            });
          }
        }
        for await (const page of this.pages("payments", day, payment, true)) {
          for (const event of page.events) {
            await this.send(client, alerts, state, day, "payment:" + event.id, {
              content: "**Large payment recorded**\n" + paymentLine(event),
            });
          }
        }
      }
    } catch (error) {
      failures.push(this.failure("Finance alerts", error));
    }

    if (reportDay < today) {
      try {
        const totals = await this.digest(client, finance, state, reportDay);
        await this.send(client, finance, state, reportDay, "summary", {
          content: "**Finance daily report · " + reportDay + "** (" + this.timezone + ")\n" +
            totals.payments + " recorded payments · " + totals.expenses + " receipt expenses · " +
            totals.changes + " invoice status changes · " + totals.runs + " invoice checks",
        });
        const previous = state.lastReportDay;
        state.lastReportDay = reportDay;
        try {
          this.persist();
        } catch (error) {
          state.lastReportDay = previous;
          throw error;
        }
      } catch (error) {
        failures.push(this.failure("Finance daily report", error));
      }
    }
    return failures;
  }

  private async digest(client: Client, channel: string, state: Progress, day: string) {
    const localTime = new Intl.DateTimeFormat("en-PH", {
      timeZone: this.timezone, dateStyle: "short", timeStyle: "medium",
    });
    const postSection = async <S extends z.ZodType<{ occurred_at: string }>>(
      kind: string, label: string, schema: S, line: (event: z.output<S>) => string,
    ): Promise<number> => {
      let count = 0;
      let part = 0;
      for await (const page of this.pages(kind, day, schema)) {
        if (page.events.length === 0) continue;
        part++;
        count += page.events.length;
        const rows = page.events.map((event) => localTime.format(new Date(event.occurred_at)) + "  " + line(event));
        await this.send(client, channel, state, day, kind + ":" + part, {
          content: "**" + label + " · " + day + "** · part " + part,
          files: [{ attachment: Buffer.from(rows.join("\n") + "\n"), name: kind + "-" + day + "-" + part + ".txt" }],
        });
      }
      return count;
    };

    return {
      payments: await postSection("payments", "Recorded payments", payment, paymentLine),
      changes: await postSection("changes", "Invoice status changes", change, (event) =>
        (event.invoice_number ?? event.invoice_id) + " · " + (event.previous_status ?? "unknown") + " → " + (event.new_status ?? "unknown")),
      runs: await postSection("runs", "Invoice checks", run, runLine),
      expenses: await postSection("receipt-expenses", "Confirmed receipt expenses", receiptExpense, (event) =>
        "Expense " + event.expense_id + " · " + event.currency + " " + event.amount +
        (event.reference ? " · receipt " + event.reference : "")),
    };
  }

  private async *pages<S extends z.ZodType>(kind: string, day: string, event: S, alerts = false) {
    const schema = z.object({ data: z.object({ events: z.array(event), next: z.string().nullable() }) });
    let after: string | null = null;
    do {
      const response: unknown = await this.cargo.financeActivity({
        kind, day, timezone: this.timezone, alerts: alerts ? 1 : 0, after,
      });
      const page = schema.parse(response).data;
      yield page;
      after = page.next;
    } while (after !== null);
  }

  private async send(client: Client, channelId: string, state: Progress, day: string, id: string, message: MessageCreateOptions): Promise<void> {
    const delivery = day + ":" + id;
    if (state.sent[delivery]) return;

    const channel = await privateTextChannel(client, channelId);
    const nonce = createHash("sha256").update(channelId + ":" + delivery).digest("hex").slice(0, 24);
    await channel.send({ ...message, nonce, enforceNonce: true, allowedMentions: { parse: [] } });
    state.sent[delivery] = day;
    try {
      this.persist();
    } catch (error) {
      delete state.sent[delivery];
      throw error;
    }
  }

  private persist(): void {
    mkdirSync(dirname(this.file), { recursive: true });
    writeFileSync(this.file + ".tmp", JSON.stringify(this.saved), { mode: 0o600 });
    renameSync(this.file + ".tmp", this.file);
  }

  private failure(label: string, error: unknown): string {
    return label + ": " + (error instanceof Error ? error.message.split("\n")[0] : "Delivery failed.");
  }
}

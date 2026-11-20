import { z } from "zod";

import type { CargoClient } from "./cargo.js";

const schema = z.object({
  enabled: z.boolean(),
  company: z.string().nullable().optional(),
  company_id: z.string().nullable().optional(),
  provider: z.enum(["deepseek", "openai"]),
  model: z.string().min(1),
  api_key: z.string().nullable(),
  thinking: z.boolean(),
  discord_token: z.string().nullable().optional(),
  discord_ids: z.array(z.string()),
  discord_assistant_channel_id: z.string().nullable().optional(),
  discord_finance_channel_id: z.string().nullable().optional(),
  discord_finance_alerts_channel_id: z.string().nullable().optional(),
  discord_hr_channel_id: z.string().nullable().optional(),
  finance_reports_enabled: z.boolean().optional(),
  hr_alerts_enabled: z.boolean().optional(),
});

export type BotSettings = z.infer<typeof schema>;

export const BASE_URLS: Record<BotSettings["provider"], string> = {
  deepseek: "https://api.deepseek.com",
  openai: "https://api.openai.com/v1",
};

export class RemoteSettings {
  private current: BotSettings | null = null;
  private fetchedAt = 0;
  private inflight: Promise<BotSettings> | null = null;

  constructor(
    private readonly cargo: CargoClient,
    private readonly maxAgeMs = 60_000,
    private readonly now: () => number = Date.now,
  ) {}

  async get(allowStale = true): Promise<BotSettings> {
    if (this.current !== null && this.now() - this.fetchedAt < this.maxAgeMs) return this.current;

    try {
      return await this.refresh();
    } catch (error) {
      if (allowStale && this.current !== null) return this.current;
      throw error;
    }
  }

  refresh(): Promise<BotSettings> {
    this.inflight ??= this.cargo
      .botConfig()
      .then((body) => {
        const parsed = schema.parse((body as { data?: unknown } | null)?.data);
        this.current = parsed;
        this.fetchedAt = this.now();
        return parsed;
      })
      .finally(() => {
        this.inflight = null;
      });

    return this.inflight;
  }
}

export function mayAsk(settings: BotSettings, discordId: string): boolean {
  return settings.discord_ids.includes(discordId);
}

export function channelScope(settings: BotSettings, channelId: string): "general" | "finance" | "legacy" | null {
  const { discord_assistant_channel_id: assistant, discord_finance_channel_id: finance, discord_finance_alerts_channel_id: alerts } = settings;
  if (!assistant && !finance && !alerts && !settings.discord_hr_channel_id) return "legacy";
  if (channelId === assistant) return "general";
  if (channelId === finance) return "finance";
  return null;
}

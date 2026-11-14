import { z } from "zod";

import type { CargoClient } from "./cargo.js";

const schema = z.object({
  enabled: z.boolean(),
  company: z.string().nullable().optional(),
  provider: z.enum(["deepseek", "openai"]),
  model: z.string().min(1),
  api_key: z.string().nullable(),
  thinking: z.boolean(),
  discord_token: z.string().nullable().optional(),
  discord_ids: z.array(z.string()),
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

  async get(): Promise<BotSettings> {
    if (this.current !== null && this.now() - this.fetchedAt < this.maxAgeMs) return this.current;

    try {
      return await this.refresh();
    } catch (error) {
      if (this.current !== null) return this.current;
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

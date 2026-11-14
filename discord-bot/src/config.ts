import { z } from "zod";

const schema = z.object({
  DISCORD_TOKEN: z.string().optional(),
  DISCORD_CLIENT_ID: z.string().min(1).default("1571205865602687158"),
  CARGO_API_URL: z.string().url().transform((u) => u.replace(/\/+$/, "")),
  CARGO_BOT_TOKEN: z.string().optional(),
  ASSISTANT_NAME: z.string().default("Rush"),
  TIMEZONE: z.string().default("Asia/Manila"),
  HISTORY_TURNS: z.coerce.number().int().min(2).max(200).default(20),
  DATA_DIR: z.string().default("data"),
});

export type Config = z.infer<typeof schema>;

export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
  const parsed = schema.safeParse(env);

  if (!parsed.success) {
    const problems = parsed.error.issues.map((i) => `  ${i.path.join(".")}: ${i.message}`).join("\n");
    throw new Error(`The bot is not configured:\n${problems}\nCopy .env.example to .env and fill it in.`);
  }

  return parsed.data;
}

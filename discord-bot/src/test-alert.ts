import { createHash } from "node:crypto";
import type { Client } from "discord.js";
import { z } from "zod";

import type { CargoClient } from "./cargo.js";
import { privateTextChannel } from "./channels.js";

const response = z.object({
  data: z.object({
    test: z.object({
      id: z.string().regex(/^[0-9A-HJKMNP-TV-Z]{26}$/i),
      target: z.enum(["hr", "finance"]),
      channel_id: z.string().regex(/^[1-9][0-9]{16,19}$/),
    }).nullable(),
  }),
});

export async function deliverTestAlert(client: Client, cargo: CargoClient): Promise<void> {
  const { data: { test } } = response.parse(await cargo.testAlert());
  if (!test) return;

  let sent: { guild_id: string; message_id: string };
  try {
    const channel = await privateTextChannel(client, test.channel_id);
    const nonce = createHash("sha256").update("test-alert:" + test.id).digest("hex").slice(0, 24);
    const label = test.target === "hr" ? "HR payroll" : "Finance";
    const message = await channel.send({
      content: "**TEST ALERT · " + label + "**\nCargo Rush reached this private Discord channel. No payroll, invoice, or payment was created.",
      nonce, enforceNonce: true, allowedMentions: { parse: [] },
    });
    sent = { guild_id: channel.guild.id, message_id: message.id };
  } catch {
    await cargo.finishTestAlert(test.id, { status: "failed" });
    return;
  }
  await cargo.finishTestAlert(test.id, { status: "delivered", ...sent });
}

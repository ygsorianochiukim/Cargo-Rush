import { ChannelType, type Interaction, MessageFlags } from "discord.js";
import { expect, it, vi } from "vitest";
import type { CargoClient } from "../src/cargo.js";
import { ReceiptWorkflow } from "../src/receipts.js";
import type { RemoteSettings } from "../src/settings.js";

it("refreshes the receipt preview and privately gives the uploader a browser edit link", async () => {
  const id = "01ARZ3NDEKTSV4RRFFQ69G5FAV";
  const url = "https://cargo.example.test/expenses/receipt-edit/" + id + "#ticket=demo";
  const receiptEditLink = vi.fn().mockResolvedValue({
    data: {
      url,
      preview: {
        id, filename: "receipt.png", status: "pending", revision: 2, fields: {},
        issues: [], categories: {}, expense_id: null, may_confirm: true,
      },
    },
  });
  const settings = {
    get: async () => ({ enabled: true, discord_finance_channel_id: "finance", discord_ids: ["uploader"] }),
  } as unknown as RemoteSettings;
  const editReply = vi.fn();
  const followUp = vi.fn();
  const interaction = {
    isButton: () => true,
    customId: "receipt:update:" + id + ":1",
    channelId: "finance",
    user: { id: "uploader" },
    deferUpdate: vi.fn(),
    editReply,
    followUp,
    client: { channels: { fetch: async () => ({
      type: ChannelType.GuildText, guild: { roles: { everyone: {} } },
      permissionsFor: () => ({ has: () => false }),
    }) } },
  } as unknown as Interaction;

  await new ReceiptWorkflow({ receiptEditLink } as unknown as CargoClient, settings, () => {
    throw new Error("Update must not use the AI provider");
  }).interact(interaction);

  expect(receiptEditLink).toHaveBeenCalledWith(id, { channel_id: "finance", discord_user_id: "uploader" });
  expect(editReply.mock.calls[0]?.[0].components[0].toJSON().components[0].custom_id).toBe("receipt:confirm:" + id + ":2");
  expect(followUp.mock.calls[0]?.[0].flags).toBe(MessageFlags.Ephemeral);
  expect(followUp.mock.calls[0]?.[0].components[0].toJSON().components[0].url).toBe(url);
});

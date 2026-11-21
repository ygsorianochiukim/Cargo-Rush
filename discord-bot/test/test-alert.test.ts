import { ChannelType, type Client } from "discord.js";
import { expect, it, vi } from "vitest";

import type { CargoClient } from "../src/cargo.js";
import { deliverTestAlert } from "../src/test-alert.js";

const test = { id: "01K1ARZ3NDEKTSV4RRFFQ69G5F", channel_id: "123456789012345678", target: "hr" };

it.each([false, true])("reports the result of a test alert for a channel with public visibility %s", async (isPublic) => {
  const finishTestAlert = vi.fn().mockResolvedValue(undefined);
  const send = vi.fn().mockResolvedValue({ id: "222222222222222222" });
  const fetch = vi.fn().mockResolvedValue({
    type: ChannelType.GuildText, guild: { id: "111111111111111111", roles: { everyone: {} } },
    permissionsFor: () => ({ has: () => isPublic }), send,
  });
  const client = { channels: { fetch } } as unknown as Client;
  const cargo = { testAlert: async () => ({ data: { test } }), finishTestAlert } as unknown as CargoClient;

  await deliverTestAlert(client, cargo);
  expect(fetch).toHaveBeenCalledWith(test.channel_id, { force: true });
  if (isPublic) {
    expect(send).not.toHaveBeenCalled();
    expect(finishTestAlert).toHaveBeenCalledWith(test.id, { status: "failed" });
  } else {
    expect(send).toHaveBeenCalledExactlyOnceWith(expect.objectContaining({
      content: expect.stringContaining("TEST ALERT"), enforceNonce: true, allowedMentions: { parse: [] },
    }));
    expect(send).toHaveBeenCalledBefore(finishTestAlert);
    expect(finishTestAlert).toHaveBeenCalledWith(test.id, {
      status: "delivered", guild_id: "111111111111111111", message_id: "222222222222222222",
    });
  }
});

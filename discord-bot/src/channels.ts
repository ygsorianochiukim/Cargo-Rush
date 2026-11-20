import { ChannelType, type Client, PermissionFlagsBits, type TextChannel } from "discord.js";

export async function privateTextChannel(client: Client, id: string): Promise<TextChannel> {
  const channel = await client.channels.fetch(id, { force: true });
  if (channel?.type !== ChannelType.GuildText ||
      channel.permissionsFor(channel.guild.roles.everyone).has(PermissionFlagsBits.ViewChannel)) {
    throw new Error("Use a private server text channel with View Channel disabled for @everyone.");
  }
  return channel;
}

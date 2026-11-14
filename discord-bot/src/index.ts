import OpenAI from "openai";
import { ChannelType, Client, Events, GatewayIntentBits, type Message, Partials } from "discord.js";
import { join } from "node:path";

import { Assistant, llmFactory } from "./assistant.js";
import { CargoApiError, CargoClient } from "./cargo.js";
import { loadConfig } from "./config.js";
import { ConversationMemory, KeyedQueue } from "./memory.js";
import { CredentialStore, pair } from "./pairing.js";
import { stampFor } from "./prompt.js";
import { type BotSettings, mayAsk, RemoteSettings } from "./settings.js";
import { splitMessage } from "./split.js";

const config = loadConfig();

const credentials = new CredentialStore(join(config.DATA_DIR, "credentials.json"));
const cargo = new CargoClient(config.CARGO_API_URL, config.CARGO_BOT_TOKEN || credentials.load() || "");
const settings = new RemoteSettings(cargo);
const assistant = new Assistant(llmFactory(), cargo, { name: config.ASSISTANT_NAME, timezone: config.TIMEZONE });
const memory = new ConversationMemory(config.HISTORY_TURNS, join(config.DATA_DIR, "history.json"));
const queue = new KeyedQueue();
const warned = new Set<string>();

const RESET = /^(reset|forget( it| that| everything)?|new topic|start over|bagong usapan)[.!]?$/i;
const CHECK_EVERY_MS = 60_000;

let discord: Client | null = null;
let discordToken: string | null = null;
let botId = config.DISCORD_CLIENT_ID;

const stamp = () => `[${new Date().toISOString()}]`;
const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

function conversationKey(message: Message): string {
  return message.channel.type === ChannelType.DM ? `dm:${message.author.id}` : `ch:${message.channelId}`;
}

async function addressedToMe(message: Message): Promise<boolean> {
  if (message.channel.type === ChannelType.DM) return true;
  if (message.mentions.users.has(botId)) return true;

  if (message.reference?.messageId) {
    const replied = await message.fetchReference().catch(() => null);
    return replied?.author.id === botId;
  }

  return false;
}

function cleanText(message: Message): string {
  return message.content.replace(new RegExp(`<@!?${botId}>`, "g"), "").trim();
}

function keepTyping(message: Message): () => void {
  const channel = message.channel;
  if (!("sendTyping" in channel)) return () => undefined;

  void channel.sendTyping().catch(() => undefined);
  const timer = setInterval(() => void channel.sendTyping().catch(() => undefined), 8_000);

  return () => clearInterval(timer);
}

async function send(message: Message, text: string): Promise<void> {
  const [first, ...rest] = splitMessage(text);
  if (first === undefined) return;

  await message.reply({ content: first, allowedMentions: { repliedUser: false, parse: [] } });

  for (const chunk of rest) {
    if ("send" in message.channel) await message.channel.send({ content: chunk, allowedMentions: { parse: [] } });
  }
}

async function handle(message: Message): Promise<void> {
  if (message.author.bot || !(await addressedToMe(message))) return;

  const current = await settings.get().catch((error: unknown) => {
    console.error(`${stamp()} could not read the assistant settings`, error);
    return null;
  });

  if (current === null) {
    await message.reply("I can't reach Cargo Rush right now. Try again in a few minutes.").catch(() => undefined);
    return;
  }

  if (!mayAsk(current, message.author.id)) {
    if (!warned.has(message.author.id)) {
      warned.add(message.author.id);
      await message.reply("Sorry, I can only help people the company has given access to. Ask an admin to add your Discord ID in Assistant Settings.");
    }
    return;
  }

  warned.delete(message.author.id);

  if (!current.enabled) {
    await message.reply("I'm switched off at the moment. An admin can turn me on in Cargo Rush → Assistant Settings.");
    return;
  }

  const text = cleanText(message);
  const key = conversationKey(message);

  if (text === "") {
    await message.reply("Hi! What would you like to know? Sales, trips, schedules, trucks, assets, anything in Cargo Rush.");
    return;
  }

  if (RESET.test(text)) {
    memory.forget(key);
    await message.reply("Done, fresh start. What's next?");
    return;
  }

  await queue.run(key, async () => {
    const stopTyping = keepTyping(message);
    const author = message.member?.displayName ?? message.author.displayName;
    const stamped = `${stampFor(author, message.createdAt, config.TIMEZONE)}\n${text}`;

    try {
      const reply = await assistant.reply(memory.history(key), stamped, current);
      memory.record(key, stamped, reply.text, reply.reasoning);
      stopTyping();
      await send(message, reply.text);
      console.log(`${stamp()} ${author} in ${key}: ${reply.lookups} lookup(s)`);
    } catch (error) {
      stopTyping();
      console.error(`${stamp()} reply failed in ${key}`, error);
      await send(message, friendlyError(error)).catch(() => undefined);
    }
  });
}

function friendlyError(error: unknown): string {
  if (error instanceof OpenAI.RateLimitError) return "I'm getting a lot of questions at once (or the AI account is out of credit). Give me a minute and ask again?";
  if (error instanceof OpenAI.AuthenticationError) return "The AI key in Assistant Settings was rejected. Please ask an admin to check it.";
  if (error instanceof CargoApiError && error.status === 401) return "My connection to Cargo Rush was revoked. An admin can approve me again in Assistant Settings.";
  if (error instanceof OpenAI.APIConnectionError) return "I couldn't reach my AI service just now. Try again in a moment.";
  if (error instanceof OpenAI.APIError) return "Something went wrong on my side while thinking that through. Try asking again.";

  return "Something went wrong while I was looking that up. Try again in a moment.";
}

async function connectToCargo(): Promise<BotSettings> {
  for (;;) {
    if (!cargo.hasToken()) {
      const token = await pair(cargo.baseUrl);
      credentials.save(token);
      cargo.setToken(token);
    }

    try {
      return await settings.refresh();
    } catch (error) {
      if (error instanceof CargoApiError && (error.status === 401 || error.status === 403)) {
        console.log(`${stamp()} Cargo Rush no longer accepts this bot's token. Asking to connect again.`);
        credentials.clear();
        cargo.setToken("");
        continue;
      }

      console.error(`${stamp()} cannot reach Cargo Rush (${error instanceof Error ? error.message : String(error)}). Retrying in 30s.`);
      await sleep(30_000);
    }
  }
}

function newDiscordClient(): Client {
  const client = new Client({
    intents: [GatewayIntentBits.Guilds, GatewayIntentBits.GuildMessages, GatewayIntentBits.MessageContent, GatewayIntentBits.DirectMessages],
    partials: [Partials.Channel, Partials.Message],
  });

  client.once(Events.ClientReady, (ready) => {
    botId = ready.user.id;
    const permissions = 1024n + 2048n + 65536n + 274877906944n;
    console.log(`${stamp()} ${config.ASSISTANT_NAME} is online on Discord as ${ready.user.tag}.`);
    console.log(`Invite: https://discord.com/oauth2/authorize?client_id=${ready.user.id}&scope=bot&permissions=${permissions}`);
  });

  client.on(Events.MessageCreate, (message) => {
    void handle(message).catch((error) => console.error("message handler failed", error));
  });

  return client;
}

async function syncDiscord(current: BotSettings): Promise<void> {
  const wanted = current.discord_token || config.DISCORD_TOKEN || null;

  if (wanted === discordToken && discord !== null) return;

  if (discord !== null) {
    console.log(`${stamp()} The Discord token changed in Assistant Settings. Reconnecting.`);
    await discord.destroy();
    discord = null;
  }

  discordToken = wanted;

  if (wanted === null) {
    console.log(`${stamp()} No Discord bot token yet. Add it in Cargo Rush → Assistant Settings; I'll pick it up within a minute.`);
    return;
  }

  const client = newDiscordClient();

  try {
    await client.login(wanted);
    discord = client;
  } catch (error) {
    console.error(`${stamp()} Discord refused the bot token (${error instanceof Error ? error.message : String(error)}). Fix it in Assistant Settings.`);
    await client.destroy().catch(() => undefined);
  }
}

async function supervise(): Promise<void> {
  for (;;) {
    await sleep(CHECK_EVERY_MS);

    try {
      await syncDiscord(await settings.refresh());
    } catch (error) {
      if (error instanceof CargoApiError && (error.status === 401 || error.status === 403)) {
        cargo.setToken("");
        credentials.clear();
        await syncDiscord(await connectToCargo());
      }
    }
  }
}

function shutdown(): void {
  memory.flush();
  void (discord?.destroy() ?? Promise.resolve()).finally(() => process.exit(0));
}

process.on("SIGINT", shutdown);
process.on("SIGTERM", shutdown);

const initial = await connectToCargo();
console.log(
  `${stamp()} Connected to Cargo Rush${initial.company ? ` (${initial.company})` : ""}: ${initial.provider} ${initial.model}, ` +
    `${initial.discord_ids.length} Discord user(s) allowed, ${initial.enabled ? "switched on" : "switched off"}.`,
);

await syncDiscord(initial);
void supervise();

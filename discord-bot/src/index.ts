import OpenAI from "openai";
import { ChannelType, Client, Events, GatewayIntentBits, type Message, Partials, PermissionFlagsBits } from "discord.js";
import { join } from "node:path";

import { Assistant, llmFactory } from "./assistant.js";
import { CargoApiError, CargoClient } from "./cargo.js";
import { privateTextChannel } from "./channels.js";
import { loadConfig } from "./config.js";
import { FinanceReports } from "./finance-reports.js";
import { ConversationMemory, KeyedQueue } from "./memory.js";
import { CredentialStore, pair } from "./pairing.js";
import { deliverPayrollAlerts } from "./payroll-alerts.js";
import { stampFor } from "./prompt.js";
import { ReceiptWorkflow } from "./receipts.js";
import { type BotSettings, channelScope, mayAsk, RemoteSettings } from "./settings.js";
import { splitMessage } from "./split.js";
import { deliverTestAlert } from "./test-alert.js";

const config = loadConfig();

const credentials = new CredentialStore(join(config.DATA_DIR, "credentials.json"));
const cargo = new CargoClient(config.CARGO_API_URL, config.CARGO_BOT_TOKEN || credentials.load() || "");
const settings = new RemoteSettings(cargo);
const llm = llmFactory();
const receipts = new ReceiptWorkflow(cargo, settings, llm);
const assistant = new Assistant(llm, cargo, { name: config.ASSISTANT_NAME, timezone: config.TIMEZONE });
const finance = new Assistant(llm, cargo, { name: "Finance Bot", timezone: config.TIMEZONE, financeOnly: true });
const reports = new FinanceReports(cargo, join(config.DATA_DIR, "finance-deliveries.json"), config.TIMEZONE);
const memory = new ConversationMemory(config.HISTORY_TURNS, join(config.DATA_DIR, "history.json"));
const queue = new KeyedQueue();
const warned = new Set<string>();

const RESET = /^(reset|forget( it| that| everything)?|new topic|start over|bagong usapan)[.!]?$/i;
const CHECK_EVERY_MS = 60_000;

let discord: Client | null = null;
let discordToken: string | null = null;
let botId = config.DISCORD_CLIENT_ID;
let discordStatus: Record<string, unknown> & { state: string } = { state: "connecting" };

const stamp = () => `[${new Date().toISOString()}]`;
const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

function conversationKey(message: Message): string {
  return message.channel.type === ChannelType.DM ? `dm:${message.author.id}` : `ch:${message.channelId}`;
}

function myRoleId(message: Message): string | null {
  return message.guild?.members.me?.roles.botRole?.id ?? null;
}

async function addressedToMe(message: Message): Promise<boolean> {
  if (message.channel.type === ChannelType.DM) return true;
  if (message.mentions.users.has(botId)) return true;

  const role = myRoleId(message);
  if (role !== null && message.mentions.roles.has(role)) return true;

  if (message.reference?.messageId) {
    const replied = await message.fetchReference().catch(() => null);
    return replied?.author.id === botId;
  }

  return false;
}

function cleanText(message: Message): string {
  const role = myRoleId(message);
  const mention = role === null ? `<@!?${botId}>` : `<@!?${botId}>|<@&${role}>`;

  return message.content.replace(new RegExp(mention, "g"), "").trim();
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
  if (message.author.bot) return;

  const current = await settings.get(false).catch((error: unknown) => {
    console.error(`${stamp()} could not read the assistant settings`, error);
    return null;
  });

  if (current === null) return;
  const scope = channelScope(current, message.channelId);
  if (scope === null || (scope === "legacy" && !(await addressedToMe(message)))) return;

  if (!mayAsk(current, message.author.id)) {
    if (scope !== "legacy") return;
    if (!warned.has(message.author.id)) {
      warned.add(message.author.id);
      await message.reply("Sorry, I can only help people the company has given access to. Ask an admin to add your Discord ID in Assistant Settings.");
    }
    return;
  }

  if (scope !== "legacy") await privateTextChannel(message.client, message.channelId);
  warned.delete(message.author.id);

  if (!current.enabled) {
    await message.reply("I'm switched off at the moment. An admin can turn me on in Cargo Rush → Assistant Settings.");
    return;
  }

  if (scope === "finance" && message.attachments.size > 0) {
    await receipts.receive(message, current);
    return;
  }

  const text = cleanText(message);
  const key = scope === "legacy"
    ? conversationKey(message)
    : (current.company_id ?? "") + ":" + scope + ":" + conversationKey(message);

  if (text === "") {
    await message.reply(scope === "finance"
      ? "Ask me about Cargo Rush finances, invoices, payments, or accounting."
      : "Hi! What would you like to know about Cargo Rush?");
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
      const reply = await (scope === "finance" ? finance : assistant).reply(memory.history(key), stamped, current);
      const latest = await settings.get(false);
      if (current.company_id !== latest.company_id || !latest.enabled ||
          channelScope(latest, message.channelId) !== scope || !mayAsk(latest, message.author.id)) return;
      if (scope !== "legacy") await privateTextChannel(message.client, message.channelId);
      memory.record(key, stamped, reply.text, reply.reasoning);
      await send(message, reply.text);
      console.log(`${stamp()} ${author} in ${key}: ${reply.lookups} lookup(s)`);
    } catch (error) {
      console.error(`${stamp()} reply failed in ${key}`, error);
      if (scope === "legacy") {
        await send(message, friendlyError(error)).catch(() => undefined);
      } else {
        await privateTextChannel(message.client, message.channelId)
          .then(() => send(message, friendlyError(error))).catch(() => undefined);
      }
    } finally {
      stopTyping();
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
    const permissions = PermissionFlagsBits.ViewChannel | PermissionFlagsBits.SendMessages |
      PermissionFlagsBits.ReadMessageHistory | PermissionFlagsBits.SendMessagesInThreads | PermissionFlagsBits.AttachFiles;
    const invite = `https://discord.com/oauth2/authorize?client_id=${ready.user.id}&scope=bot&permissions=${permissions}`;
    console.log(`${stamp()} ${config.ASSISTANT_NAME} is online on Discord as ${ready.user.tag}, in ${ready.guilds.cache.size} server(s).`);
    console.log(`Invite: ${invite}`);
    discordStatus = { state: "online", tag: ready.user.tag, servers: ready.guilds.cache.size, invite_url: invite };
    void cargo.reportStatus(discordStatus);
  });

  const recount = () => {
    if (discordStatus.state === "online") {
      discordStatus = { ...discordStatus, servers: client.guilds.cache.size };
      void cargo.reportStatus(discordStatus);
    }
  };
  client.on(Events.GuildCreate, recount);
  client.on(Events.GuildDelete, recount);

  client.on(Events.MessageCreate, (message) => {
    void handle(message).catch((error) => console.error("message handler failed", error));
  });
  client.on(Events.InteractionCreate, (interaction) => {
    void receipts.interact(interaction).catch(() => console.error("Receipt interaction failed."));
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
    discordStatus = { state: "no_token" };
    void cargo.reportStatus(discordStatus);
    return;
  }

  const client = newDiscordClient();
  discordStatus = { state: "connecting" };

  try {
    await client.login(wanted);
    discord = client;
  } catch (error) {
    const reason = error instanceof Error ? error.message : String(error);
    console.error(`${stamp()} Discord refused the bot token (${reason}). Fix it in Assistant Settings.`);
    discordStatus = { state: "refused", error: reason.slice(0, 300) };
    void cargo.reportStatus(discordStatus);
    await client.destroy().catch(() => undefined);
  }
}

async function supervise(): Promise<void> {
  for (;;) {
    await sleep(CHECK_EVERY_MS);

    try {
      const current = await settings.refresh();
      await syncDiscord(current);
      await syncReports(current);
      void cargo.reportStatus(discordStatus);
    } catch (error) {
      if (error instanceof CargoApiError && (error.status === 401 || error.status === 403)) {
        cargo.setToken("");
        credentials.clear();
        await syncDiscord(await connectToCargo());
      }
    }
  }
}

async function syncReports(current: BotSettings): Promise<void> {
  if (discord?.isReady() !== true) return;
  await deliverTestAlert(discord, cargo).catch(() => {
    console.error(stamp() + " Could not check Discord test alerts.");
  });
  const errors = await reports.tick(discord, current);
  let payrollError: string | null = null;
  try {
    await deliverPayrollAlerts(discord, cargo, current);
  } catch {
    payrollError = "Payroll alerts could not be delivered. Check the private HR channel and bot permissions.";
    console.error(stamp() + " " + payrollError);
  }
  discordStatus = { ...discordStatus, finance_error: errors.join(" ").slice(0, 300) || null, hr_error: payrollError };
  for (const error of errors) console.error(stamp() + " " + error);
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
await syncReports(initial);
void supervise();

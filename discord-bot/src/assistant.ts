import OpenAI from "openai";

import { CargoApiError, type CargoClient, normalisePath, readable, trimResult } from "./cargo.js";
import type { Turn } from "./memory.js";
import { systemPrompt } from "./prompt.js";
import { BASE_URLS, type BotSettings } from "./settings.js";

export interface AssistantOptions {
  name: string;
  timezone: string;
  financeOnly?: boolean;
}

export type LlmFactory = (settings: BotSettings) => OpenAI;

export function llmFactory(): LlmFactory {
  const clients = new Map<string, OpenAI>();

  return (settings) => {
    const key = `${settings.provider}:${settings.api_key}`;
    let client = clients.get(key);

    if (!client) {
      clients.clear();
      client = new OpenAI({ apiKey: settings.api_key ?? "", baseURL: BASE_URLS[settings.provider], timeout: 120_000 });
      clients.set(key, client);
    }

    return client;
  };
}

export interface Reply {
  text: string;
  reasoning: string | null;
  lookups: number;
}

type Message = OpenAI.Chat.Completions.ChatCompletionMessageParam & { reasoning_content?: string | null };
type AssistantMessage = OpenAI.Chat.Completions.ChatCompletionMessage & { reasoning_content?: string | null };

const MAX_ROUNDS = 12;

export const CARGO_TOOL: OpenAI.Chat.Completions.ChatCompletionFunctionTool = {
  type: "function",
  function: {
    name: "cargo_api_get",
    description:
      "Read live data from Cargo Rush (trips, schedule, sales, income, expenses, invoices, trucks, drivers, customers, assets, payroll, and more). " +
      "Pass a path from the data catalogue in the system prompt and optional query parameters. Returns JSON.",
    parameters: {
      type: "object",
      properties: {
        path: { type: "string", description: 'Path relative to /api/v1, e.g. "trips", "dashboard/business", "trips/01H…/history"' },
        query: {
          type: "object",
          description: 'Query parameters, e.g. {"from": "2026-10-01", "to": "2026-10-31", "per_page": 50}',
          additionalProperties: { type: ["string", "number", "boolean"] },
        },
      },
      required: ["path"],
      additionalProperties: false,
    },
  },
};

export class Assistant {
  private readonly system: string;
  private readonly timezone: string;
  private readonly financeOnly: boolean;

  constructor(
    private readonly llmFor: LlmFactory,
    private readonly cargo: CargoClient,
    options: AssistantOptions,
  ) {
    this.financeOnly = options.financeOnly ?? false;
    this.system = systemPrompt(options.name, options.timezone) + (this.financeOnly
      ? "\n\nThis is the Finance channel. Answer only finance, billing and accounting questions. Use only finance, accounting, expenses, billing, payments and dashboard/business endpoints. Direct other work to the general Assistant channel."
      : "");
    this.timezone = options.timezone;
  }

  async reply(history: Turn[], message: string, settings: BotSettings): Promise<Reply> {
    const llm = this.llmFor(settings);
    const messages: Message[] = [
      { role: "system", content: this.system },
      ...history.map((turn): Message =>
        turn.role === "assistant" && settings.thinking && turn.reasoning
          ? { role: "assistant", content: turn.text, reasoning_content: turn.reasoning }
          : { role: turn.role, content: turn.text },
      ),
      { role: "user", content: message },
    ];

    let lookups = 0;

    for (let round = 0; round < MAX_ROUNDS; round++) {
      const completion = await llm.chat.completions.create(this.request(messages, settings));
      const choice = completion.choices[0];
      if (!choice) break;

      const answer = choice.message as AssistantMessage;
      messages.push(answer as Message);

      const calls = (answer.tool_calls ?? []).filter((call) => call.type === "function");

      if (calls.length === 0) {
        return { text: finalText(answer.content, choice.finish_reason), reasoning: answer.reasoning_content ?? null, lookups };
      }

      lookups += calls.length;
      const results = await Promise.all(calls.map((call) => this.runTool(call.function.name, call.function.arguments)));

      calls.forEach((call, i) => messages.push({ role: "tool", tool_call_id: call.id, content: results[i] ?? "" }));
    }

    return {
      text: "That one needed more digging than I could finish in one go. Could you narrow it down a little, like a specific truck or month?",
      reasoning: null,
      lookups,
    };
  }

  private request(messages: Message[], settings: BotSettings): OpenAI.Chat.Completions.ChatCompletionCreateParamsNonStreaming {
    const base: OpenAI.Chat.Completions.ChatCompletionCreateParamsNonStreaming = {
      model: settings.model,
      messages,
      tools: [CARGO_TOOL],
    };

    if (settings.provider === "deepseek") {
      return { ...base, max_tokens: 8000, thinking: { type: settings.thinking ? "enabled" : "disabled" } } as typeof base;
    }

    return base;
  }

  async runTool(name: string, rawArguments: string): Promise<string> {
    if (name !== CARGO_TOOL.function.name) return `Unknown tool "${name}".`;

    let input: { path?: unknown; query?: unknown };
    try {
      input = JSON.parse(rawArguments || "{}");
    } catch {
      return "Lookup failed: the arguments were not valid JSON. Send {\"path\": \"...\", \"query\": {...}}.";
    }

    if (typeof input.path !== "string") return 'Lookup failed: "path" is required.';

    const query = input.query && typeof input.query === "object" ? (input.query as Record<string, string | number | boolean>) : {};

    try {
      const path = normalisePath(input.path);
      if (this.financeOnly && !/^(?:(?:finance|accounting|expenses|billing|payments)(?:\/|$)|dashboard\/business$)/i.test(path)) {
        return "This lookup is outside the scope of the Finance channel.";
      }
      return trimResult(readable(await this.cargo.get(path, query), this.timezone));
    } catch (error) {
      if (error instanceof CargoApiError) return `Lookup failed: ${error.message}`;
      return `Lookup failed: ${error instanceof Error ? error.message : String(error)}`;
    }
  }
}

export function finalText(content: string | null | undefined, finishReason: string | null | undefined): string {
  const text = (content ?? "").trim();

  if (finishReason === "content_filter") return "Sorry, I can't help with that one.";
  if (text !== "") return text;

  return "I couldn't put an answer together for that. Could you rephrase it?";
}

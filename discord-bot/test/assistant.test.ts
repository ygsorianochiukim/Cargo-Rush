import type OpenAI from "openai";
import { describe, expect, it } from "vitest";

import { Assistant, type AssistantOptions } from "../src/assistant.js";
import type { CargoClient } from "../src/cargo.js";
import type { BotSettings } from "../src/settings.js";

type Request = OpenAI.Chat.Completions.ChatCompletionCreateParamsNonStreaming & {
  thinking?: { type: string };
  messages: (OpenAI.Chat.Completions.ChatCompletionMessageParam & { reasoning_content?: string })[];
};

function fakeLlm(replies: object[]) {
  const requests: Request[] = [];
  const llm = {
    chat: {
      completions: {
        create: async (request: Request) => {
          requests.push(structuredClone(request));
          const message = replies.shift();
          if (!message) throw new Error("no more scripted replies");
          return { choices: [{ message, finish_reason: "tool_calls" in message ? "tool_calls" : "stop" }] };
        },
      },
    },
  } as unknown as OpenAI;

  return { llm, requests };
}

function fakeCargo(data: Record<string, unknown>) {
  const asked: { path: string; query: unknown }[] = [];
  const cargo = {
    get: async (path: string, query: unknown) => {
      asked.push({ path, query });
      if (!(path in data)) throw new Error(`no such path ${path}`);
      return data[path];
    },
  } as unknown as CargoClient;

  return { cargo, asked };
}

const options: AssistantOptions = { name: "Rush", timezone: "Asia/Manila" };
const base: BotSettings = { enabled: true, company: "Eisenlink", provider: "deepseek", model: "deepseek-v4-pro", api_key: "sk-1", thinking: false, discord_ids: [] };

describe("Assistant", () => {
  it("looks the data up, hands it back to the model, and returns the answer", async () => {
    const { llm, requests } = fakeLlm([
      {
        role: "assistant",
        content: null,
        tool_calls: [
          { id: "c1", type: "function", function: { name: "cargo_api_get", arguments: '{"path":"dashboard/business","query":{"period":"month"}}' } },
          { id: "c2", type: "function", function: { name: "cargo_api_get", arguments: '{"path":"trips","query":{"status":"overdue"}}' } },
        ],
      },
      { role: "assistant", content: "Net income this month is **₱77,041.67**, up 14.5%. One trip is overdue." },
    ]);
    const { cargo, asked } = fakeCargo({ "dashboard/business": { data: { net: 7_704_167 } }, trips: { data: [{ reference: "CTS-1" }] } });

    const reply = await new Assistant(() => llm, cargo, options).reply(
      [
        { role: "user", text: "hi" },
        { role: "assistant", text: "Hello!" },
      ],
      "[Yvert · today]\nkumusta ang benta this month?",
      base,
    );

    expect(reply).toEqual({ text: "Net income this month is **₱77,041.67**, up 14.5%. One trip is overdue.", reasoning: null, lookups: 2 });
    expect(asked).toEqual([
      { path: "dashboard/business", query: { period: "month" } },
      { path: "trips", query: { status: "overdue" } },
    ]);

    const first = requests[0]!;
    expect(first.model).toBe("deepseek-v4-pro");
    expect(first.thinking).toEqual({ type: "disabled" });
    expect(first.messages[0]?.role).toBe("system");
    expect(first.messages.slice(1).map((m) => m.role)).toEqual(["user", "assistant", "user"]);

    const second = requests[1]!;
    const toolResults = second.messages.filter((m) => m.role === "tool") as OpenAI.Chat.Completions.ChatCompletionToolMessageParam[];
    expect(toolResults.map((m) => m.tool_call_id)).toEqual(["c1", "c2"]);
    expect(toolResults[0]?.content).toBe('{"data":{"net":7704167}}');
  });

  it("passes DeepSeek's reasoning back on every remembered turn when thinking is on", async () => {
    const { llm, requests } = fakeLlm([{ role: "assistant", content: "Truck 2.", reasoning_content: "compare nets" }]);
    const { cargo } = fakeCargo({});

    const reply = await new Assistant(() => llm, cargo, options).reply(
      [
        { role: "user", text: "best truck?" },
        { role: "assistant", text: "Truck 1.", reasoning: "earlier thought" },
      ],
      "and this month?",
      { ...base, thinking: true },
    );

    expect(reply.reasoning).toBe("compare nets");
    expect(requests[0]?.thinking).toEqual({ type: "enabled" });
    expect(requests[0]?.messages[2]).toEqual({ role: "assistant", content: "Truck 1.", reasoning_content: "earlier thought" });
  });

  it("tells the model when a lookup fails instead of crashing", async () => {
    const { llm, requests } = fakeLlm([
      { role: "assistant", content: null, tool_calls: [{ id: "x", type: "function", function: { name: "cargo_api_get", arguments: '{"path":"nowhere"}' } }] },
      { role: "assistant", content: "I couldn't find that one." },
    ]);
    const { cargo } = fakeCargo({});

    const reply = await new Assistant(() => llm, cargo, options).reply([], "show me nowhere", base);

    expect(reply.text).toBe("I couldn't find that one.");
    const tool = requests[1]?.messages.find((m) => m.role === "tool") as { content: string };
    expect(tool.content).toBe("Lookup failed: no such path nowhere");
  });

  it("sends no DeepSeek-only fields to OpenAI", async () => {
    const { llm, requests } = fakeLlm([{ role: "assistant", content: "Hi!" }]);
    const { cargo } = fakeCargo({});

    await new Assistant(() => llm, cargo, options).reply([], "hello", { ...base, provider: "openai", model: "gpt-4.1" });

    expect(requests[0]?.thinking).toBeUndefined();
    expect(requests[0]?.max_tokens).toBeUndefined();
    expect(requests[0]?.tools?.[0]).toMatchObject({ type: "function", function: { name: "cargo_api_get" } });
  });

  it("rejects bad tool arguments with a message the model can act on", async () => {
    const assistant = new Assistant(() => ({}) as OpenAI, fakeCargo({}).cargo, options);

    await expect(assistant.runTool("cargo_api_get", "{not json")).resolves.toContain("not valid JSON");
    await expect(assistant.runTool("cargo_api_get", "{}")).resolves.toContain('"path" is required');
    await expect(assistant.runTool("delete_everything", "{}")).resolves.toContain("Unknown tool");
  });
});

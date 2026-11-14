export const MAX_RESULT_CHARS = 40_000;

const BLOCKED = [
  /\.pdf$/i,
  /\/export$/i,
  /\/document$/i,
  /^(login|logout|me\/password|forgot-password|reset-password)$/i,
  /^access(\/|$)/i,
  /^assistant(\/|$)/i,
];

export class CargoApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message);
  }
}

export function normalisePath(raw: string): string {
  const path = raw.trim().replace(/^https?:\/\/[^/]+/i, "").replace(/^\/+/, "").replace(/^api\/v1\/+/i, "").replace(/\/+$/, "");

  if (path === "" || path.includes("..") || !/^[A-Za-z0-9\-_/.]+$/.test(path)) {
    throw new CargoApiError(`"${raw}" is not a valid API path. Use something like "trips" or "dashboard/business".`, 400);
  }

  if (BLOCKED.some((rule) => rule.test(path))) {
    throw new CargoApiError(`"${path}" is not available to the assistant.`, 403);
  }

  return path;
}

export function trimResult(body: unknown, limit = MAX_RESULT_CHARS): string {
  const text = JSON.stringify(body);

  if (text.length <= limit) return text;

  return (
    text.slice(0, limit) +
    `\n…[truncated: the response was ${text.length.toLocaleString()} characters. ` +
    "Ask again with narrower filters (from/to dates, status, search) or a smaller per_page.]"
  );
}

type Query = Record<string, string | number | boolean | undefined | null>;

export class CargoClient {
  constructor(
    readonly baseUrl: string,
    private token: string,
    private readonly fetcher: typeof fetch = fetch,
  ) {}

  setToken(token: string): void {
    this.token = token;
  }

  hasToken(): boolean {
    return this.token !== "";
  }

  get(rawPath: string, query: Query = {}): Promise<unknown> {
    return this.fetch(normalisePath(rawPath), query);
  }

  botConfig(): Promise<unknown> {
    return this.fetch("assistant/bot-config", {});
  }

  private async fetch(path: string, query: Query): Promise<unknown> {
    const url = new URL(`${this.baseUrl}/${path}`);

    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== "") url.searchParams.set(key, String(value));
    }

    const response = await this.fetcher(url, {
      headers: { Accept: "application/json", Authorization: `Bearer ${this.token}` },
      signal: AbortSignal.timeout(30_000),
    });

    const body = await response.json().catch(() => null);

    if (response.status === 401) {
      throw new CargoApiError(
        "The bot token was rejected. Generate a new one in Cargo Rush → Assistant Settings and put it in CARGO_BOT_TOKEN.",
        401,
      );
    }

    if (!response.ok) {
      const message =
        (body && typeof body === "object" && "message" in body && typeof body.message === "string" && body.message) ||
        response.statusText;

      throw new CargoApiError(`${response.status} on ${path}: ${message}`, response.status);
    }

    return body;
  }
}

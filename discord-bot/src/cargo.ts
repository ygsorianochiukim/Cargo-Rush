export const MAX_RESULT_CHARS = 40_000;

const BLOCKED = [
  /\.pdf$/i,
  /\/export$/i,
  /\/document$/i,
  /^(login|logout|me\/password|forgot-password|reset-password)$/i,
  /^access(\/|$)/i,
  /^assistant(\/|$)/i,
  /^expenses\/(?:receipt-archive|archived)(\/|$)/i,
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

  financeActivity(query: Query): Promise<unknown> {
    return this.fetch("assistant/finance-activity", query);
  }

  payrollAlerts(channelId: string): Promise<unknown> {
    return this.fetch("assistant/payroll-alerts", { channel_id: channelId });
  }

  async markPayrollAlertDelivered(id: string, channelId: string): Promise<void> {
    await this.fetch("assistant/payroll-alerts/" + id + "/delivered", {}, {
      method: "POST", body: JSON.stringify({ channel_id: channelId }), headers: { "Content-Type": "application/json" },
    });
  }

  uploadReceipt(input: FormData): Promise<unknown> {
    return this.fetch("assistant/receipts", {}, { method: "POST", body: input });
  }

  receipt(id: string, identity: Query): Promise<unknown> {
    return this.fetch("assistant/receipts/" + id, identity);
  }

  decideReceipt(id: string, input: object): Promise<unknown> {
    return this.fetch("assistant/receipts/" + id, {}, {
      method: "POST", body: JSON.stringify(input), headers: { "Content-Type": "application/json" },
    });
  }

  receiptEditLink(id: string, input: object): Promise<unknown> {
    return this.fetch("assistant/receipts/" + id + "/edit-link", {}, {
      method: "POST", body: JSON.stringify(input), headers: { "Content-Type": "application/json" },
    });
  }

  async reportStatus(status: Record<string, unknown>): Promise<void> {
    await this.fetcher(`${this.baseUrl}/assistant/bot-status`, {
      method: "POST",
      headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${this.token}` },
      body: JSON.stringify(status),
      signal: AbortSignal.timeout(15_000),
    }).catch(() => undefined);
  }

  private async fetch(path: string, query: Query, options: RequestInit = {}): Promise<unknown> {
    const url = new URL(`${this.baseUrl}/${path}`);

    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== "") url.searchParams.set(key, String(value));
    }

    const response = await this.fetcher(url, {
      ...options,
      headers: { Accept: "application/json", Authorization: "Bearer " + this.token, ...options.headers },
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

const INSTANT = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/;

function localTime(value: string, timezone: string): string {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat("en-CA", {
      timeZone: timezone,
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      hourCycle: "h23",
    })
      .formatToParts(new Date(value))
      .map((part) => [part.type, part.value]),
  );

  return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}`;
}

export function readable(body: unknown, timezone: string): unknown {
  if (Array.isArray(body)) return body.map((item) => readable(item, timezone));

  if (typeof body === "string") return INSTANT.test(body) && !Number.isNaN(Date.parse(body)) ? localTime(body, timezone) : body;

  if (body === null || typeof body !== "object") return body;

  const out: Record<string, unknown> = {};

  for (const [key, value] of Object.entries(body)) {
    const cents = typeof value === "string" && /^-?\d+$/.test(value) ? Number(value) : value;

    if (key.endsWith("_cents") && (typeof cents === "number" || cents === null)) {
      out[`${key.slice(0, -"_cents".length)}_pesos`] = cents === null ? null : Math.round(cents) / 100;
    } else {
      out[key] = readable(value, timezone);
    }
  }

  return out;
}

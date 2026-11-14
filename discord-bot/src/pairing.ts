import { chmodSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { dirname } from "node:path";

export class CredentialStore {
  constructor(private readonly file: string) {}

  load(): string | null {
    try {
      const saved = JSON.parse(readFileSync(this.file, "utf8")) as { token?: unknown };
      return typeof saved.token === "string" && saved.token !== "" ? saved.token : null;
    } catch {
      return null;
    }
  }

  save(token: string): void {
    mkdirSync(dirname(this.file), { recursive: true });
    writeFileSync(this.file, JSON.stringify({ token }), { mode: 0o600 });
    try {
      chmodSync(this.file, 0o600);
    } catch {
      return;
    }
  }

  clear(): void {
    rmSync(this.file, { force: true });
  }
}

interface PairingRequest {
  id: string;
  code: string;
  secret: string;
  expires_at: string;
}

export interface PairingOptions {
  fetcher?: typeof fetch;
  pollMs?: number;
  retryMs?: number;
  log?: (line: string) => void;
  sleep?: (ms: number) => Promise<void>;
}

const wait = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

export async function pair(baseUrl: string, options: PairingOptions = {}): Promise<string> {
  const fetcher = options.fetcher ?? fetch;
  const pollMs = options.pollMs ?? 3_000;
  const retryMs = options.retryMs ?? 30_000;
  const log = options.log ?? console.log;
  const sleep = options.sleep ?? wait;

  for (;;) {
    let request: PairingRequest;

    try {
      const response = await fetcher(`${baseUrl}/assistant/pairings`, {
        method: "POST",
        headers: { Accept: "application/json" },
        signal: AbortSignal.timeout(30_000),
      });

      if (!response.ok) {
        const body = (await response.json().catch(() => null)) as { message?: string } | null;
        log(`Could not ask Cargo Rush to connect (${response.status}${body?.message ? `: ${body.message}` : ""}). Retrying shortly.`);
        await sleep(retryMs);
        continue;
      }

      request = ((await response.json()) as { data: PairingRequest }).data;
    } catch (error) {
      log(`Cannot reach Cargo Rush at ${baseUrl} (${error instanceof Error ? error.message : String(error)}). Retrying shortly.`);
      await sleep(retryMs);
      continue;
    }

    log("");
    log(`  Connection code: ${request.code}`);
    log("  Approve it in Cargo Rush → Administration → Assistant Settings.");
    log("");

    for (;;) {
      await sleep(pollMs);

      const status = await fetcher(`${baseUrl}/assistant/pairings/${request.id}`, {
        headers: { Accept: "application/json", "X-Pairing-Secret": request.secret },
        signal: AbortSignal.timeout(30_000),
      })
        .then(async (r) => (r.ok ? ((await r.json()) as { data: { status: string; token?: string } }).data : { status: r.status === 429 ? "pending" : "lost" }))
        .catch(() => ({ status: "pending" as string, token: undefined }));

      if (status.status === "approved" && status.token) {
        log("Approved. Connected to Cargo Rush.");
        return status.token;
      }

      if (status.status !== "pending") {
        log(status.status === "expired" ? "The code expired. Getting a new one." : `The request was ${status.status}. Asking again.`);
        break;
      }
    }
  }
}

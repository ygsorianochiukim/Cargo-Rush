import { mkdirSync, readFileSync, writeFileSync, renameSync } from "node:fs";
import { dirname } from "node:path";

export interface Turn {
  role: "user" | "assistant";
  text: string;
  reasoning?: string;
}

export class ConversationMemory {
  private readonly conversations = new Map<string, Turn[]>();
  private saveTimer: NodeJS.Timeout | null = null;

  constructor(
    private readonly maxTurns: number,
    private readonly file: string | null = null,
  ) {
    this.load();
  }

  history(key: string): Turn[] {
    return [...(this.conversations.get(key) ?? [])];
  }

  record(key: string, user: string, assistant: string, reasoning?: string | null): void {
    const reply: Turn = reasoning ? { role: "assistant", text: assistant, reasoning } : { role: "assistant", text: assistant };
    const turns: Turn[] = [...(this.conversations.get(key) ?? []), { role: "user", text: user }, reply];
    const keep = this.maxTurns * 2;

    this.conversations.set(key, turns.length > keep ? turns.slice(turns.length - keep) : turns);
    this.scheduleSave();
  }

  forget(key: string): void {
    this.conversations.delete(key);
    this.scheduleSave();
  }

  flush(): void {
    if (this.saveTimer) clearTimeout(this.saveTimer);
    this.saveTimer = null;
    this.save();
  }

  private scheduleSave(): void {
    if (this.file === null || this.saveTimer) return;

    this.saveTimer = setTimeout(() => {
      this.saveTimer = null;
      this.save();
    }, 2_000);
  }

  private save(): void {
    if (this.file === null) return;

    mkdirSync(dirname(this.file), { recursive: true });
    const temp = `${this.file}.tmp`;
    writeFileSync(temp, JSON.stringify(Object.fromEntries(this.conversations)));
    renameSync(temp, this.file);
  }

  private load(): void {
    if (this.file === null) return;

    try {
      const raw = JSON.parse(readFileSync(this.file, "utf8")) as Record<string, Turn[]>;
      for (const [key, turns] of Object.entries(raw)) {
        if (Array.isArray(turns)) this.conversations.set(key, turns.slice(-this.maxTurns * 2));
      }
    } catch {
      return;
    }
  }
}

export class KeyedQueue {
  private readonly tails = new Map<string, Promise<unknown>>();

  run<T>(key: string, task: () => Promise<T>): Promise<T> {
    const previous = this.tails.get(key) ?? Promise.resolve();
    const next = previous.catch(() => undefined).then(task);
    const tail = next.catch(() => undefined);

    this.tails.set(key, tail);
    void tail.then(() => {
      if (this.tails.get(key) === tail) this.tails.delete(key);
    });

    return next;
  }
}

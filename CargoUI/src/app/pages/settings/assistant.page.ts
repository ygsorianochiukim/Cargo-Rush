import { ChangeDetectionStrategy, Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable } from 'rxjs';

import {
  AssistantBotRole,
  AssistantDiscordUser,
  AssistantProvider,
  AssistantSettings,
  AssistantSettingsPayload,
} from '../../models/assistant/assistant.model';
import { AssistantService } from '../../services/assistant/assistant.service';
import { Card } from '../../shared/card';
import { Field } from '../../shared/field';

const INPUT =
  'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink focus:border-cr-blue focus:outline-none disabled:opacity-60';
const PRIMARY =
  'h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60';
const SECONDARY =
  'h-10 rounded-control border border-cr-line px-4 text-[14px] font-semibold text-cr-ink-muted transition-colors hover:bg-cr-tint disabled:opacity-60';

@Component({
  selector: 'app-assistant-settings',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Field],
  template: `
    @if (settings(); as s) {
      <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <app-card heading="Discord assistant" icon="gauge" hint="Answers questions about the business on Discord">
          <label class="flex items-start gap-2 text-[14px]">
            <input
              type="checkbox"
              class="mt-1 h-4 w-4 flex-none accent-cr-blue"
              [checked]="enabled()"
              [disabled]="busy()"
              (change)="enabled.set($any($event.target).checked)"
            />
            <span>
              Assistant switched on
              <span class="block text-[12px] text-cr-ink-muted">
                This controls conversations and reports. Conversations also require an AI key.
              </span>
            </span>
          </label>

          <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <app-field label="AI PROVIDER">
              <select [class]="input" [value]="provider()" [disabled]="busy()" (change)="pickProvider($any($event.target).value)">
                @for (p of s.providers; track p.value) {
                  <option [value]="p.value" [selected]="p.value === provider()">{{ p.label }}</option>
                }
              </select>
            </app-field>
            <app-field label="MODEL" [hint]="'Blank uses ' + defaultModel()" [error]="errorFor('llm_model')">
              <input
                type="text"
                [class]="input"
                [value]="model()"
                [placeholder]="defaultModel()"
                [disabled]="busy()"
                (input)="model.set($any($event.target).value)"
              />
            </app-field>
          </div>

          <div class="mt-3">
            <app-field
              label="API KEY"
              [hint]="s.has_key ? 'A key ending in …' + s.key_hint + ' is saved, encrypted. Leave blank to keep it.' : 'No key saved yet.'"
              [error]="errorFor('api_key')"
            >
              <input
                type="password"
                autocomplete="off"
                [class]="input"
                [value]="apiKey()"
                [placeholder]="s.has_key ? '•••••••• ' + s.key_hint : 'Paste the key from your ' + providerLabel() + ' account'"
                [disabled]="busy()"
                (input)="apiKey.set($any($event.target).value)"
              />
            </app-field>
            @if (s.has_key) {
              <button
                type="button"
                class="mt-1 text-[12px] font-semibold text-cr-red hover:underline disabled:opacity-60"
                [disabled]="busy()"
                (click)="removeKey()"
              >
                Remove the saved key
              </button>
            }
          </div>

          <div class="mt-3">
            <app-field
              label="DISCORD BOT TOKEN"
              [hint]="s.has_discord_token ? 'A token ending in …' + s.discord_token_hint + ' is saved, encrypted. Leave blank to keep it.' : 'From the Discord Developer Portal → your app → Bot → Reset Token.'"
              [error]="errorFor('discord_token')"
            >
              <input
                type="password"
                autocomplete="off"
                [class]="input"
                [value]="discordToken()"
                [placeholder]="s.has_discord_token ? '•••••••• ' + s.discord_token_hint : 'Paste the bot token'"
                [disabled]="busy()"
                (input)="discordToken.set($any($event.target).value)"
              />
            </app-field>
          </div>

          <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <app-field label="WHAT THE BOT CAN SEE" hint="The role its Cargo Rush account has">
              <select [class]="input" [value]="role()" [disabled]="busy()" (change)="role.set($any($event.target).value)">
                <option value="accountant" [selected]="role() === 'accountant'">Accountant: finance, trips, fleet</option>
                <option value="administrator" [selected]="role() === 'administrator'">Administrator: everything, incl. payroll and HR</option>
              </select>
            </app-field>
            @if (provider() === 'deepseek') {
              <label class="flex items-start gap-2 self-center text-[14px]">
                <input
                  type="checkbox"
                  class="mt-1 h-4 w-4 flex-none accent-cr-blue"
                  [checked]="thinking()"
                  [disabled]="busy()"
                  (change)="thinking.set($any($event.target).checked)"
                />
                <span>
                  Deeper reasoning
                  <span class="block text-[12px] text-cr-ink-muted">Better on hard questions, slower to reply.</span>
                </span>
              </label>
            }
          </div>

          @if (failure(); as message) {
            <p role="alert" class="mt-3 text-[12px] font-medium text-cr-red">{{ message }}</p>
          }
          @if (notice(); as message) {
            <p class="mt-3 text-[12px] font-medium text-cr-green">{{ message }}</p>
          }

          <button type="button" [class]="primary + ' mt-4'" [disabled]="busy() || !dirty()" (click)="save()">
            {{ busy() ? 'Saving…' : 'Save settings' }}
          </button>
        </app-card>

        <app-card heading="Who can message it" icon="customers" hint="Only these Discord accounts get answers">
          <div class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <app-field label="DISCORD USER ID" [error]="errorFor('discord_id')">
              <input
                type="text"
                inputmode="numeric"
                [class]="input + ' cr-num'"
                placeholder="e.g. 1571205865602687158"
                [value]="newId()"
                [disabled]="busy()"
                (input)="newId.set($any($event.target).value)"
              />
            </app-field>
            <app-field label="NAME">
              <input
                type="text"
                [class]="input"
                placeholder="Who this is"
                [value]="newLabel()"
                [disabled]="busy()"
                (input)="newLabel.set($any($event.target).value)"
              />
            </app-field>
            <button type="button" [class]="primary" [disabled]="busy() || newId().trim() === ''" (click)="addUser()">Add</button>
          </div>
          <p class="cr-meta mt-2">
            To find an ID in Discord: Settings → Advanced → turn on Developer Mode, then right-click the person → Copy
            User ID.
          </p>

          <ul class="mt-4 divide-y divide-cr-line/70">
            @for (u of s.discord_users; track u.id) {
              <li class="flex items-center justify-between gap-3 py-2 text-[13px]">
                <span class="min-w-0">
                  <span class="block truncate font-medium">{{ u.label ?? 'No name' }}</span>
                  <span class="cr-num text-[12px] text-cr-ink-muted">{{ u.discord_id }}</span>
                </span>
                <label class="flex items-center gap-1.5 text-xs text-cr-ink-muted">
                  <input
                    type="checkbox"
                    class="accent-cr-blue"
                    [checked]="u.may_confirm_receipts"
                    [disabled]="busy()"
                    (change)="setReceiptAccess(u, $any($event.target).checked)"
                  />
                  Confirm receipts
                </label>
                <button
                  type="button"
                  class="flex-none text-[12px] font-semibold text-cr-red hover:underline disabled:opacity-60"
                  [disabled]="busy()"
                  (click)="removeUser(u.id)"
                >
                  Remove
                </button>
              </li>
            } @empty {
              <li class="py-4 text-center text-[13px] text-cr-ink-muted">Nobody yet. The bot answers no one until you add someone.</li>
            }
          </ul>
        </app-card>

        <app-card heading="Bot connection" icon="shield" hint="How the bot gets into Cargo Rush" class="xl:col-span-2">
          @for (p of s.pairings; track p.id) {
            <div class="mb-3 flex flex-wrap items-center gap-3 rounded-control border border-cr-blue/30 bg-cr-tint p-3">
              <div class="min-w-0 flex-1">
                <p class="text-[13px] font-semibold">A bot is asking to connect</p>
                <p class="cr-meta">
                  Requested {{ when(p.requested_at) }}{{ p.ip_address ? ' from ' + p.ip_address : '' }}. Approve it only if the
                  bot you just started shows this code.
                </p>
              </div>
              <span class="cr-num rounded-control bg-cr-surface px-3 py-1.5 text-[18px] font-semibold tracking-[0.12em] text-cr-blue">
                {{ p.code }}
              </span>
              <button type="button" [class]="primary" [disabled]="busy()" (click)="approve(p.id)">Approve</button>
              <button type="button" [class]="secondary" [disabled]="busy()" (click)="dismiss(p.id)">Dismiss</button>
            </div>
          }

          <p class="text-[13px]">
            @if (s.token.issued) {
              <span class="font-semibold text-cr-success">Connected to Cargo Rush.</span>
              @if (s.token.last_used_at) {
                Last checked in {{ when(s.token.last_used_at) }}.
              } @else {
                Waiting for the bot to check in.
              }
            } @else if (s.pairings.length === 0) {
              Not connected. Start the bot and its connection request appears here within a few seconds.
              <button type="button" class="ml-1 font-semibold text-cr-blue hover:underline" (click)="reload()">Check again</button>
            }
          </p>

          @if (s.token.issued) {
            <div class="mt-2 text-[13px]">
              @if (s.discord_status; as d) {
                @switch (d.state) {
                  @case ('online') {
                    @if ((d.servers ?? 0) > 0) {
                      <p>
                        <span class="font-semibold text-cr-success">Online on Discord</span> as {{ d.tag }}, in
                        {{ d.servers }} {{ d.servers === 1 ? 'server' : 'servers' }}.
                      </p>
                    } @else {
                      <p>
                        <span class="font-semibold text-cr-warning">Online on Discord as {{ d.tag }}, but not in any server yet.</span>
                        People can only reach it once it is in a server you share.
                      </p>
                      @if (d.invite_url) {
                        <a [href]="d.invite_url" target="_blank" rel="noopener" [class]="primary + ' mt-2 inline-flex items-center'">
                          Add it to your Discord server
                        </a>
                      }
                    }
                  }
                  @case ('refused') {
                    <p class="font-semibold text-cr-red">Discord refused the bot token.</p>
                    <p class="cr-meta mt-0.5">{{ discordHint(d.error) }}</p>
                  }
                  @case ('no_token') {
                    <p class="font-semibold text-cr-warning">Waiting for a Discord bot token. Paste it above and save.</p>
                  }
                  @default {
                    <p class="text-cr-ink-muted">Connecting to Discord…</p>
                  }
                }
                <p class="cr-meta mt-1">Reported {{ when(d.reported_at) }}.</p>
              } @else {
                <p class="text-cr-ink-muted">
                  No Discord status from the bot yet. It reports within a minute of starting; if this stays empty, the
                  running bot is an older version, so redeploy it.
                </p>
              }
            </div>
          }

          @if (s.token.issued) {
            <button type="button" [class]="secondary + ' mt-3'" [disabled]="busy()" (click)="revokeToken()">Disconnect the bot</button>
          }
        </app-card>
      </div>
    } @else if (failure(); as message) {
      <p role="alert" class="text-[13px] font-medium text-cr-red">{{ message }}</p>
    } @else {
      <p class="cr-meta">Loading the assistant settings…</p>
    }
  `,
})
export class AssistantPage {
  private readonly api = inject(AssistantService);

  protected readonly input = INPUT;
  protected readonly primary = PRIMARY;
  protected readonly secondary = SECONDARY;

  protected readonly settings = signal<AssistantSettings | null>(null);
  protected readonly busy = signal(false);
  protected readonly failure = signal<string | null>(null);
  protected readonly notice = signal<string | null>(null);
  protected readonly errors = signal<Record<string, string[]>>({});

  protected readonly enabled = signal(false);
  protected readonly provider = signal<AssistantProvider>('deepseek');
  protected readonly model = signal('');
  protected readonly apiKey = signal('');
  protected readonly thinking = signal(false);
  protected readonly role = signal<AssistantBotRole>('accountant');

  protected readonly newId = signal('');
  protected readonly newLabel = signal('');

  protected readonly discordToken = signal('');

  protected readonly defaultModel = computed(
    () => this.settings()?.providers.find((p) => p.value === this.provider())?.default_model ?? '',
  );

  protected readonly providerLabel = computed(
    () => this.settings()?.providers.find((p) => p.value === this.provider())?.label ?? 'AI',
  );

  protected readonly dirty = computed(() => {
    const s = this.settings();
    if (s === null) return false;

    return (
      this.apiKey().trim() !== '' ||
      this.discordToken().trim() !== '' ||
      this.enabled() !== s.enabled ||
      this.provider() !== s.provider ||
      this.model().trim() !== (s.model ?? '') ||
      this.thinking() !== s.thinking ||
      this.role() !== s.bot_role
    );
  });

  constructor() {
    this.reload();

    let ticks = 0;
    const timer = setInterval(() => {
      ticks++;
      const s = this.settings();
      if (s === null || this.busy()) return;
      if (!s.token.issued || ticks % 6 === 0) this.reload();
    }, 5_000);

    inject(DestroyRef).onDestroy(() => clearInterval(timer));
  }

  protected reload(): void {
    this.api.settings().subscribe({
      next: (s) => this.load(s, this.settings() === null),
      error: (e: unknown) => this.failure.set(this.messageFor(e, 'Could not load the assistant settings.')),
    });
  }

  protected pickProvider(value: AssistantProvider): void {
    if (value !== this.provider()) this.model.set('');
    this.provider.set(value);
  }

  protected errorFor(field: string): string | null {
    return this.errors()[field]?.[0] ?? null;
  }

  protected save(): void {
    const payload: AssistantSettingsPayload = {
      enabled: this.enabled(),
      llm_provider: this.provider(),
      llm_model: this.model().trim() || null,
      llm_thinking: this.provider() === 'deepseek' && this.thinking(),
      bot_role: this.role(),
      ...(this.apiKey().trim() !== '' ? { api_key: this.apiKey().trim() } : {}),
      ...(this.discordToken().trim() !== '' ? { discord_token: this.discordToken().trim() } : {}),
    };

    this.run(this.api.save(payload), 'Saved.', () => {
      this.apiKey.set('');
      this.discordToken.set('');
    });
  }

  protected removeKey(): void {
    if (!confirm('Remove the saved AI key? The assistant stops answering until a new one is saved.')) return;
    this.run(this.api.save({ clear_key: true }), 'Key removed.');
  }

  protected addUser(): void {
    this.run(this.api.addDiscordUser(this.newId().trim(), this.newLabel().trim()), 'Added.', () => {
      this.newId.set('');
      this.newLabel.set('');
    });
  }

  protected removeUser(id: string): void {
    this.run(this.api.removeDiscordUser(id), 'Removed.');
  }

  protected setReceiptAccess(user: AssistantDiscordUser, enabled: boolean): void {
    this.run(this.api.addDiscordUser(user.discord_id, user.label, enabled), 'Receipt access saved.');
  }

  protected approve(id: string): void {
    this.run(this.api.approvePairing(id), 'Approved. The bot connects within a few seconds.');
  }

  protected dismiss(id: string): void {
    this.run(this.api.dismissPairing(id), 'Dismissed.');
  }

  protected revokeToken(): void {
    if (!confirm('Disconnect the bot? It stops answering and asks to connect again, which you approve here.')) return;
    this.run(this.api.revokeToken(), 'Disconnected.');
  }

  protected discordHint(error: string | null | undefined): string {
    const text = (error ?? '').toLowerCase();

    if (text.includes('disallowed intents')) {
      return 'Turn on Message Content Intent: Discord Developer Portal → your app → Bot → Privileged Gateway Intents, then save there. The bot retries within a minute.';
    }

    if (text.includes('invalid') || text.includes('token')) {
      return 'The token is wrong or was reset. In the Developer Portal → Bot → Reset Token, copy the new one and paste it above.';
    }

    return error ?? 'Check the token in the Developer Portal.';
  }

  protected when(iso: string | null): string {
    return iso === null ? '' : new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
  }

  private run(request: Observable<AssistantSettings>, success: string, after?: () => void): void {
    this.busy.set(true);
    this.clearMessages();

    request.subscribe({
      next: (s) => {
        this.busy.set(false);
        this.load(s, false);
        this.notice.set(success);
        after?.();
      },
      error: (e: unknown) => this.fail(e),
    });
  }

  private load(s: AssistantSettings, resetForm = true): void {
    this.settings.set(s);

    if (resetForm || !this.dirty()) {
      this.enabled.set(s.enabled);
      this.provider.set(s.provider);
      this.model.set(s.model ?? '');
      this.thinking.set(s.thinking);
      this.role.set(s.bot_role);
    }
  }

  private clearMessages(): void {
    this.failure.set(null);
    this.notice.set(null);
    this.errors.set({});
  }

  private fail(e: unknown): void {
    this.busy.set(false);
    const body = e instanceof HttpErrorResponse ? (e.error as { errors?: Record<string, string[]> } | null) : null;
    this.errors.set(body?.errors ?? {});
    this.failure.set(this.messageFor(e, 'That did not save. Try again.'));
  }

  private messageFor(e: unknown, fallback: string): string {
    if (e instanceof HttpErrorResponse) {
      const message = (e.error as { message?: string } | null)?.message;
      if (e.status === 403) return 'Only an administrator or general manager can change these settings.';
      if (message) return message;
    }

    return fallback;
  }
}

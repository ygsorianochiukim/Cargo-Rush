import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';
import { EMPTY, Subject, catchError, exhaustMap, filter, finalize, merge, takeUntil, timer } from 'rxjs';

import {
  AssistantChannelField,
  DiscordBotConfiguration,
  DiscordBotSettings as BotSettings,
} from '../models/assistant/assistant.model';
import { AssistantService } from '../services/assistant/assistant.service';
import { IdentityService } from '../services/identity/identity.service';
import { Card } from './card';
import { Field } from './field';

const CHANNELS = [
  { key: 'discord_assistant_channel_id', label: 'Assistant channel', hint: 'General Cargo Rush questions' },
  { key: 'discord_finance_channel_id', label: 'Finance channel', hint: 'Finance questions and daily reports' },
  { key: 'discord_finance_alerts_channel_id', label: 'Finance alerts channel', hint: 'Optional. Defaults to the Finance channel.' },
  { key: 'discord_hr_channel_id', label: 'HR assistant channel', hint: 'Private payroll alerts with employee names and net pay' },
] as const;

const CONFIGURATION_KEYS = [
  'enabled',
  'discord_assistant_channel_id',
  'discord_finance_channel_id',
  'discord_finance_alerts_channel_id',
  'discord_hr_channel_id',
  'discord_payment_alert_pesos',
] as const satisfies readonly (keyof DiscordBotConfiguration)[];

@Component({
  selector: 'app-discord-bot-settings',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DatePipe, Field, RouterLink],
  templateUrl: './discord-bot-settings.html',
})
export class DiscordBotSettings {
  private readonly api = inject(AssistantService);
  private readonly identity = inject(IdentityService);
  private readonly reload = new Subject<void>();
  private readonly savingStarted = new Subject<void>();
  private readonly destroyRef = inject(DestroyRef);

  protected readonly settings = signal<BotSettings | null>(null);
  protected readonly draft = signal<DiscordBotConfiguration | null>(null);
  protected readonly saving = signal(false);
  protected readonly error = signal('');
  protected readonly notice = signal('');
  protected readonly fieldErrors = signal<Record<string, string[]>>({});
  protected readonly fields = CHANNELS;
  protected readonly canManageConnection = computed(() => this.identity.can('company.manage'));
  protected readonly changes = computed(() => {
    const form = this.draft();
    const current = this.settings();
    if (!form || !current) return {};

    return Object.fromEntries(CONFIGURATION_KEYS
      .filter((key) => form[key] !== current[key])
      .map((key) => [key, form[key]])) as Partial<DiscordBotConfiguration>;
  });
  protected readonly dirty = computed(() => Object.keys(this.changes()).length > 0);
  protected readonly connection = computed(() => {
    const settings = this.settings();
    if (!settings?.paired) return 'Unpaired';

    switch (settings.discord_status?.state) {
      case 'online': return 'Online';
      case 'connecting': return 'Connecting';
      case 'no_token': return 'Waiting for a Discord token';
      case 'refused': return 'Discord rejected the token';
      default: return 'Waiting for bot check-in';
    }
  });

  constructor() {
    merge(timer(0, 30_000), this.reload).pipe(
      filter(() => !this.saving()),
      exhaustMap(() => this.api.discordSettings().pipe(
        takeUntil(this.savingStarted),
        catchError((error: unknown) => {
          if (error instanceof HttpErrorResponse && [401, 403].includes(error.status)) {
            this.settings.set(null);
            this.draft.set(null);
          }
          this.error.set('Could not load Discord bot settings.');
          return EMPTY;
        }),
      )),
      takeUntilDestroyed(),
    ).subscribe((settings) => {
      if (this.saving()) return;
      const changes = this.changes();
      this.settings.set(settings);
      this.draft.set({ ...settings, ...changes });
      if (this.error() === 'Could not load Discord bot settings.') this.error.set('');
    });
  }

  refresh(): void {
    this.reload.next();
  }

  protected fieldError(key: string): string | null {
    return this.fieldErrors()[key]?.[0] ?? null;
  }

  protected setEnabled(enabled: boolean): void {
    this.draft.update((draft) => draft && { ...draft, enabled });
  }

  protected setChannel(key: AssistantChannelField, input: string): void {
    const value = input.trim();
    const link = /^https:\/\/(?:discord\.com|discordapp\.com)\/channels\/\d{17,20}\/(\d{17,20})\/?$/.exec(value);
    this.draft.update((draft) => draft && { ...draft, [key]: link?.[1] ?? (value || null) });
  }

  protected setPaymentAlert(value: number): void {
    this.draft.update((draft) => draft && {
      ...draft,
      discord_payment_alert_pesos: Number.isNaN(value) ? null : value,
    });
  }

  protected save(): void {
    if (this.saving() || !this.dirty()) return;

    this.saving.set(true);
    this.savingStarted.next();
    this.error.set('');
    this.notice.set('');
    this.fieldErrors.set({});
    this.api.saveDiscordSettings(this.changes()).pipe(
      takeUntilDestroyed(this.destroyRef),
      finalize(() => this.saving.set(false)),
    ).subscribe({
      next: (settings) => {
        this.settings.set(settings);
        this.draft.set(settings);
        this.notice.set('Discord bot settings saved.');
      },
      error: (error: unknown) => {
        if (error instanceof HttpErrorResponse && error.status === 422) {
          this.fieldErrors.set(error.error?.errors ?? {});
        }
        const message = error instanceof HttpErrorResponse ? error.error?.message : null;
        this.error.set(typeof message === 'string' ? message : 'Could not save Discord bot settings.');
      },
    });
  }
}

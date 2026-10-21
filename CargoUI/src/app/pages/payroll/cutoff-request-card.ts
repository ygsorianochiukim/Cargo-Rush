import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import { PayrollCalendar, PayrollCutoffRequest } from '../../models/hr/payroll.model';
import { PayrollCutoffRequestService, PayrollService } from '../../services/hr/payroll.service';
import { IdentityService } from '../../services/identity/identity.service';
import { Card } from '../../shared/card';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { Modal } from '../../shared/modal';

@Component({
  selector: 'app-cutoff-request-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Field, Icon, Modal, ReactiveFormsModule],
  template: `
    <app-card heading="Pay cutoff" icon="calendar" hint="The period payroll runs on">
      <p class="text-[14px] font-medium">{{ description() }}</p>

      @if (pending(); as request) {
        <div class="mt-3 rounded-control border border-cr-line bg-cr-tint p-3">
          <p class="text-[13px] font-semibold">Change requested</p>
          <p class="cr-meta mt-0.5">{{ request.summary }}</p>
          <p class="mt-1 text-[12px] text-cr-ink-muted">
            {{ request.reason }}
          </p>
          <p class="cr-meta mt-1">
            Asked for by {{ request.requested_by_name ?? 'somebody' }} ·
            {{ fmt.date(request.created_at) }}
          </p>

          @if (request.blocked_reason; as blocked) {
            <p class="mt-2 text-[12px] font-medium text-cr-red">{{ blocked }}</p>
          }

          @if (canDecide()) {
            <p class="cr-meta mt-2">Approve or decline it on the Benefits page.</p>
          } @else if (mine(request)) {
            <button
              type="button"
              class="mt-2 h-8 rounded-control border border-cr-line px-3 text-[12px] font-semibold text-cr-ink-muted transition-colors hover:bg-cr-surface disabled:opacity-60"
              [disabled]="busy()"
              (click)="withdraw(request)"
            >
              Withdraw request
            </button>
          }
        </div>
      } @else if (canDecide()) {
        <p class="cr-meta mt-2">
          You can change this yourself on the Benefits page, under Payroll cutoff.
        </p>
      } @else {
        <button
          type="button"
          class="mt-3 flex h-9 items-center gap-1.5 rounded-control border border-cr-blue px-3 text-[13px] font-semibold text-cr-blue transition-colors hover:bg-cr-tint"
          (click)="openForm()"
        >
          <app-icon name="calendar" [size]="16" />
          Request a different cutoff
        </button>
      }

      @if (notice(); as message) {
        <p role="status" class="mt-3 text-[12px] font-medium text-cr-green">{{ message }}</p>
      }

      @if (failure(); as message) {
        <p role="alert" class="mt-3 text-[12px] font-medium text-cr-red">{{ message }}</p>
      }
    </app-card>

    <app-modal
      [(open)]="formOpen"
      title="Request a different cutoff"
      subtitle="An administrator decides it"
      icon="calendar"
      [locked]="busy()"
    >
      <form [formGroup]="form" class="grid gap-3 sm:grid-cols-2" (ngSubmit)="file()">
        <div class="sm:col-span-2">
          <p class="cr-meta">Currently: {{ description() }}</p>
        </div>

        <app-field label="How often" class="sm:col-span-2">
          <select
            formControlName="shape"
            class="h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px]"
          >
            <option value="twice">Twice a month</option>
            <option value="once">Once a month</option>
          </select>
        </app-field>

        @if (form.controls.shape.value === 'twice') {
          <app-field label="First cutoff" hint="The 27th at the latest, because of February.">
            <select
              formControlName="firstDay"
              class="h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px]"
            >
              @for (day of firstDayChoices; track day) {
                <option [value]="day">{{ dayLabel(day) }}</option>
              }
            </select>
          </app-field>
        }

        <app-field [label]="form.controls.shape.value === 'twice' ? 'Second cutoff' : 'Cutoff'">
          <select
            formControlName="lastDay"
            class="h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px]"
          >
            @for (day of lastDayChoices; track day) {
              <option [value]="day">{{ dayLabel(day) }}</option>
            }
          </select>
        </app-field>

        <app-field
          label="Why"
          class="sm:col-span-2"
          required
          hint="Whoever decides this is not in the room. Say what changed."
        >
          <textarea
            formControlName="reason"
            rows="3"
            maxlength="255"
            placeholder="The yard moved its working week, so the fortnight no longer matches the timesheets."
            class="w-full rounded-control border border-cr-line bg-cr-surface px-3 py-2 text-[14px]"
          ></textarea>
        </app-field>

        @if (failure(); as message) {
          <p role="alert" class="text-[12px] font-medium text-cr-red sm:col-span-2">
            {{ message }}
          </p>
        }

        <div class="sm:col-span-2">
          <button
            type="submit"
            class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
            [disabled]="busy() || form.invalid"
          >
            {{ busy() ? 'Sending…' : 'Send request' }}
          </button>
        </div>
      </form>
    </app-modal>
  `,
})
export class CutoffRequestCard {
  private readonly requests = inject(PayrollCutoffRequestService);
  private readonly payroll = inject(PayrollService);
  private readonly identity = inject(IdentityService);
  private readonly fb = inject(FormBuilder);

  protected readonly fmt = fmt;

  protected readonly pending = signal<PayrollCutoffRequest | null>(null);
  protected readonly formOpen = signal(false);
  protected readonly busy = signal(false);
  protected readonly notice = signal<string | null>(null);
  protected readonly failure = signal<string | null>(null);

  protected readonly canDecide = computed(() => this.identity.can('company.manage'));

  protected readonly description = signal('Loading the pay cutoff…');

  protected readonly firstDayChoices = Array.from({ length: 27 }, (_, i) => i + 1);
  protected readonly lastDayChoices = Array.from({ length: 31 }, (_, i) => i + 1);

  protected readonly form = this.fb.group({
    shape: ['twice'],
    firstDay: [15],
    lastDay: [31],
    reason: ['', [Validators.required, Validators.minLength(4), Validators.maxLength(255)]],
  });

  constructor() {
    this.load();
  }

  protected mine(request: PayrollCutoffRequest): boolean {
    return request.requested_by_name === this.identity.name();
  }

  protected dayLabel(day: number): string {
    if (day === 31) return 'The last day of the month';

    const suffix = [11, 12, 13].includes(day % 100)
      ? 'th'
      : day % 10 === 1
        ? 'st'
        : day % 10 === 2
          ? 'nd'
          : day % 10 === 3
            ? 'rd'
            : 'th';

    return `The ${day}${suffix}`;
  }

  protected openForm(): void {
    this.failure.set(null);
    this.notice.set(null);
    this.formOpen.set(true);
  }

  protected file(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }

    this.busy.set(true);
    this.failure.set(null);

    const { shape, firstDay, lastDay, reason } = this.form.getRawValue();
    const days = shape === 'once' ? [Number(lastDay)] : [Number(firstDay), Number(lastDay)];

    this.requests.file({ cutoff_days: days, reason: String(reason) }).subscribe({
      next: (request) => {
        this.busy.set(false);
        this.formOpen.set(false);
        this.pending.set(request);
        this.form.patchValue({ reason: '' });
        this.notice.set('Sent. An administrator has been notified.');
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.failure.set(this.messageFor(error));
      },
    });
  }

  protected withdraw(request: PayrollCutoffRequest): void {
    this.busy.set(true);
    this.failure.set(null);

    this.requests.withdraw(request.id).subscribe({
      next: () => {
        this.busy.set(false);
        this.pending.set(null);
        this.notice.set('Withdrawn.');
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.failure.set(this.messageFor(error));
      },
    });
  }

  private load(): void {
    this.requests.pending().subscribe({
      next: (request) => {
        this.pending.set(request);

        if (request?.current_calendar) {
          this.description.set(request.current_calendar.description);

          return;
        }

        this.loadCalendar();
      },
      error: () => {
        this.pending.set(null);
        this.loadCalendar();
      },
    });
  }

  private loadCalendar(): void {
    this.payroll.periods().subscribe({
      next: (envelope) => {
        const calendar = envelope.meta?.['calendar'] as PayrollCalendar | undefined;

        if (calendar) this.description.set(calendar.description);
      },
      error: () => this.description.set('Could not read the pay cutoff.'),
    });
  }

  private messageFor(error: HttpErrorResponse): string {
    if (error.status === 422) {
      const errors = error.error?.errors as Record<string, string[]> | undefined;

      return (
        Object.values(errors ?? {})[0]?.[0] ??
        error.error?.message ??
        'That request was not accepted.'
      );
    }

    if (error.status === 0) return 'Cannot reach the server.';
    if (error.status === 403) return 'This account cannot request a cutoff change.';

    return error.error?.message ?? `The server refused that request (${error.status}).`;
  }
}

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';

import { Company } from '../../models/identity/identity.model';
import { CompanyService } from '../../services/identity/company.service';
import { Card } from '../../shared/card';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';

@Component({
  selector: 'app-payroll-cutoff-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Icon],
  template: `
    <app-card heading="Payroll cutoff" icon="calendar" hint="When pay periods close">
      @if (company(); as row) {
        <p class="text-[14px] font-medium">{{ row.payroll_calendar.description }}</p>

        <ul class="mt-3 space-y-1">
          @for (period of row.payroll_calendar.example_periods; track period.start) {
            <li class="flex flex-wrap items-center gap-2 text-[13px] text-cr-ink-muted">
              <app-icon name="calendar" [size]="14" />
              <span class="font-medium text-cr-ink">{{ period.label }}</span>
              <span>· {{ period.days }} days</span>
              <span class="cr-num text-cr-blue">· paid {{ date(period.release_on) }}</span>
            </li>
          }
        </ul>

        @if (row.payroll_cutoff_days === null) {
          <p class="cr-meta mt-2">
            Using the system default. Choose a schedule below to set your own.
          </p>
        }

        <hr class="my-4 border-cr-line" />

        <fieldset [disabled]="busy()">
          <legend class="text-[13px] font-semibold">How often is payroll run?</legend>

          <div class="mt-2 flex flex-wrap gap-2">
            @for (option of schedules; track option.key) {
              <button
                type="button"
                class="h-9 rounded-control border px-3 text-[13px] font-semibold transition-colors disabled:opacity-60"
                [class]="
                  option.key === shape()
                    ? 'border-cr-blue bg-cr-tint text-cr-blue'
                    : 'border-cr-line text-cr-ink-muted hover:bg-cr-tint'
                "
                (click)="chooseShape(option.key)"
              >
                {{ option.label }}
              </button>
            }
          </div>

          <div class="mt-4 grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3">
            @if (shape() !== 'once') {
              <label class="block">
                <span class="cr-meta">First cutoff</span>
                <select
                  class="mt-1 h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px]"
                  [value]="firstDay()"
                  (change)="setFirst($event)"
                >
                  @for (day of firstDayChoices; track day) {
                    <option [value]="day" [selected]="day === firstDay()">
                      {{ dayLabel(day) }}
                    </option>
                  }
                </select>
              </label>
            }

            @if (shape() === 'thrice') {
              <label class="block">
                <span class="cr-meta">Second cutoff</span>
                <select
                  class="mt-1 h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px]"
                  [value]="middleDay()"
                  (change)="setMiddle($event)"
                >
                  @for (day of firstDayChoices; track day) {
                    <option [value]="day" [selected]="day === middleDay()">
                      {{ dayLabel(day) }}
                    </option>
                  }
                </select>
              </label>
            }

            <label class="block">
              <span class="cr-meta">{{ lastCutoffLabel() }}</span>
              <select
                class="mt-1 h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px]"
                [value]="lastDay()"
                (change)="setLast($event)"
              >
                @for (day of lastDayChoices; track day) {
                  <option [value]="day" [selected]="day === lastDay()">{{ dayLabel(day) }}</option>
                }
              </select>
            </label>
          </div>

          @if (shape() !== 'once') {
            <p class="cr-meta mt-2">
              Every cutoff but the last stops at the 27th, so each still leaves a period after it in
              February.
            </p>
          }

          <label class="mt-4 block max-w-[340px]">
            <span class="cr-meta">Released this many days after each cutoff</span>
            <input
              type="number"
              min="0"
              max="14"
              class="mt-1 h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px]"
              [value]="lag()"
              (change)="setLag($event)"
            />
            <span class="cr-meta mt-1 block">
              Nought pays on the cutoff itself. Calendar days, not working days.
            </span>
          </label>
        </fieldset>

        @if (row.payroll_calendar.runs_per_month > 2) {
          <p
            class="mt-4 rounded-control bg-cr-warning-bg px-3 py-2 text-[12px] text-cr-ink"
            role="status"
          >
            <span class="font-semibold">Withholding tax is worked out monthly for three runs.</span>
            The BIR has no table for three payslips a month, so each run's pay is taken to a month,
            taxed on the monthly table and divided by three. The year-end adjustment settles any
            centavos.
          </p>
        }

        @if (failure(); as message) {
          <p role="alert" class="mt-3 text-[12px] font-medium text-cr-red">{{ message }}</p>
        }

        @if (saved()) {
          <p class="mt-3 text-[12px] font-medium text-cr-green">Saved.</p>
        }

        <div class="mt-4 flex items-center gap-2">
          <button
            type="button"
            class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
            [disabled]="busy() || !dirty()"
            (click)="save()"
          >
            {{ busy() ? 'Saving…' : 'Save schedule' }}
          </button>

          @if (row.payroll_cutoff_days !== null) {
            <button
              type="button"
              class="h-10 rounded-control border border-cr-line px-4 text-[14px] font-semibold text-cr-ink-muted transition-colors hover:bg-cr-tint disabled:opacity-60"
              [disabled]="busy()"
              (click)="reset()"
            >
              Use the default
            </button>
          }
        </div>
      } @else {
        <p class="cr-meta">Loading the payroll schedule…</p>
      }
    </app-card>
  `,
})
export class PayrollCutoffCard {
  private readonly companyApi = inject(CompanyService);

  protected readonly company = signal<Company | null>(null);

  protected readonly busy = signal(false);
  protected readonly saved = signal(false);
  protected readonly failure = signal<string | null>(null);

  protected readonly shape = signal<'once' | 'twice' | 'thrice'>('twice');
  protected readonly firstDay = signal(5);
  protected readonly middleDay = signal(15);
  protected readonly lastDay = signal(31);

  protected readonly lag = signal(2);

  protected readonly schedules = [
    { key: 'once' as const, label: 'Once a month' },
    { key: 'twice' as const, label: 'Twice a month' },
    { key: 'thrice' as const, label: 'Three times a month' },
  ];

  protected readonly firstDayChoices = Array.from({ length: 27 }, (_, i) => i + 1);
  protected readonly lastDayChoices = Array.from({ length: 31 }, (_, i) => i + 1);

  protected readonly dirty = computed(() => {
    const row = this.company();

    if (row === null) return false;

    const current = row.payroll_cutoff_days ?? row.payroll_calendar.cutoff_days;

    return (
      current.join(',') !== this.days().join(',') ||
      row.payroll_calendar.release_lag_days !== this.lag()
    );
  });

  protected readonly lastCutoffLabel = computed(() => {
    if (this.shape() === 'once') return 'Cutoff';

    return this.shape() === 'twice' ? 'Second cutoff' : 'Third cutoff';
  });

  protected readonly date = (value: string): string => fmt.date(value);

  constructor() {
    this.load();
  }

  protected chooseShape(shape: 'once' | 'twice' | 'thrice'): void {
    this.shape.set(shape);
  }

  protected setFirst(event: Event): void {
    this.firstDay.set(Number((event.target as HTMLSelectElement).value));
  }

  protected setLast(event: Event): void {
    this.lastDay.set(Number((event.target as HTMLSelectElement).value));
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

  protected save(): void {
    this.busy.set(true);
    this.failure.set(null);
    this.saved.set(false);

    this.companyApi
      .updateProfile({
        payroll_cutoff_days: this.days(),
        payroll_release_lag_days: this.lag(),
      })
      .subscribe({
        next: (company) => {
          this.adopt(company);
          this.busy.set(false);
          this.saved.set(true);
        },
        error: (error: HttpErrorResponse) => {
          this.busy.set(false);
          this.failure.set(this.messageFor(error));
        },
      });
  }

  protected reset(): void {
    this.busy.set(true);
    this.failure.set(null);
    this.saved.set(false);

    this.companyApi.updateProfile({ payroll_cutoff_days: null }).subscribe({
      next: (company) => {
        this.adopt(company);
        this.busy.set(false);
        this.saved.set(true);
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.failure.set(this.messageFor(error));
      },
    });
  }

  private days(): number[] {
    if (this.shape() === 'once') return [this.lastDay()];
    if (this.shape() === 'twice') return [this.firstDay(), this.lastDay()];

    return [this.firstDay(), this.middleDay(), this.lastDay()];
  }

  protected setMiddle(event: Event): void {
    this.middleDay.set(Number((event.target as HTMLSelectElement).value));
  }

  protected setLag(event: Event): void {
    const days = Number((event.target as HTMLInputElement).value);

    this.lag.set(Number.isFinite(days) ? Math.min(14, Math.max(0, Math.round(days))) : 0);
  }

  private load(): void {
    this.companyApi.show().subscribe({
      next: (company) => this.adopt(company),
      error: () => this.failure.set('Could not load the payroll schedule.'),
    });
  }

  private adopt(company: Company): void {
    this.company.set(company);

    const days = company.payroll_cutoff_days ?? company.payroll_calendar.cutoff_days;

    this.shape.set(days.length === 1 ? 'once' : days.length === 2 ? 'twice' : 'thrice');

    this.firstDay.set(days.length === 1 ? 5 : days[0]);
    this.middleDay.set(days.length === 3 ? days[1] : 15);
    this.lastDay.set(days[days.length - 1]);

    this.lag.set(company.payroll_calendar.release_lag_days);
  }

  private messageFor(error: HttpErrorResponse): string {
    if (error.status === 422) {
      const errors = error.error?.errors as Record<string, string[]> | undefined;

      return (
        errors?.['payroll_cutoff_days']?.[0] ??
        errors?.['payroll_deduct_on']?.[0] ??
        error.error?.message ??
        'That schedule was not accepted.'
      );
    }

    if (error.status === 0) return 'Cannot reach the server.';
    if (error.status === 403) return 'This account cannot change the payroll schedule.';

    return error.error?.message ?? `The server refused that request (${error.status}).`;
  }
}

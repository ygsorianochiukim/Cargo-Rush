import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';

import { Company } from '../../models/identity/identity.model';
import { CompanyService } from '../../services/identity/company.service';
import { Card } from '../../shared/card';
import { Icon } from '../../shared/icon';

/**
 * When this firm's pay periods close — and which payslip the contributions
 * come off.
 *
 * The two payroll settings that are the **firm's own policy** rather than the
 * government's, and the reason this card exists at all. Until now the cutoff
 * was `PAYROLL_RUNS_PER_MONTH`, an environment variable serving every haulier
 * on the install: a firm closing on the 10th and the 25th could not be
 * described, and moving one firm to a monthly payroll moved all of them. Both
 * are columns on the company now, which is what makes this a form instead of a
 * support ticket and a deployment.
 *
 * It sits on Access Control beside the company card for the same reason that
 * one does: this is already the administrator's screen, `company.manage` is
 * already the permission here, and a page holding one card is a menu item
 * somebody has to find.
 *
 * ## What it does not decide
 *
 * The SSS, PhilHealth and Pag-IBIG **rates**. Those are the government's, the
 * same for every firm on the platform, and they live in configuration — see
 * `config/cargo.php`. The line this card draws is exactly that one: a rate is
 * found out, a policy is chosen, and only the second belongs on a form.
 *
 * ## Why the periods are shown rather than the days
 *
 * "The 10th and the 25th" is not something an office can picture; "26 Aug–10
 * Sep, then 11–25 Sep" is. The worked example comes from the API with the
 * setting, so this screen never does calendar arithmetic of its own — which is
 * the bug the whole change exists to remove, and it would be a poor joke to
 * reintroduce it on the screen that fixes it.
 */
@Component({
  selector: 'app-payroll-cutoff-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Icon],
  template: `
    <app-card heading="Payroll cutoff" icon="calendar" hint="When pay periods close">
      @if (company(); as row) {
        <p class="text-[14px] font-medium">{{ row.payroll_calendar.description }}</p>

        <!--
          What those days actually come to. The office checks the setting
          against this rather than against the numbers, because this is the
          thing they recognise from their own paperwork.
        -->
        <ul class="mt-3 space-y-1">
          @for (period of row.payroll_calendar.example_periods; track period.start) {
            <li class="flex items-center gap-2 text-[13px] text-cr-ink-muted">
              <app-icon name="calendar" [size]="14" />
              <span class="font-medium text-cr-ink">{{ period.label }}</span>
              <span>· {{ period.days }} days</span>
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

          <!--
            The day pickers. Two when payroll runs twice, one when it runs
            once — the second cutoff is not "disabled" in the monthly case,
            it does not exist, and a greyed-out control would suggest it did.
          -->
          <div class="mt-4 grid gap-3 sm:grid-cols-2">
            @if (shape() === 'twice') {
              <label class="block">
                <span class="cr-meta">First cutoff</span>
                <select
                  class="mt-1 h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px]"
                  [value]="firstDay()"
                  (change)="setFirst($event)"
                >
                  @for (day of firstDayChoices; track day) {
                    <option [value]="day">{{ dayLabel(day) }}</option>
                  }
                </select>
              </label>
            }

            <label class="block">
              <span class="cr-meta">{{ shape() === 'twice' ? 'Second cutoff' : 'Cutoff' }}</span>
              <select
                class="mt-1 h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px]"
                [value]="lastDay()"
                (change)="setLast($event)"
              >
                @for (day of lastDayChoices; track day) {
                  <option [value]="day">{{ dayLabel(day) }}</option>
                }
              </select>
            </label>
          </div>

          <!--
            The earlier cutoff stops at the 27th, and saying why beats letting
            somebody find out from a 422. February is the reason: a cutoff
            clamps to the last day of a short month, so a first cutoff any
            later would collide with the second and leave the month one period.
          -->
          @if (shape() === 'twice') {
            <p class="cr-meta mt-2">
              The first cutoff stops at the 27th so it still leaves a second period in February.
            </p>
          }
        </fieldset>

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

  /** The last thing the server said. Everything on screen starts from it. */
  protected readonly company = signal<Company | null>(null);

  protected readonly busy = signal(false);
  protected readonly saved = signal(false);
  protected readonly failure = signal<string | null>(null);

  /** One cutoff a month or two. The days follow from the answer. */
  protected readonly shape = signal<'once' | 'twice'>('twice');
  protected readonly firstDay = signal(15);
  protected readonly lastDay = signal(31);

  protected readonly schedules = [
    { key: 'twice' as const, label: 'Twice a month' },
    { key: 'once' as const, label: 'Once a month' },
  ];

  /**
   * The earlier cutoff stops at the 27th. See the note in the template — it is
   * February, and the API refuses anything later for the same reason.
   */
  protected readonly firstDayChoices = Array.from({ length: 27 }, (_, i) => i + 1);
  protected readonly lastDayChoices = Array.from({ length: 31 }, (_, i) => i + 1);

  /** Nothing to save until something differs from what the server holds. */
  protected readonly dirty = computed(() => {
    const row = this.company();

    if (row === null) return false;

    const current = row.payroll_cutoff_days ?? row.payroll_calendar.cutoff_days;

    return current.join(',') !== this.days().join(',');
  });

  constructor() {
    this.load();
  }

  protected chooseShape(shape: 'once' | 'twice'): void {
    this.shape.set(shape);
  }

  protected setFirst(event: Event): void {
    this.firstDay.set(Number((event.target as HTMLSelectElement).value));
  }

  protected setLast(event: Event): void {
    this.lastDay.set(Number((event.target as HTMLSelectElement).value));
  }

  /**
   * `the 15th`, and `the last day of the month` for the 31st.
   *
   * The 31st is how a firm says "the end of the month", and reading it back as
   * "the 31st" to an office whose September has thirty days would be the one
   * number on this screen that was wrong.
   */
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

  /** Back to the install default, which is what a null column means. */
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
    return this.shape() === 'once' ? [this.lastDay()] : [this.firstDay(), this.lastDay()];
  }

  private load(): void {
    this.companyApi.show().subscribe({
      next: (company) => this.adopt(company),
      error: () => this.failure.set('Could not load the payroll schedule.'),
    });
  }

  /**
   * Redraw the controls from what the server actually stored.
   *
   * Not from what was sent: the API sorts the days and may have been given them
   * the other way round, and a form that kept its own version of the answer is
   * the one that shows a saved setting the server does not have.
   */
  private adopt(company: Company): void {
    this.company.set(company);

    const days = company.payroll_cutoff_days ?? company.payroll_calendar.cutoff_days;

    this.shape.set(days.length === 1 ? 'once' : 'twice');
    this.firstDay.set(days.length === 1 ? 15 : days[0]);
    this.lastDay.set(days[days.length - 1]);
  }

  /**
   * The server's own words for a 422.
   *
   * It knows things this screen does not — chiefly whether a draft pay run is
   * open, which blocks the change and names the run in the message. Restating
   * any of that here would be two rules to keep in step, and the interesting
   * half of this one is not a rule about numbers at all.
   */
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

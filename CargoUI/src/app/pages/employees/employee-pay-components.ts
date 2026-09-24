import {
  ChangeDetectionStrategy,
  Component,
  effect,
  inject,
  input,
  model,
  signal,
} from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import { Employee } from '../../models/hr/hr.model';
import { EmployeePayComponent, PayComponent } from '../../models/hr/payroll.model';
import { PayComponentService } from '../../services/hr/payroll.service';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { Modal } from '../../shared/modal';

/**
 * What one person is paid on top of their basic — and what comes off it.
 *
 * The screen that makes the salary structure worth having. A component in the
 * catalogue pays nobody until somebody is put on it here, and this is where an
 * office does that: once, with a start date, instead of typing the same rice
 * allowance onto the same payslip every fortnight until somebody leaves.
 *
 * ## The two things it is careful about
 *
 * **The amount is usually the catalogue's.** The override field is left blank
 * for almost everybody, and that blank is what keeps a firm-wide raise to one
 * edited row rather than ninety. So an empty box means "whatever the component
 * says" and is sent as null — never as zero, which would pin this person to
 * nothing and look identical on screen.
 *
 * **The end date is how a loan stops.** An assignment with no end runs forever,
 * which is right for an allowance and wrong for a repayment. Setting the date
 * when the loan is granted is the difference between it stopping on the right
 * payslip and stopping when somebody remembers.
 *
 * It is a modal off the roster rather than a page of its own: the question is
 * always about one person, and it is asked while looking at them.
 */
@Component({
  selector: 'app-employee-pay-components',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Field, Icon, Modal, ReactiveFormsModule],
  template: `
    <app-modal
      [(open)]="open"
      [title]="employee().full_name"
      subtitle="Allowances and deductions"
      icon="wallet"
      size="lg"
      [locked]="busy()"
    >
      @if (failure(); as message) {
        <p
          role="alert"
          class="mb-4 rounded-control bg-cr-red-bg px-3 py-2 text-[13px] font-medium text-cr-red"
        >
          {{ message }}
        </p>
      }

      <!-- What they are on now. The list first, because somebody opening this
           is usually checking rather than adding. -->
      @if (rows(); as assignments) {
        @if (assignments.length === 0) {
          <div class="rounded-control border border-dashed border-cr-line px-4 py-6 text-center">
            <app-icon name="wallet" [size]="28" class="text-cr-ink-muted" />
            <p class="mt-2 text-[14px] font-semibold">Nothing on top of the basic</p>
            <p class="cr-meta mt-1">
              Payroll pays this person their salary and the statutory deductions, and nothing else.
            </p>
          </div>
        } @else {
          <ul class="space-y-2">
            @for (row of assignments; track row.id) {
              <li class="rounded-control border border-cr-line p-3">
                <div class="flex items-start gap-3">
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-[14px] font-semibold">
                      {{ row.component?.name ?? 'Removed component' }}
                      <span
                        class="ml-1 text-[12px] font-medium"
                        [class]="
                          row.component?.kind === 'deduction' ? 'text-cr-red' : 'text-cr-green'
                        "
                      >
                        {{ row.component?.sign }}{{ amountOf(row) }}
                      </span>
                    </p>
                    <p class="cr-meta mt-0.5 truncate">
                      {{ row.component?.schedule_label }}
                      @if (row.overrides_amount) {
                        · set for this person
                      }
                    </p>
                    <p class="cr-num mt-0.5 text-[12px] text-cr-ink-muted">
                      From {{ fmt.date(row.effective_from) }}
                      @if (row.effective_to) {
                        until {{ fmt.date(row.effective_to) }}
                      } @else {
                        · ongoing
                      }
                    </p>
                  </div>

                  @if (row.status !== 'active') {
                    <span class="cr-meta flex-none">Paused</span>
                  }

                  <button
                    type="button"
                    [attr.aria-label]="'Remove ' + (row.component?.name ?? 'component')"
                    class="h-8 w-8 flex-none rounded-control text-cr-ink-muted transition-colors hover:bg-cr-red-bg hover:text-cr-red disabled:opacity-60"
                    [disabled]="busy()"
                    (click)="unassign(row)"
                  >
                    <app-icon name="close" [size]="16" class="mx-auto" />
                  </button>
                </div>
              </li>
            }
          </ul>
        }
      } @else {
        <div class="cr-skeleton h-20 w-full"></div>
      }

      <hr class="my-4 border-cr-line" />

      <form [formGroup]="form" class="grid gap-3 sm:grid-cols-2" (ngSubmit)="assign()">
        <app-field label="Component" class="sm:col-span-2" required>
          <select
            formControlName="pay_component_id"
            class="h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px]"
          >
            <option value="">Choose one…</option>
            @for (component of catalogue(); track component.id) {
              <option [value]="component.id">
                {{ component.name }} — {{ component.schedule_label }}
              </option>
            }
          </select>
        </app-field>

        <app-field label="Starts" required>
          <input
            type="date"
            formControlName="effective_from"
            class="h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px]"
          />
        </app-field>

        <app-field label="Ends" hint="Leave blank to run until somebody stops it.">
          <input
            type="date"
            formControlName="effective_to"
            class="h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px]"
          />
        </app-field>

        <app-field
          label="Amount for this person"
          class="sm:col-span-2"
          hint="Leave blank to use the component’s own figure — which is what a firm-wide change then reaches."
        >
          <input
            type="number"
            min="0"
            step="0.01"
            placeholder="Use the component’s amount"
            formControlName="amount"
            class="h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px]"
          />
        </app-field>

        <div class="sm:col-span-2">
          <button
            type="submit"
            class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
            [disabled]="busy() || form.invalid"
          >
            {{ busy() ? 'Saving…' : 'Add to this person' }}
          </button>
        </div>
      </form>
    </app-modal>
  `,
})
export class EmployeePayComponents {
  private readonly components = inject(PayComponentService);
  private readonly fb = inject(FormBuilder);

  readonly employee = input.required<Employee>();
  readonly open = model(false);

  protected readonly fmt = fmt;

  protected readonly rows = signal<EmployeePayComponent[] | null>(null);
  protected readonly catalogue = signal<PayComponent[]>([]);
  protected readonly busy = signal(false);
  protected readonly failure = signal<string | null>(null);

  protected readonly form = this.fb.group({
    pay_component_id: ['', Validators.required],
    effective_from: [new Date().toISOString().slice(0, 10), Validators.required],
    effective_to: [''],
    amount: [''],
  });

  constructor() {
    /**
     * Load when it opens, not when it is constructed.
     *
     * The component sits inside the roster and is built with the page; fetching
     * then would be one request per employee on a screen showing ninety, to
     * answer a question nobody has asked yet.
     */
    effect(() => {
      if (!this.open()) return;

      this.load();
    });
  }

  /**
   * What this row is worth, in the unit its basis uses.
   *
   * A percentage shows `10%` rather than a peso figure — there is no peso
   * answer without running payroll, and ₱0.00 would read as an allowance that
   * pays nothing.
   */
  protected amountOf(row: EmployeePayComponent): string {
    if (row.effective_rate_bp !== null) {
      const percent = row.effective_rate_bp / 100;

      return `${percent.toFixed(percent % 1 === 0 ? 0 : 2)}% of basic`;
    }

    return fmt.money(row.effective_amount_cents ?? 0, row.currency);
  }

  protected assign(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }

    this.busy.set(true);
    this.failure.set(null);

    const { pay_component_id, effective_from, effective_to, amount } = this.form.getRawValue();

    this.components
      .assign({
        employee_id: this.employee().id,
        pay_component_id: String(pay_component_id),
        effective_from: String(effective_from),
        effective_to: effective_to === '' ? null : String(effective_to),
        /**
         * Blank means "the component's own figure", and is sent as null.
         *
         * Not as zero: null is what keeps this person following a firm-wide
         * change, and zero would pin them to nothing while looking identical
         * on this screen.
         */
        amount_cents: amount === '' || amount === null ? null : Math.round(Number(amount) * 100),
      })
      .subscribe({
        next: () => {
          this.busy.set(false);
          this.form.patchValue({ pay_component_id: '', effective_to: '', amount: '' });
          this.load();
        },
        error: (error: HttpErrorResponse) => {
          this.busy.set(false);
          this.failure.set(this.messageFor(error));
        },
      });
  }

  protected unassign(row: EmployeePayComponent): void {
    this.busy.set(true);
    this.failure.set(null);

    this.components.unassign(row.id).subscribe({
      next: () => {
        this.busy.set(false);
        this.rows.set((this.rows() ?? []).filter((r) => r.id !== row.id));
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.failure.set(this.messageFor(error));
      },
    });
  }

  private load(): void {
    this.components.assignmentsFor(this.employee().id).subscribe({
      next: (res) => this.rows.set(res.data),
      error: () => this.failure.set('Could not read what this person is paid.'),
    });

    // Active only: the picker offers what payroll will actually use next time,
    // and a retired component is deliberately not that.
    this.components.list(true).subscribe({
      next: (res) => this.catalogue.set(res.data),
      error: () => this.catalogue.set([]),
    });
  }

  private messageFor(error: HttpErrorResponse): string {
    if (error.status === 422) {
      const errors = error.error?.errors as Record<string, string[]> | undefined;

      return Object.values(errors ?? {})[0]?.[0] ?? 'Check the details and try again.';
    }

    if (error.status === 0) return 'Cannot reach the server.';
    if (error.status === 403) return 'This account cannot change what somebody is paid.';

    return error.error?.message ?? `The server refused that request (${error.status}).`;
  }
}

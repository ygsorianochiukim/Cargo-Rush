import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  model,
  signal,
} from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import { Employee } from '../../models/hr/hr.model';
import { StoreCredit, StoreCreditState } from '../../models/hr/payroll.model';
import { StoreCreditService } from '../../services/hr/payroll.service';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { Modal } from '../../shared/modal';

/**
 * The store tab — the mini-mart *pautang*, for one person.
 *
 * Goods taken against pay, and what payroll takes back at the cutoff. The
 * balance is the difference between the two kinds of row, and the next payslip
 * takes it — capped, where the person has a cap on their record.
 *
 * ## Why this is not a pay component
 *
 * The salary structure beside it already expresses a standing deduction, and a
 * cash-advance repayment is exactly what it is for. It is the wrong shape for a
 * tab. A component is an instruction with an amount and a date range, ended by
 * somebody remembering to end it; a tab has no amount until the cutoff, because
 * it changes every time the storekeeper writes a line.
 *
 * ## The date is the field that matters
 *
 * A tab is written up at the till and keyed later, and a cutoff is a pair of
 * dates: a charge entered on the 16th for something taken on the 14th belongs
 * on the first payslip. So the form asks when it happened rather than assuming
 * today, and defaults to today because that is right most of the time.
 *
 * A modal off the roster rather than a page of its own, like the allowances
 * beside it: the question is always about one person and is asked while
 * looking at them.
 */
@Component({
  selector: 'app-employee-store-credits',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Field, Icon, Modal, ReactiveFormsModule],
  template: `
    <app-modal
      [(open)]="open"
      [title]="employee().full_name"
      subtitle="Store tab — mini-mart pautang"
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

      <!--
        The balance first, because it is the only figure anybody opens this for,
        and beside it what the next payslip will actually take — which is not
        the same number the moment a cap is set, and is exactly the sort of
        thing an office should not have to reason out.
      -->
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <div class="rounded-control border border-cr-line px-3 py-2.5">
          <p class="cr-meta">Outstanding</p>
          <p class="cr-num mt-0.5 text-[20px] leading-none font-semibold">
            {{ fmt.money(state()?.balance_cents ?? 0) }}
          </p>
        </div>
        <div class="rounded-control border border-cr-line px-3 py-2.5">
          <p class="cr-meta">Next payslip takes</p>
          <p class="cr-num mt-0.5 text-[20px] leading-none font-semibold">
            {{ fmt.money(state()?.next_deduction_cents ?? 0) }}
          </p>
        </div>
        <div class="rounded-control border border-cr-line px-3 py-2.5">
          <p class="cr-meta">Cap per payslip</p>
          <p class="cr-num mt-0.5 text-[20px] leading-none font-semibold">
            {{ (state()?.cap_cents ?? 0) > 0 ? fmt.money(state()!.cap_cents) : 'No cap' }}
          </p>
        </div>
      </div>

      @if ((state()?.cap_cents ?? 0) === 0 && (state()?.balance_cents ?? 0) > 0) {
        <p class="mt-2 text-[12px] text-cr-ink-muted">
          No cap set, so the next payslip takes the whole balance — or as much of it as the payslip
          can bear. Set a cap on this person’s record to spread it.
        </p>
      }

      <!-- Adding a line. Deliberately four fields: a tab entered at a till is
           a date, a figure and a couple of words, and a longer form is one
           that stays in the notebook. -->
      <form [formGroup]="form" class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-5">
        <app-field label="Date" class="sm:col-span-1">
          <input type="date" formControlName="charged_on" [class]="inputClass" />
        </app-field>
        <app-field label="What was taken" class="sm:col-span-2">
          <input
            type="text"
            formControlName="description"
            placeholder="3 kg rice, 2 tins"
            [class]="inputClass"
          />
        </app-field>
        <app-field label="Amount (₱)" class="sm:col-span-1">
          <input
            type="number"
            step="0.01"
            min="0.01"
            formControlName="amount"
            [class]="inputClass + ' cr-num'"
          />
        </app-field>
        <app-field label="Kind" class="sm:col-span-1">
          <select formControlName="kind" [class]="inputClass">
            <option value="charge">Charge</option>
            <option value="payment">Payment</option>
          </select>
        </app-field>
      </form>

      <button
        type="button"
        class="mt-3 h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
        [disabled]="busy() || form.invalid"
        (click)="add()"
      >
        {{ busy() ? 'Saving…' : 'Add to the tab' }}
      </button>

      <div class="mt-5 border-t border-cr-line pt-4">
        @if (rows(); as list) {
          @if (list.length === 0) {
            <p class="py-6 text-center text-[13px] text-cr-ink-muted">Nothing on this tab yet.</p>
          } @else {
            <table class="w-full border-collapse text-left text-[13px]">
              <thead>
                <tr class="border-b border-cr-line">
                  <th class="py-2 pr-3 font-semibold">Date</th>
                  <th class="py-2 pr-3 font-semibold">What</th>
                  <th class="py-2 pr-3 text-right font-semibold">Amount</th>
                  <th class="py-2"></th>
                </tr>
              </thead>
              <tbody>
                @for (row of list; track row.id) {
                  <tr class="border-b border-cr-line/60">
                    <td class="py-2 pr-3 whitespace-nowrap">{{ fmt.date(row.charged_on) }}</td>
                    <td class="py-2 pr-3">
                      {{ row.description ?? row.kind_label }}
                      @if (row.outlet) {
                        <span class="text-cr-ink-muted"> · {{ row.outlet }}</span>
                      }
                      @if (row.from_payroll) {
                        <span class="text-cr-ink-muted"> · off a payslip</span>
                      }
                    </td>
                    <td
                      class="cr-num py-2 pr-3 text-right whitespace-nowrap"
                      [class.text-cr-success]="row.kind === 'payment'"
                    >
                      {{ row.kind === 'payment' ? '−' : '+' }}{{ fmt.money(row.amount_cents) }}
                    </td>
                    <td class="py-2 text-right">
                      <!--
                        A repayment payroll wrote is part of a payslip somebody
                        has been handed, so there is nothing to offer here — the
                        API refuses it, and a button that produced a 422 would
                        be worse than none.
                      -->
                      @if (!row.from_payroll) {
                        <button
                          type="button"
                          aria-label="Remove line"
                          class="h-8 w-8 rounded-control text-cr-ink-muted transition-colors hover:bg-cr-red-bg hover:text-cr-red"
                          (click)="remove(row)"
                        >
                          <app-icon name="close" [size]="14" class="mx-auto" />
                        </button>
                      }
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          }
        } @else {
          <div class="cr-skeleton h-16 w-full"></div>
        }
      </div>
    </app-modal>
  `,
})
export class EmployeeStoreCredits {
  private readonly store = inject(StoreCreditService);
  private readonly fb = inject(FormBuilder);

  readonly employee = input.required<Employee>();
  readonly open = model(false);

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly state = signal<StoreCreditState | null>(null);
  protected readonly rows = computed(() => this.state()?.rows ?? null);
  protected readonly busy = signal(false);
  protected readonly failure = signal<string | null>(null);

  protected readonly form = this.fb.group({
    charged_on: [new Date().toISOString().slice(0, 10), Validators.required],
    description: [''],
    amount: [0, [Validators.required, Validators.min(0.01)]],
    kind: ['charge'],
  });

  constructor() {
    effect(() => {
      if (this.open() && this.employee()) {
        this.failure.set(null);
        this.load();
      }
    });
  }

  protected add(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }

    this.busy.set(true);
    this.failure.set(null);

    const { charged_on, description, amount, kind } = this.form.getRawValue();

    this.store
      .add(this.employee().id, {
        kind: kind as 'charge' | 'payment',
        amount_cents: Math.round(Number(amount ?? 0) * 100),
        description: description || null,
        charged_on: charged_on ?? undefined,
      })
      .subscribe({
        next: () => {
          this.busy.set(false);
          // Reloaded rather than pushed onto the list: the balance and the
          // next deduction both move, and recomputing them here would be a
          // second implementation of the cap.
          this.form.patchValue({ description: '', amount: 0 });
          this.load();
        },
        error: (error: HttpErrorResponse) => {
          this.busy.set(false);
          this.failure.set(this.messageFor(error));
        },
      });
  }

  protected remove(row: StoreCredit): void {
    this.busy.set(true);
    this.failure.set(null);

    this.store.remove(row.id).subscribe({
      next: () => {
        this.busy.set(false);
        this.load();
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.failure.set(this.messageFor(error));
      },
    });
  }

  private load(): void {
    this.store.forEmployee(this.employee().id).subscribe({
      next: (state) => this.state.set(state),
      error: () => this.failure.set('Could not read this person’s tab.'),
    });
  }

  private messageFor(error: HttpErrorResponse): string {
    if (error.status === 422) {
      const errors = error.error?.errors as Record<string, string[]> | undefined;

      return (
        Object.values(errors ?? {})[0]?.[0] ??
        error.error?.message ??
        'Check the details and try again.'
      );
    }

    if (error.status === 0) return 'Cannot reach the server.';
    if (error.status === 403) return 'This account cannot change what somebody is paid.';

    return error.error?.message ?? `The server refused that request (${error.status}).`;
  }
}

import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import { TripCosts } from '../models/trip/trip.model';
import { PaymentSourceService } from '../services/accounting/payment-source.service';
import { ExpenseService } from '../services/expense/expense.service';
import { IdentityService } from '../services/identity/identity.service';
import { TripService } from '../services/trip/trip.service';
import { fmt } from './format';

function today(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

@Component({
  selector: 'app-trip-costs',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule],
  template: `
    <div class="rounded-control border border-cr-line px-3 py-3">
      <button
        type="button"
        class="flex w-full items-center justify-between text-left text-[13px] font-semibold"
        [attr.aria-expanded]="shown()"
        (click)="toggle()"
      >
        <span>
          Costs &amp; net
          @if (costs(); as c) {
            <span class="cr-num ml-2 font-normal" [class.text-cr-red]="c.net_cents < 0">
              {{ fmt.money(c.net_cents, c.currency) }}
            </span>
          }
        </span>
        <span class="text-[12px] font-normal text-cr-blue">{{ shown() ? 'Hide' : 'Show' }}</span>
      </button>

      @if (shown()) {
        @if (loading()) {
          <p class="mt-2 text-[12px] text-cr-ink-muted">Loading…</p>
        } @else if (failure(); as message) {
          <p class="mt-2 text-[12px] text-cr-red" role="alert">{{ message }}</p>
        } @else if (costs(); as c) {
          <dl class="mt-3 flex flex-col gap-1 text-[13px]">
            <div class="flex justify-between font-semibold">
              <dt>{{ c.income_label }}</dt>
              <dd class="cr-num">{{ fmt.money(c.income_cents, c.currency) }}</dd>
            </div>

            @for (line of c.lines; track line.key) {
              <div class="flex justify-between gap-3">
                <dt class="min-w-0 truncate text-cr-ink-muted">
                  {{ line.kind }}{{ line.description ? ' · ' + line.description : '' }}{{
                    line.paid_with ? ' · ' + line.paid_with : ''
                  }}
                </dt>
                <dd class="cr-num flex-none">− {{ fmt.money(line.amount_cents, c.currency) }}</dd>
              </div>
            } @empty {
              <p class="text-[12px] text-cr-ink-muted">No costs on this trip yet.</p>
            }

            <div class="mt-1 flex justify-between border-t border-cr-line pt-1.5">
              <dt class="text-cr-ink-muted">Total costs</dt>
              <dd class="cr-num">{{ fmt.money(c.costs_cents, c.currency) }}</dd>
            </div>
            <div class="flex justify-between text-[14px] font-semibold">
              <dt>Net</dt>
              <dd class="cr-num" [class.text-cr-red]="c.net_cents < 0" [class.text-cr-success]="c.net_cents > 0">
                {{ fmt.money(c.net_cents, c.currency) }}
              </dd>
            </div>
          </dl>

          @if (!c.final) {
            <p class="mt-2 text-[12px] text-cr-ink-muted">
              Not delivered yet — these are the figures so far.
            </p>
          }

          @if (canAdd) {
            <form
              [formGroup]="form"
              class="mt-3 grid grid-cols-2 gap-2 border-t border-cr-line pt-3 sm:grid-cols-4"
            >
              <select formControlName="category_id" aria-label="Category" [class]="inputClass">
                <option value="">Category…</option>
                @for (o of categories(); track o.value) {
                  <option [value]="o.value">{{ o.label }}</option>
                }
              </select>
              <input
                type="number"
                min="0"
                step="0.01"
                formControlName="amount"
                placeholder="Amount (₱)"
                aria-label="Amount"
                [class]="inputClass + ' cr-num'"
              />
              <select formControlName="payment_source_id" aria-label="Paid with" [class]="inputClass">
                <option value="">Paid with…</option>
                @for (o of wallets(); track o.value) {
                  <option [value]="o.value">{{ o.label }}</option>
                }
              </select>
              <button
                type="button"
                class="h-9 rounded-control bg-cr-blue px-3 text-[13px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
                [disabled]="saving()"
                (click)="add()"
              >
                {{ saving() ? 'Adding…' : 'Add expense' }}
              </button>
              <input
                type="text"
                formControlName="note"
                placeholder="Note (optional)"
                aria-label="Note"
                class="col-span-2 sm:col-span-4"
                [class]="inputClass"
              />
            </form>
            @if (addFailure(); as message) {
              <p class="mt-2 text-[12px] text-cr-red" role="alert">{{ message }}</p>
            }
          }
        }
      }
    </div>
  `,
})
export class TripCostsPanel {
  private readonly trips = inject(TripService);
  private readonly expenses = inject(ExpenseService);
  private readonly sources = inject(PaymentSourceService);
  private readonly identity = inject(IdentityService);
  private readonly fb = inject(FormBuilder);

  readonly tripId = input.required<string>();

  protected readonly fmt = fmt;
  protected readonly canAdd = this.identity.can('expenses.manage');

  protected readonly inputClass =
    'h-9 w-full rounded-control border border-cr-line bg-cr-surface px-2 text-[13px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly shown = signal(false);
  protected readonly loading = signal(false);
  protected readonly saving = signal(false);
  protected readonly failure = signal<string | null>(null);
  protected readonly addFailure = signal<string | null>(null);
  protected readonly costs = signal<TripCosts | null>(null);
  protected readonly categories = signal<{ value: string; label: string }[]>([]);
  protected readonly wallets = signal<{ value: string; label: string }[]>([]);

  protected readonly form = this.fb.nonNullable.group({
    category_id: ['', Validators.required],
    amount: ['', [Validators.required, Validators.min(0.01)]],
    payment_source_id: [''],
    note: [''],
  });

  private optionsLoaded = false;

  protected toggle(): void {
    this.shown.update((s) => !s);

    if (!this.shown()) return;

    this.load();

    if (this.canAdd && !this.optionsLoaded) {
      this.optionsLoaded = true;
      this.expenses.categories(true).subscribe((rows) =>
        this.categories.set(rows.map((c) => ({ value: c.id, label: c.name }))),
      );
      this.sources.options().subscribe((rows) => this.wallets.set(rows));
    }
  }

  private load(): void {
    this.loading.set(true);
    this.failure.set(null);

    this.trips.costs(this.tripId()).subscribe({
      next: (costs) => {
        this.costs.set(costs);
        this.loading.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.failure.set(error.error?.message ?? `Could not load the costs (${error.status}).`);
        this.loading.set(false);
      },
    });
  }

  protected add(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.addFailure.set('Pick a category and enter an amount.');

      return;
    }

    const v = this.form.getRawValue();

    this.saving.set(true);
    this.addFailure.set(null);

    this.expenses
      .create({
        category_id: v.category_id,
        trip_id: this.tripId(),
        date: today(),
        amount_cents: Math.round(Number(v.amount) * 100),
        currency: 'PHP',
        payment_source_id: v.payment_source_id || null,
        note: v.note || null,
      })
      .subscribe({
        next: () => {
          this.saving.set(false);
          this.form.reset({ category_id: '', amount: '', payment_source_id: '', note: '' });
          this.load();
        },
        error: (error: HttpErrorResponse) => {
          this.saving.set(false);
          this.addFailure.set(error.error?.message ?? 'Could not add that expense.');
        },
      });
  }
}

import { ChangeDetectionStrategy, Component, computed, effect, inject, input, model, signal } from '@angular/core';
import { Router } from '@angular/router';

import { DateRange, ExpenseLine, ExpenseLines } from '../../models/finance/finance.model';
import { FinanceService } from '../../services/finance/finance.service';
import { Column, DataTable } from '../../shared/data-table';
import { fmt } from '../../shared/format';
import { Modal } from '../../shared/modal';
import { ErrorState } from '../../shared/states';

/**
 * The transactions behind a period's Total expenses.
 *
 * Opened from the tile. The tile is one number made of four sources — the
 * daily sheet's cost columns, the categorised expenses, the supplier bills
 * paid, and the payouts to partners — and "why is it that much" is the first
 * thing anybody asks of it. The API builds this list from the same queries the
 * total is summed from, so the footer figure is the tile's figure; if the two
 * ever disagree, that is a bug, not a rounding.
 *
 * A row opens the screen that owns it. The Summary is a read-only roll-up, so
 * correcting a figure means going to where it was filed.
 */
@Component({
  selector: 'app-expense-lines-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DataTable, ErrorState, Modal],
  template: `
    <app-modal
      [(open)]="open"
      title="Total expenses"
      [subtitle]="subtitle()"
      icon="wallet"
      size="lg"
    >
      @if (error()) {
        <app-error-state [message]="error()!" (retry)="load()" />
      } @else {
        <app-data-table
          [columns]="columns"
          [rows]="report()?.lines ?? null"
          [minWidth]="640"
          rowAction="Open"
          emptyIcon="wallet"
          emptyTitle="No expenses in this period"
          emptyBody="Nothing was spent, billed or paid out between these dates."
          searchPlaceholder="Search fuel, a plate, a supplier…"
          (open)="go($any($event))"
        />

        @if (report(); as r) {
          <div class="mt-3 flex items-baseline justify-between border-t border-cr-line pt-3">
            <span class="text-[14px] font-semibold">
              {{ r.lines.length }} {{ r.lines.length === 1 ? 'transaction' : 'transactions' }}
            </span>
            <span class="cr-num text-[16px] font-semibold">{{ fmt.pesos(r.total_cents) }}</span>
          </div>
        }
      }
    </app-modal>
  `,
})
export class ExpenseLinesDialog {
  private readonly financeApi = inject(FinanceService);
  private readonly router = inject(Router);

  readonly open = model(false);
  readonly range = input.required<DateRange>();

  protected readonly report = signal<ExpenseLines | null>(null);
  protected readonly error = signal<string | null>(null);

  protected readonly fmt = fmt;

  protected readonly subtitle = computed(
    () => `${fmt.date(this.range().from)} – ${fmt.date(this.range().to)}`,
  );

  protected readonly columns: Column<ExpenseLine>[] = [
    { label: 'Date', kind: 'num', value: (l) => fmt.date(l.date) },
    { label: 'Type', kind: 'strong', value: (l) => l.kind, sub: (l) => l.description },
    // Overhead, bills and payouts belong to no unit, and saying so beats a dash.
    { label: 'Truck', kind: 'muted', value: (l) => l.truck ?? (l.source === 'sheet' ? null : 'No truck') },
    { label: 'Amount', kind: 'num', value: (l) => fmt.pesos(l.amount_cents) },
  ];

  constructor() {
    // Fetched on opening rather than with the page: most visits never ask, and
    // a quarter of transactions is the heaviest thing the Summary could load.
    // Re-read each time, so a figure corrected elsewhere shows up here.
    effect(() => {
      if (this.open()) this.load();
    });
  }

  protected load(): void {
    this.report.set(null);
    this.error.set(null);

    this.financeApi.expenseLines(this.range()).subscribe({
      next: (report) => this.report.set(report),
      error: () => this.error.set('Could not load the transactions. Check the connection and try again.'),
    });
  }

  /** Where each kind of row is filed, and so where it is corrected. */
  protected go(line: ExpenseLine): void {
    this.open.set(false);

    switch (line.source) {
      case 'sheet':
        void this.router.navigate(['/monitoring']);
        break;
      case 'expense':
        void this.router.navigate(['/expenses'], { queryParams: { settle: line.record_id } });
        break;
      case 'supplier_bill':
        void this.router.navigate(['/billing', line.record_id]);
        break;
      case 'trucker_payout':
        void this.router.navigate(['/truckers']);
        break;
    }
  }
}

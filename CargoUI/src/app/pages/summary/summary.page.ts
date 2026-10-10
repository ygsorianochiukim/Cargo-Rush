import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { map, switchMap } from 'rxjs';

import { PeriodRollup, QuarterKey, TruckPnl } from '../../models/finance/finance.model';
import {
  currentQuarter,
  emptyTotals,
  hasActivity,
  quarters,
} from '../../services/finance/finance.math';
import { FinanceService } from '../../services/finance/finance.service';
import { Card } from '../../shared/card';
import { ChartTooltip, TooltipRow } from '../../shared/chart-tooltip';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { MarginRing } from '../../shared/margin-ring';
import { SplitBar, SplitPart } from '../../shared/split-bar';
import { ExpenseLinesDialog } from './expense-lines.dialog';
import { ReceivableLinesDialog } from './receivable-lines.dialog';

@Component({
  selector: 'app-summary',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, ChartTooltip, ExpenseLinesDialog, Icon, MarginRing, ReceivableLinesDialog, SplitBar],
  templateUrl: './summary.page.html',
})
export class SummaryPage {
  private readonly financeApi = inject(FinanceService);

  private readonly year = new Date().getUTCFullYear();

  protected readonly quarters = quarters(this.year);

  protected readonly quarter = signal<QuarterKey>(currentQuarter());

  protected readonly range = computed(
    () => this.quarters.find((q) => q.key === this.quarter())!,
  );

  private readonly rollup = toSignal<PeriodRollup | null>(
    toObservable(this.quarter).pipe(
      switchMap((key) => this.financeApi.summary(key, this.year).pipe(map((r) => r.data))),
    ),
    { initialValue: null },
  );

  protected readonly loading = computed(() => this.rollup() === null);

  protected readonly linesOpen = signal(false);

  protected readonly receivablesOpen = signal(false);

  protected readonly rows = computed(() => this.rollup()?.trucks ?? []);
  protected readonly totals = computed(() => this.rollup()?.totals ?? emptyTotals());

  protected readonly unattributed = computed(
    () =>
      this.totals().overhead_cents +
      this.totals().supplier_bills_cents +
      this.totals().payroll_cents,
  );

  protected readonly unattributedParts = computed(() => {
    const t = this.totals();

    return [
      [t.overhead_cents, 'overhead (incl. fuel fills on no truck)'] as const,
      [t.supplier_bills_cents, 'of supplier bills paid'] as const,
      [t.payroll_cents, "of payroll beyond the sheet's crew pay"] as const,
    ]
      .filter(([cents]) => cents > 0)
      .map(([cents, what]) => `${fmt.pesos(cents)} ${what}`);
  });
  protected readonly incomeParts = computed<SplitPart[]>(() => {
    const t = this.totals();

    return [
      { key: 'own', label: 'Own trucks', cents: t.trip_income_cents, value: fmt.pesos(t.trip_income_cents) },
      {
        key: 'commission',
        label: 'Trucker commission',
        cents: t.trucker_commission_cents,
        value: fmt.pesos(t.trucker_commission_cents),
      },
      {
        key: 'other',
        label: 'Other income (invoices raised by hand)',
        cents: t.other_income_cents,
        value: fmt.pesos(t.other_income_cents),
      },
    ];
  });

  protected readonly expenseParts = computed<SplitPart[]>(() => {
    const t = this.totals();
    const trucks =
      t.total_expenses_cents - t.overhead_cents - t.supplier_bills_cents - t.payroll_cents;

    return [
      { key: 'trucks', label: 'Trucks', cents: trucks, value: fmt.pesos(trucks) },
      {
        key: 'overhead',
        label: 'Overhead (incl. fuel fills on no truck)',
        cents: t.overhead_cents,
        value: fmt.pesos(t.overhead_cents),
      },
      {
        key: 'bills',
        label: 'Supplier bills',
        cents: t.supplier_bills_cents,
        value: fmt.pesos(t.supplier_bills_cents),
      },
      {
        key: 'payroll',
        label: 'Payroll',
        cents: t.payroll_cents,
        value: fmt.pesos(t.payroll_cents),
      },
    ];
  });

  protected readonly best = computed(() => this.rollup()?.best_performer ?? null);

  protected readonly active = computed(() => this.rows().filter(hasActivity));

  private readonly scaleMax = computed(() =>
    Math.max(
      1,
      ...this.active().map((r) => Math.max(r.trip_income_cents, r.total_expenses_cents)),
    ),
  );

  protected width(cents: number): number {
    return Math.round((cents / this.scaleMax()) * 100);
  }

  protected readonly hovered = signal<string | null>(null);

  protected breakdown(row: TruckPnl): TooltipRow[] {
    return [
      { label: 'Trip income', value: fmt.pesos(row.trip_income_cents) },
      { label: 'Total expenses', value: fmt.pesos(row.total_expenses_cents) },
      ...(row.owner_share_cents > 0
        ? [{ label: '· of it owner share', value: fmt.pesos(row.owner_share_cents) }]
        : []),
      {
        label: 'Net income',
        value: fmt.pesos(row.net_income_cents),
        negative: row.net_income_cents < 0,
      },
      { label: 'Days recorded', value: String(row.entry_count) },
    ];
  }

  protected spoken(row: TruckPnl): string {
    const name = row.truck.plate ?? row.truck.label;

    return `${name}: ${this.breakdown(row).map((r) => `${r.label} ${r.value}`).join(', ')}`;
  }

  protected select(key: QuarterKey): void {
    this.quarter.set(key);
  }

  protected readonly fmt = fmt;
  protected readonly skeleton = [0, 1, 2, 3, 4, 5, 6];
}

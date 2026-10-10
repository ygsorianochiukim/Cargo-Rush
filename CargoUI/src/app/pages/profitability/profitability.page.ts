import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { switchMap } from 'rxjs';

import { PeriodRollup, TruckPnl } from '../../models/finance/finance.model';
import {
  currentTenDayStart,
  emptyTotals,
  hasActivity,
  tenDayRange,
} from '../../services/finance/finance.math';
import { FinanceService } from '../../services/finance/finance.service';
import { BarRow, BarRows } from '../../shared/bar-rows';
import { Card } from '../../shared/card';
import { Donut, DonutSlice } from '../../shared/donut';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';

@Component({
  selector: 'app-profitability',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [BarRows, Card, Icon, Donut],
  templateUrl: './profitability.page.html',
})
export class ProfitabilityPage {
  private readonly financeApi = inject(FinanceService);

  protected readonly from = signal(currentTenDayStart());
  protected readonly range = computed(() => tenDayRange(this.from()));

  private readonly rollup = toSignal<PeriodRollup | null>(
    toObservable(this.range).pipe(switchMap((range) => this.financeApi.profitability(range))),
    { initialValue: null },
  );

  protected readonly loading = computed(() => this.rollup() === null);

  protected readonly rows = computed<TruckPnl[]>(() => this.rollup()?.trucks ?? []);

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
  protected readonly best = computed(() => this.rollup()?.best_performer ?? null);
  protected readonly average = computed(
    () => this.rollup()?.average_profit_per_truck ?? { cents: 0, trucks: 0 },
  );

  protected readonly active = computed(() => this.rows().filter(hasActivity));

  protected readonly shares = computed<DonutSlice[]>(() =>
    this.active().map((r) => ({
      key: r.truck.id,
      label: r.truck.plate ?? r.truck.label,
      value: r.net_income_cents,
      detail: fmt.pesos(r.net_income_cents),
    })),
  );

  protected readonly incomeBars = computed<BarRow[]>(() =>
    this.active().map((r) => ({
      key: r.truck.id,
      label: r.truck.plate ?? r.truck.label,
      value: fmt.pesos(r.trip_income_cents),
      pct: this.incomeWidth(r.trip_income_cents),
      tone: 'income' as const,
      rows: [
        { label: 'Trip income', value: fmt.pesos(r.trip_income_cents) },
        { label: 'Days recorded', value: String(r.entry_count) },
      ],
    })),
  );

  protected readonly expenseBars = computed<BarRow[]>(() =>
    this.active().map((r) => ({
      key: r.truck.id,
      label: r.truck.plate ?? r.truck.label,
      value: fmt.pesos(r.total_expenses_cents),
      pct: this.expenseWidth(r.total_expenses_cents),
      tone: 'expense' as const,
      rows: [
        { label: 'Fuel', value: fmt.pesos(r.fuel_cents) },
        { label: 'Driver', value: fmt.pesos(r.driver_salary_cents) },
        { label: 'Helper', value: fmt.pesos(r.helper_salary_cents) },
        { label: 'Maintenance', value: fmt.pesos(r.maintenance_cents) },
        { label: 'Allowance', value: fmt.pesos(r.allowance_cents) },
        { label: 'Owner share', value: fmt.pesos(r.owner_share_cents) },
        { label: 'Other expenses', value: fmt.pesos(r.other_expenses_cents) },
      ],
    })),
  );

  protected readonly netBars = computed<BarRow[]>(() =>
    this.active().map((r) => ({
      key: r.truck.id,
      label: r.truck.plate ?? r.truck.label,
      value: fmt.pesos(r.net_income_cents),
      pct: this.netWidth(r.net_income_cents),
      tone: 'net' as const,
      negative: r.net_income_cents < 0,
      rows: [
        { label: 'Income', value: fmt.pesos(r.trip_income_cents) },
        { label: 'Expenses', value: fmt.pesos(r.total_expenses_cents) },
        {
          label: 'Net',
          value: fmt.pesos(r.net_income_cents),
          negative: r.net_income_cents < 0,
        },
        { label: 'Share of net', value: `${(r.net_share * 100).toFixed(1)}%` },
      ],
    })),
  );

  private max(pick: (r: TruckPnl) => number): number {
    return Math.max(1, ...this.active().map(pick));
  }

  protected incomeWidth(cents: number): number {
    return Math.round((cents / this.max((r) => r.trip_income_cents)) * 100);
  }

  protected expenseWidth(cents: number): number {
    return Math.round((cents / this.max((r) => r.total_expenses_cents)) * 100);
  }

  protected netWidth(cents: number): number {
    return Math.round((Math.abs(cents) / this.max((r) => Math.abs(r.net_income_cents))) * 50);
  }

  protected shift(days: number): void {
    const date = new Date(`${this.from()}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);
    this.from.set(date.toISOString().slice(0, 10));
  }

  protected onFromChange(value: string): void {
    if (value) this.from.set(value);
  }

  protected readonly fmt = fmt;
  protected readonly skeleton = [0, 1, 2, 3, 4];
}

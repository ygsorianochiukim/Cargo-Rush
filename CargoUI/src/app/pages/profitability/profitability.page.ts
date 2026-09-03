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

/**
 * Profitability — the workbook's "DashBoard" sheet, laid out the same way:
 * a titled 10-day header with the From/To range, the five summary tiles,
 * the four charts, then the per-unit table with its TOTAL row.
 *
 * The roll-up is the API's, not this page's. Quarterly Summary asks the same
 * endpoint with a different range, so the two pages cannot print arithmetic
 * that disagrees.
 */
@Component({
  selector: 'app-profitability',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [BarRows, Card, Icon, Donut],
  templateUrl: './profitability.page.html',
})
export class ProfitabilityPage {
  private readonly financeApi = inject(FinanceService);

  /**
   * The ten days ending today, matching what the API defaults to.
   *
   * The workbook opens on 5 April 2026 and this page used to pin that date.
   * It meant a day recorded now sat outside the window the page opens on, so
   * the ledger looked as though it never reached Profitability. The From box
   * and the step buttons still reach April.
   */
  protected readonly from = signal(currentTenDayStart());
  protected readonly range = computed(() => tenDayRange(this.from()));

  private readonly rollup = toSignal<PeriodRollup | null>(
    toObservable(this.range).pipe(switchMap((range) => this.financeApi.profitability(range))),
    { initialValue: null },
  );

  protected readonly loading = computed(() => this.rollup() === null);

  protected readonly rows = computed<TruckPnl[]>(() => this.rollup()?.trucks ?? []);

  protected readonly totals = computed(() => this.rollup()?.totals ?? emptyTotals());
  protected readonly best = computed(() => this.rollup()?.best_performer ?? null);
  protected readonly average = computed(
    () => this.rollup()?.average_profit_per_truck ?? { cents: 0, trucks: 0 },
  );

  /** Charts show only units that actually traded in the period. */
  protected readonly active = computed(() => this.rows().filter(hasActivity));

  protected readonly shares = computed<DonutSlice[]>(() =>
    this.active().map((r) => ({
      key: r.truck.id,
      label: r.truck.plate ?? r.truck.label,
      value: r.net_income_cents,
      detail: fmt.money(r.net_income_cents),
    })),
  );

  /**
   * The three per-unit bar lists.
   *
   * Each carries the same breakdown in its tooltip, so a unit can be read in
   * full from whichever chart the eye landed on rather than only from the
   * table further down the page.
   */
  protected readonly incomeBars = computed<BarRow[]>(() =>
    this.active().map((r) => ({
      key: r.truck.id,
      label: r.truck.plate ?? r.truck.label,
      value: fmt.money(r.trip_income_cents),
      pct: this.incomeWidth(r.trip_income_cents),
      tone: 'income' as const,
      rows: [
        { label: 'Trip income', value: fmt.money(r.trip_income_cents) },
        { label: 'Days recorded', value: String(r.entry_count) },
      ],
    })),
  );

  protected readonly expenseBars = computed<BarRow[]>(() =>
    this.active().map((r) => ({
      key: r.truck.id,
      label: r.truck.plate ?? r.truck.label,
      value: fmt.money(r.total_expenses_cents),
      pct: this.expenseWidth(r.total_expenses_cents),
      tone: 'expense' as const,
      // The five columns the total is made of — this is where somebody looks
      // when a unit is expensive and they want to know which part of it is.
      rows: [
        { label: 'Fuel', value: fmt.money(r.fuel_cents) },
        { label: 'Driver', value: fmt.money(r.driver_salary_cents) },
        { label: 'Helper', value: fmt.money(r.helper_salary_cents) },
        { label: 'Maintenance', value: fmt.money(r.maintenance_cents) },
        { label: 'Allowance', value: fmt.money(r.allowance_cents) },
      ],
    })),
  );

  protected readonly netBars = computed<BarRow[]>(() =>
    this.active().map((r) => ({
      key: r.truck.id,
      label: r.truck.plate ?? r.truck.label,
      value: fmt.money(r.net_income_cents),
      pct: this.netWidth(r.net_income_cents),
      tone: 'net' as const,
      negative: r.net_income_cents < 0,
      rows: [
        { label: 'Income', value: fmt.money(r.trip_income_cents) },
        { label: 'Expenses', value: fmt.money(r.total_expenses_cents) },
        {
          label: 'Net',
          value: fmt.money(r.net_income_cents),
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

  /** Net bars are centred: a loss runs left of the axis, a profit right. */
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

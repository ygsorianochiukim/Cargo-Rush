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

/**
 * Quarterly Summary — the workbook's "Summary" sheet and its quarter slicer.
 *
 * The same roll-up Profitability shows, over a wider window. Both call
 * `FinanceService`, so "the same aggregation" is a fact rather than an
 * intention that two pages have to keep honouring separately.
 */
@Component({
  selector: 'app-summary',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, ChartTooltip],
  templateUrl: './summary.page.html',
})
export class SummaryPage {
  private readonly financeApi = inject(FinanceService);

  /**
   * The book runs by calendar year, and Table11 defines the boundaries within
   * it. Taken from the clock rather than fixed at 2026, so the page does not
   * quietly start reporting last year's figures come January.
   */
  private readonly year = new Date().getUTCFullYear();

  protected readonly quarters = quarters(this.year);

  /**
   * Opens on the quarter we are actually in.
   *
   * This was pinned to `q1`, so a day recorded in any other quarter was
   * outside the opening view and the ledger looked as though it never reached
   * the summary. The slicer still reaches every quarter.
   */
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

  protected readonly rows = computed(() => this.rollup()?.trucks ?? []);
  protected readonly totals = computed(() => this.rollup()?.totals ?? emptyTotals());
  protected readonly best = computed(() => this.rollup()?.best_performer ?? null);

  /** Charts show only units that actually traded in the period. */
  protected readonly active = computed(() => this.rows().filter(hasActivity));

  /** Income and expenses share one scale so the pair can be read against each other. */
  private readonly scaleMax = computed(() =>
    Math.max(
      1,
      ...this.active().map((r) => Math.max(r.trip_income_cents, r.total_expenses_cents)),
    ),
  );

  protected width(cents: number): number {
    return Math.round((cents / this.scaleMax()) * 100);
  }

  /** The unit under the pointer or holding focus. */
  protected readonly hovered = signal<string | null>(null);

  /**
   * What the pair of bars adds up to.
   *
   * The chart shows income against expenses; this is the arithmetic between
   * them, which is the thing somebody is squinting at the gap to work out.
   */
  protected breakdown(row: TruckPnl): TooltipRow[] {
    return [
      { label: 'Trip income', value: fmt.money(row.trip_income_cents) },
      { label: 'Total expenses', value: fmt.money(row.total_expenses_cents) },
      {
        label: 'Net income',
        value: fmt.money(row.net_income_cents),
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
  protected readonly skeleton = [0, 1, 2, 3];
}

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { switchMap } from 'rxjs';

import { Granularity, SalesBucket, SalesReport } from '../../models/finance/sales.model';
import { FinanceService } from '../../services/finance/finance.service';
import { Bar, BarChart } from '../../shared/bar-chart';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';

@Component({
  selector: 'app-sales',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [BarChart, Card, DataTable, Icon],
  templateUrl: './sales.page.html',
})
export class SalesPage {
  private readonly financeApi = inject(FinanceService);

  protected readonly fmt = fmt;

  protected readonly granularity = signal<Granularity>('daily');

  protected readonly options: { value: Granularity; label: string }[] = [
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'monthly', label: 'Monthly' },
  ];

  protected readonly report = toSignal<SalesReport | null>(
    toObservable(this.granularity).pipe(switchMap((g) => this.financeApi.sales(g))),
    { initialValue: null },
  );

  protected readonly loading = computed(() => this.report() === null);
  protected readonly series = computed<SalesBucket[]>(() => this.report()?.series ?? []);
  protected readonly totals = computed(() => this.report()?.totals ?? null);
  protected readonly currency = computed(() => this.report()?.currency ?? 'PHP');

  protected readonly bars = computed<Bar[]>(() => {
    const series = this.series().slice(-14);
    const best = this.totals()?.best?.key ?? null;

    return series.map((bucket) => ({
      key: bucket.key,
      label: this.shortLabel(bucket),
      value: bucket.sales_cents,
      highlight: bucket.key === best,
      rows: [
        { label: 'Sales', value: fmt.money(bucket.sales_cents, this.currency()) },
        { label: 'Expenses', value: fmt.money(bucket.expenses_cents, this.currency()) },
        { label: 'Net', value: fmt.money(bucket.net_cents, this.currency()) },
      ],
    }));
  });

  private shortLabel(bucket: SalesBucket): string {
    switch (this.granularity()) {
      case 'monthly':
        return new Date(bucket.from).toLocaleDateString([], { month: 'short' });
      case 'weekly':
        return new Date(bucket.from).toLocaleDateString([], { day: '2-digit', month: 'short' });
      default:
        return new Date(bucket.from).toLocaleDateString([], { day: '2-digit', month: 'short' });
    }
  }

  protected readonly marginPct = computed(() => {
    const margin = this.totals()?.margin;

    return margin === null || margin === undefined ? '—' : `${Math.round(margin * 100)}%`;
  });

  protected readonly losing = computed(() => (this.totals()?.net_cents ?? 0) < 0);

  protected readonly averageNoun = computed(() =>
    this.granularity() === 'monthly' ? 'month' : this.granularity() === 'weekly' ? 'week' : 'day',
  );

  protected readonly columns: Column<SalesBucket>[] = [
    { label: 'Period', kind: 'strong', value: (b) => b.label },
    { label: 'Entries', kind: 'num', value: (b) => b.entry_count },
    { label: 'Sales', kind: 'num', value: (b) => fmt.money(b.sales_cents, this.currency()) },
    { label: 'Expenses', kind: 'num', value: (b) => fmt.money(b.expenses_cents, this.currency()) },
    { label: 'Net', kind: 'num', value: (b) => fmt.money(b.net_cents, this.currency()) },
  ];

  protected readonly tableRows = computed(() => [...this.series()].reverse());
}

import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';
import { catchError, map, of, startWith, switchMap } from 'rxjs';

import {
  AgingBucket,
  BusinessDashboard,
  DashboardPeriod,
  MoneyKpi,
} from '../../models/dashboard/dashboard.model';
import { DashboardService } from '../../services/dashboard/dashboard.service';
import { BarRow, BarRows } from '../../shared/bar-rows';
import { Card } from '../../shared/card';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { IncomeTrend } from '../../shared/income-trend';

function today(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

const AGING_TONE: Record<AgingBucket['key'], string> = {
  current: 'bg-cr-blue',
  '1_30': 'bg-[#d9a34a]',
  '31_60': 'bg-cr-warning',
  '61_90': 'bg-[#c2410c]',
  over_90: 'bg-cr-red',
};

@Component({
  selector: 'app-business-dashboard',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [BarRows, Card, Icon, IncomeTrend, NgTemplateOutlet, RouterLink],
  templateUrl: './business-dashboard.page.html',
})
export class BusinessDashboardPage {
  private readonly api = inject(DashboardService);

  protected readonly fmt = fmt;
  protected toneFor(key: string): string {
    return AGING_TONE[key as AgingBucket['key']] ?? 'bg-cr-line';
  }

  protected readonly periods: { value: DashboardPeriod; label: string }[] = [
    { value: 'month', label: 'Month' },
    { value: 'quarter', label: 'Quarter' },
    { value: 'year', label: 'Year' },
  ];

  protected readonly period = signal<DashboardPeriod>('month');
  protected readonly date = signal(today());

  private readonly state = toSignal(
    toObservable(computed(() => ({ period: this.period(), date: this.date() }))).pipe(
      switchMap(({ period, date }) =>
        this.api.business(period, date).pipe(
          map((data) => ({ data, error: false })),
          catchError(() => of({ data: null, error: true })),
          startWith({ data: null, error: false }),
        ),
      ),
    ),
    { initialValue: { data: null as BusinessDashboard | null, error: false } },
  );

  protected readonly data = computed(() => this.state().data);
  protected readonly failed = computed(() => this.state().error);

  protected readonly previousLabel = computed(() => {
    const d = this.data();

    if (d === null) return '';

    const from = new Date(`${d.previous_range.from}T00:00:00`);

    return d.period === 'year'
      ? String(from.getFullYear())
      : d.period === 'quarter'
        ? `Q${Math.floor(from.getMonth() / 3) + 1} ${from.getFullYear()}`
        : from.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
  });

  protected step(direction: -1 | 1): void {
    const d = new Date(`${this.date()}T00:00:00`);
    const months = this.period() === 'year' ? 12 : this.period() === 'quarter' ? 3 : 1;

    d.setDate(1);
    d.setMonth(d.getMonth() + direction * months);

    const pad = (n: number) => String(n).padStart(2, '0');
    this.date.set(`${d.getFullYear()}-${pad(d.getMonth() + 1)}-01`);
  }

  protected kpiValue(k: MoneyKpi): string {
    if (k.key === 'margin') return k.ratio === null || k.ratio === undefined ? '—' : `${Math.round(k.ratio * 100)}%`;

    return fmt.pesos(k.value_cents ?? 0);
  }

  protected kpiChange(k: MoneyKpi): { text: string; good: boolean | null } | null {
    if (k.change === null) return null;

    const up = k.change > 0;
    const flat = Math.abs(k.change) < 0.0005;
    const text =
      k.key === 'margin'
        ? `${up ? '+' : ''}${Math.round(k.change * 1000) / 10} pts`
        : `${up ? '+' : ''}${Math.round(k.change * 1000) / 10}%`;

    return { text, good: flat ? null : up === k.higher_is_better };
  }

  protected kpiIcon(k: MoneyKpi): string {
    return { income: 'billing', expenses: 'wallet', net: 'trend', margin: 'gauge' }[k.key];
  }

  protected agingTotal(buckets: AgingBucket[]): number {
    return buckets.reduce((sum, b) => sum + b.cents, 0);
  }

  protected agingLabel(buckets: AgingBucket[]): string {
    return buckets.map((b) => `${b.label} ${fmt.pesos(b.cents)}`).join(', ');
  }

  protected agingPct(bucket: AgingBucket, buckets: AgingBucket[]): number {
    const total = this.agingTotal(buckets);

    return total === 0 ? 0 : (bucket.cents / total) * 100;
  }

  protected readonly incomeBars = computed<BarRow[]>(() => {
    const d = this.data();

    if (d === null) return [];

    const parts = [
      { key: 'trips', label: 'Own trucks', cents: d.income.trip_income_cents },
      { key: 'commission', label: 'Trucker commission', cents: d.income.trucker_commission_cents },
      { key: 'other', label: 'Other income', cents: d.income.other_income_cents },
    ];
    const largest = Math.max(1, ...parts.map((p) => p.cents));

    return parts.map((p) => ({
      key: p.key,
      label: p.label,
      value: fmt.pesos(p.cents),
      pct: Math.round((p.cents / largest) * 100),
      tone: 'income' as const,
    }));
  });

  protected readonly expenseBars = computed<BarRow[]>(() => {
    const rows = this.data()?.expense_mix ?? [];
    const total = rows.reduce((s, r) => s + r.amount_cents, 0);
    const largest = Math.max(1, ...rows.map((r) => r.amount_cents));

    return rows.map((r) => ({
      key: r.kind,
      label: r.kind,
      value: fmt.pesos(r.amount_cents),
      pct: Math.round((r.amount_cents / largest) * 100),
      tone: 'expense' as const,
      rows: [{ label: 'Share', value: `${total === 0 ? 0 : Math.round((r.amount_cents / total) * 100)}%` }],
    }));
  });

  protected pct(ratio: number | null): string {
    return ratio === null ? '—' : `${Math.round(ratio * 100)}%`;
  }

  protected readonly kpiSkeletons = [0, 1, 2, 3];
}

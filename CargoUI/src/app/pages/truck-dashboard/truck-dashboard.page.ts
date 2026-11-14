import { ChangeDetectionStrategy, Component, computed, effect, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { catchError, map, of, startWith, switchMap } from 'rxjs';

import { DashboardPeriod, MoneyKpi, TruckDetail } from '../../models/dashboard/dashboard.model';
import { DashboardService } from '../../services/dashboard/dashboard.service';
import { FinanceService } from '../../services/finance/finance.service';
import { BarRow, BarRows } from '../../shared/bar-rows';
import { Card } from '../../shared/card';
import { Donut, DonutSlice } from '../../shared/donut';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { IncomeTrend } from '../../shared/income-trend';

function today(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

const PAGE = 5;

@Component({
  selector: 'app-truck-dashboard',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [BarRows, Card, Donut, Icon, IncomeTrend, RouterLink],
  templateUrl: './truck-dashboard.page.html',
})
export class TruckDashboardPage {
  private readonly api = inject(DashboardService);
  private readonly finance = inject(FinanceService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  protected readonly fmt = fmt;

  protected readonly periods: { value: DashboardPeriod; label: string }[] = [
    { value: 'month', label: 'Month' },
    { value: 'quarter', label: 'Quarter' },
    { value: 'year', label: 'Year' },
  ];

  protected readonly period = signal<DashboardPeriod>('month');
  protected readonly date = signal(today());
  protected readonly costPage = signal(0);

  protected readonly trucks = toSignal(
    this.finance.trucks().pipe(catchError(() => of([]))),
    { initialValue: null },
  );

  private readonly requested = toSignal(
    this.route.queryParamMap.pipe(map((q) => q.get('truck'))),
    { initialValue: null },
  );

  protected readonly truckId = computed(() => {
    const list = this.trucks();
    const wanted = this.requested();

    if (list === null) return null;
    if (wanted !== null && list.some((t) => t.id === wanted)) return wanted;

    return list[0]?.id ?? null;
  });

  constructor() {
    effect(() => {
      this.truckId();
      this.period();
      this.date();
      this.costPage.set(0);
    });
  }

  private readonly state = toSignal(
    toObservable(
      computed(() => ({ id: this.truckId(), period: this.period(), date: this.date() })),
    ).pipe(
      switchMap(({ id, period, date }) =>
        id === null
          ? of({ data: null, error: false })
          : this.api.truck(id, period, date).pipe(
              map((data) => ({ data, error: false })),
              catchError(() => of({ data: null, error: true })),
              startWith({ data: null, error: false }),
            ),
      ),
    ),
    { initialValue: { data: null as TruckDetail | null, error: false } },
  );

  protected readonly data = computed(() => this.state().data);
  protected readonly failed = computed(() => this.state().error);
  protected readonly empty = computed(() => this.trucks()?.length === 0);

  protected pick(id: string): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { truck: id },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
  }

  protected step(direction: -1 | 1): void {
    const d = new Date(`${this.date()}T00:00:00`);
    const months = this.period() === 'year' ? 12 : this.period() === 'quarter' ? 3 : 1;

    d.setDate(1);
    d.setMonth(d.getMonth() + direction * months);

    const pad = (n: number) => String(n).padStart(2, '0');
    this.date.set(`${d.getFullYear()}-${pad(d.getMonth() + 1)}-01`);
  }

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

  protected kpiValue(k: MoneyKpi): string {
    if (k.key === 'margin') return k.ratio === null || k.ratio === undefined ? '—' : `${Math.round(k.ratio * 100)}%`;

    return fmt.pesos(k.value_cents ?? 0);
  }

  protected kpiChange(k: MoneyKpi): { text: string; up: boolean; good: boolean | null } | null {
    if (k.change === null) return null;

    const up = k.change > 0;
    const flat = Math.abs(k.change) < 0.0005;
    const text =
      k.key === 'margin'
        ? `${up ? '+' : ''}${Math.round(k.change * 1000) / 10} pts`
        : `${up ? '+' : ''}${Math.round(k.change * 1000) / 10}%`;

    return { text, up, good: flat ? null : up === k.higher_is_better };
  }

  protected kpiIcon(k: MoneyKpi): string {
    return { income: 'billing', expenses: 'wallet', net: 'trend', margin: 'gauge' }[k.key];
  }

  protected kpiNegative(k: MoneyKpi): boolean {
    return (k.key === 'net' && (k.value_cents ?? 0) < 0) || (k.key === 'margin' && (k.ratio ?? 0) < 0);
  }

  protected readonly incomeTotal = computed(() =>
    (this.data()?.customers ?? []).reduce((s, c) => s + c.income_cents, 0),
  );

  protected readonly customerSlices = computed<DonutSlice[]>(() =>
    (this.data()?.customers ?? []).map((c) => ({
      key: c.name,
      label: c.name,
      value: c.income_cents,
      detail: `${fmt.pesos(c.income_cents)} · ${c.trips} ${c.trips === 1 ? 'trip' : 'trips'}`,
    })),
  );

  protected readonly costPages = computed(() =>
    Math.max(1, Math.ceil((this.data()?.costs.length ?? 0) / PAGE)),
  );

  protected readonly costRows = computed(() =>
    (this.data()?.costs ?? []).slice(this.costPage() * PAGE, (this.costPage() + 1) * PAGE),
  );

  protected costStatus(row: TruckDetail['costs'][number]): { label: string; tone: 'up' | 'down' | 'flat' | 'new' } {
    if (row.previous_cents === 0 && row.current_cents > 0) return { label: 'New', tone: 'new' };
    if (row.variance_cents > 0) return { label: 'Higher', tone: 'up' };
    if (row.variance_cents < 0) return { label: 'Lower', tone: 'down' };

    return { label: 'Same', tone: 'flat' };
  }

  protected readonly destinationBars = computed<BarRow[]>(() => {
    const rows = this.data()?.destinations ?? [];
    const largest = Math.max(1, ...rows.map((r) => r.trips));

    return rows.map((r) => ({
      key: r.name,
      label: r.name,
      value: `${r.trips} ${r.trips === 1 ? 'trip' : 'trips'}`,
      pct: Math.round((r.trips / largest) * 100),
      tone: 'income' as const,
      rows: [{ label: 'Income', value: fmt.pesos(r.income_cents) }],
    }));
  });

  protected readonly kpiSkeletons = [0, 1, 2, 3];
}

import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';

import { TruckReport, TruckReportPeriod } from '../../models/finance/finance.model';
import { FinanceService } from '../../services/finance/finance.service';
import { Card } from '../../shared/card';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';

function today(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

@Component({
  selector: 'app-truck-report',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Icon, NgTemplateOutlet],
  templateUrl: './truck-report.page.html',
})
export class TruckReportPage {
  private readonly financeApi = inject(FinanceService);

  protected readonly fmt = fmt;

  protected readonly periods: { value: TruckReportPeriod; label: string }[] = [
    { value: 'day', label: 'Day' },
    { value: 'cutoff', label: 'Cutoff' },
    { value: 'month', label: 'Month' },
  ];

  protected readonly period = signal<TruckReportPeriod>('month');
  protected readonly date = signal(today());
  protected readonly open = signal<Set<string>>(new Set());

  private readonly query = computed(() => ({ period: this.period(), date: this.date() }));

  private readonly state = toSignal(
    toObservable(this.query).pipe(
      switchMap(({ period, date }) =>
        this.financeApi.truckReport(period, date).pipe(
          map((report) => ({ report, error: false })),
          catchError(() => of({ report: null, error: true })),
          startWith({ report: null, error: false }),
        ),
      ),
    ),
    { initialValue: { report: null as TruckReport | null, error: false } },
  );

  protected readonly report = computed(() => this.state().report);
  protected readonly failed = computed(() => this.state().error);

  protected readonly active = computed(() =>
    (this.report()?.trucks ?? []).filter((t) => t.income_cents !== 0 || t.lines.length > 0),
  );

  protected readonly idle = computed(() =>
    (this.report()?.trucks ?? []).filter((t) => t.income_cents === 0 && t.lines.length === 0),
  );

  protected setPeriod(period: TruckReportPeriod): void {
    this.period.set(period);
    this.open.set(new Set());
  }

  protected setDate(value: string): void {
    if (!value) return;

    this.date.set(value.length === 7 ? `${value}-01` : value);
    this.open.set(new Set());
  }

  protected step(direction: -1 | 1): void {
    const report = this.report();

    if (report) this.setDate(direction < 0 ? report.previous : report.next);
  }

  protected toggle(key: string): void {
    const next = new Set(this.open());

    if (next.has(key)) next.delete(key);
    else next.add(key);

    this.open.set(next);
  }

  protected readonly monthValue = computed(() => this.date().slice(0, 7));
}

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { switchMap } from 'rxjs';

import { CrewPerformance, PerformanceReport } from '../../models/hr/time-off.model';
import { TimeOffService } from '../../services/hr/time-off.service';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';

/**
 * Performance — the crew ranked by the work they actually completed.
 *
 * Every figure is recomputed from trips, deliveries and incidents on each load
 * rather than stored, so it cannot drift from the operational record. There is
 * deliberately no single score: the weights would be invented here and then
 * used to decide somebody's job.
 */
@Component({
  selector: 'app-performance',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, Icon],
  templateUrl: './performance.page.html',
})
export class PerformancePage {
  private readonly timeOffApi = inject(TimeOffService);

  protected readonly fmt = fmt;

  protected readonly windows = [
    { days: 30, label: '30 days' },
    { days: 90, label: '90 days' },
    { days: 365, label: '12 months' },
  ];

  protected readonly days = signal(30);

  protected readonly range = computed(() => {
    const to = new Date();
    const from = new Date();
    from.setDate(from.getDate() - (this.days() - 1));

    return { from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) };
  });

  protected readonly report = toSignal<PerformanceReport | null>(
    toObservable(this.range).pipe(switchMap((range) => this.timeOffApi.performance(range))),
    { initialValue: null },
  );

  protected readonly loading = computed(() => this.report() === null);
  protected readonly crew = computed<CrewPerformance[]>(() => this.report()?.crew ?? []);
  protected readonly totals = computed(() => this.report()?.totals ?? null);

  protected readonly onTimePct = computed(() => {
    const rate = this.totals()?.on_time_rate;

    return rate === null || rate === undefined ? '—' : `${Math.round(rate * 100)}%`;
  });

  /** A rate is null when nothing was delivered — "—", never a green 100%. */
  protected readonly pct = (value: number | null): string =>
    value === null ? '—' : `${Math.round(value * 100)}%`;

  protected readonly columns: Column<CrewPerformance>[] = [
    {
      label: 'Crew',
      kind: 'strong',
      value: (r) => r.employee.name,
      sub: (r) => r.employee.position,
    },
    { label: 'Completed', kind: 'num', value: (r) => r.trips_completed },
    { label: 'Assigned', kind: 'num', value: (r) => r.trips_assigned },
    { label: 'On time', kind: 'num', value: (r) => this.pct(r.on_time_rate) },
    { label: 'Completion', kind: 'num', value: (r) => this.pct(r.completion_rate) },
    { label: 'Distance', kind: 'num', value: (r) => fmt.km(r.distance_km) },
    { label: 'Revenue', kind: 'num', value: (r) => fmt.money(r.revenue_cents) },
    { label: 'Incidents', kind: 'num', value: (r) => r.incidents },
    { label: 'Leave', kind: 'num', value: (r) => `${r.leave_days} d` },
    { label: 'Undertime', kind: 'num', value: (r) => `${r.undertime_hours} h` },
  ];
}

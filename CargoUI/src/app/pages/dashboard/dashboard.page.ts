import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

import { DashboardService } from '../../services/dashboard/dashboard.service';
import { TripService } from '../../services/trip/trip.service';
import { TONE_CLASS, TONE_DOT, toneFor } from '../../shared/status';
import { Bar, BarChart } from '../../shared/bar-chart';
import { Card } from '../../shared/card';
import { ChartTooltip, TooltipRow } from '../../shared/chart-tooltip';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { KpiTile } from '../../shared/kpi-tile';
import { SkeletonRows } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';

@Component({
  selector: 'app-dashboard',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [BarChart, Card, ChartTooltip, Icon, KpiTile, SkeletonRows, StatusPill],
  templateUrl: './dashboard.page.html',
})
export class DashboardPage {
  private readonly dashboard = inject(DashboardService);
  private readonly trips = inject(TripService);

  protected readonly kpis = toSignal(this.dashboard.kpis(), {
    initialValue: null,
  });

  protected readonly shipments = toSignal(this.trips.list({ per_page: 6 }).pipe(map((r) => r.data)), {
    initialValue: null,
  });

  protected readonly fleet = toSignal(this.dashboard.fleet(), {
    initialValue: null,
  });

  protected readonly activity = toSignal(this.dashboard.activity(), {
    initialValue: null,
  });

  protected readonly deliveries = toSignal(this.dashboard.deliveries(), {
    initialValue: null,
  });

  protected readonly receivables = toSignal(this.dashboard.receivables(), {
    initialValue: null,
  });

  /**
   * How much of what has been billed has actually come in.
   *
   * Drawn as a share bar rather than printed as a third figure: the question
   * the pair answers is "how are we doing at getting paid", and a proportion
   * reads faster than two pesos amounts the reader has to divide.
   *
   * Zero billed is not a zero collection rate — it is nothing to report — so
   * it renders as an empty bar rather than as 0%.
   */
  protected readonly collectedPct = computed(() => {
    const money = this.receivables();
    if (money === null) return 0;

    const billed = money.pending_payment_cents + money.successful_payment_cents;

    return billed === 0 ? 0 : Math.round((money.successful_payment_cents / billed) * 100);
  });

  protected readonly fleetTotal = computed(() =>
    (this.fleet() ?? []).reduce((sum, s) => sum + s.count, 0),
  );

  /**
   * The delivery series as the chart wants it.
   *
   * The date rides in the tooltip because the axis only has room for "Mon" —
   * and on the seventh of a month that is genuinely ambiguous.
   */
  protected readonly deliveryBars = computed<Bar[]>(() => {
    const busiest = this.busiestDay();

    return (this.deliveries() ?? []).map((point) => ({
      key: point.date,
      label: point.day,
      value: point.delivered,
      highlight: point.date === busiest?.date,
      rows: [
        { label: 'Delivered', value: String(point.delivered) },
        { label: 'Date', value: fmt.date(point.date) },
      ],
    }));
  });

  protected readonly busiestDay = computed(() => {
    const list = this.deliveries() ?? [];
    return list.reduce<(typeof list)[number] | null>(
      (best, d) => (!best || d.delivered > best.delivered ? d : best),
      null,
    );
  });

  /** The fleet segment under the pointer or holding focus. */
  protected readonly segment = signal<string | null>(null);

  /**
   * What a fleet segment says on hover.
   *
   * The count and share are already on the row; what is not visible is how
   * many units the share is out of, which is the number that makes "13%"
   * mean something.
   */
  protected fleetRows(seg: { count: number }): TooltipRow[] {
    return [
      { label: 'Vehicles', value: String(seg.count) },
      { label: 'Share of fleet', value: `${this.sharePct(seg.count)}%` },
      { label: 'Fleet size', value: String(this.fleetTotal()) },
    ];
  }

  protected sharePct(count: number): number {
    const total = this.fleetTotal();
    return total === 0 ? 0 : Math.round((count / total) * 100);
  }

  protected tone(status: Parameters<typeof toneFor>[0]) {
    return toneFor(status);
  }

  protected readonly toneClass = TONE_CLASS;
  protected readonly toneDot = TONE_DOT;

  protected eta(value: string | null): string {
    if (!value) return '—';
    return new Date(value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  /** The window the income figure covers, once the API has said what it is. */
  protected readonly receivablesHint = computed(() => {
    const money = this.receivables();

    return money === null ? undefined : `Last ${money.window_days} days`;
  });

  protected readonly fmt = fmt;

  protected readonly kpiSkeletons = [0, 1, 2, 3];
}

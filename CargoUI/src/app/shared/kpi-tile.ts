import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

import { Kpi } from '../models/dashboard/dashboard.model';

/**
 * KPI tile — DESIGN.md section 8.
 * The delta carries a sign and an arrow as well as colour, so the direction is
 * readable without colour vision.
 */
@Component({
  selector: 'app-kpi-tile',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="group rounded-card bg-cr-surface p-4 shadow-card ring-1 ring-cr-line/60 transition-shadow hover:shadow-panel">
      <p class="cr-meta">{{ kpi().label }}</p>
      <div class="mt-2 flex items-baseline gap-2">
        <span class="cr-num text-[28px] leading-none font-semibold">{{ kpi().value }}</span>
        @if (kpi().delta !== null) {
          <span
            class="cr-num inline-flex items-center gap-0.5 text-[12px] font-semibold"
            [class]="good() ? 'text-cr-success' : 'text-cr-red'"
            [attr.aria-label]="deltaLabel()">
            <span aria-hidden="true">{{ rising() ? '▲' : '▼' }}</span>
            {{ rising() ? '+' : '' }}{{ kpi().delta }}%
          </span>
        }
      </div>
      <p class="mt-1 text-[12px] text-cr-ink-muted">vs. previous 7 days</p>
    </div>
  `,
})
export class KpiTile {
  readonly kpi = input.required<Kpi>();

  protected readonly rising = computed(() => (this.kpi().delta ?? 0) >= 0);

  /** A rise is not automatically good — open incidents going up is bad. */
  protected readonly good = computed(() =>
    this.kpi().higher_is_better ? this.rising() : !this.rising(),
  );

  protected readonly deltaLabel = computed(() => {
    const d = this.kpi().delta ?? 0;
    return `${this.rising() ? 'up' : 'down'} ${Math.abs(d)} percent versus previous 7 days`;
  });
}

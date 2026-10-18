import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';

import { ChartTooltip } from './chart-tooltip';
import { fmt } from './format';

export interface TrendPoint {
  key: string;
  label: string;
  year: number;
  sales_cents: number;
  expenses_cents: number;
}

export function compactPesos(cents: number): string {
  const pesos = cents / 100;
  const abs = Math.abs(pesos);

  if (abs >= 1_000_000) return `₱${(pesos / 1_000_000).toFixed(abs >= 10_000_000 ? 0 : 1)}M`;
  if (abs >= 1_000) return `₱${(pesos / 1_000).toFixed(abs >= 10_000 ? 0 : 1)}K`;

  return `₱${Math.round(pesos)}`;
}

@Component({
  selector: 'app-income-trend',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ChartTooltip],
  template: `
    <div class="flex items-center gap-4 text-[12px] text-cr-ink-muted" aria-hidden="true">
      <span class="flex items-center gap-1.5">
        <span class="h-2.5 w-2.5 rounded-[2px] bg-cr-blue"></span> Income
      </span>
      <span class="flex items-center gap-1.5">
        <span class="h-2.5 w-2.5 rounded-[2px] bg-cr-warning"></span> Expenses
      </span>
    </div>

    <div class="relative mt-3 flex" role="img" [attr.aria-label]="summary()">
      <div class="cr-num flex w-12 flex-none flex-col justify-between pb-6 text-right text-[11px] text-cr-ink-muted" [style.height.px]="height() + 24">
        @for (tick of ticks(); track tick) {
          <span class="-translate-y-1/2 pr-2 leading-none">{{ compact(tick) }}</span>
        }
      </div>

      <div class="relative min-w-0 flex-1">
        <div class="pointer-events-none absolute inset-x-0 top-0 flex flex-col justify-between" [style.height.px]="height()">
          @for (tick of ticks(); track tick) {
            <span class="block border-t border-cr-line/70"></span>
          }
        </div>

        <div class="relative flex items-end gap-1" [style.height.px]="height()">
          @for (p of points(); track p.key) {
            <div
              class="relative flex h-full flex-1 cursor-default items-end justify-center gap-[2px] rounded-[4px] outline-none focus-visible:ring-2 focus-visible:ring-cr-blue"
              [class.bg-cr-tint]="active() === p.key"
              tabindex="0"
              (mouseenter)="active.set(p.key)"
              (mouseleave)="active.set(null)"
              (focus)="active.set(p.key)"
              (blur)="active.set(null)"
            >
              <span
                class="w-[38%] max-w-[14px] rounded-t-[4px] bg-cr-blue"
                [style.height.%]="pct(p.sales_cents)"
              ></span>
              <span
                class="w-[38%] max-w-[14px] rounded-t-[4px] bg-cr-warning"
                [style.height.%]="pct(p.expenses_cents)"
              ></span>

              @if (active() === p.key) {
                <app-chart-tooltip
                  [title]="p.label + ' ' + p.year"
                  [rows]="[
                    { label: 'Income', value: money(p.sales_cents) },
                    { label: 'Expenses', value: money(p.expenses_cents) },
                    { label: 'Net', value: money(p.sales_cents - p.expenses_cents), negative: p.sales_cents < p.expenses_cents },
                  ]"
                />
              }
            </div>
          }
        </div>

        <div class="mt-2 flex gap-1">
          @for (p of points(); track p.key) {
            <span class="flex-1 text-center text-[11px] text-cr-ink-muted">{{ p.label }}</span>
          }
        </div>
      </div>
    </div>
  `,
})
export class IncomeTrend {
  readonly points = input.required<TrendPoint[]>();
  readonly height = input(180);

  protected readonly active = signal<string | null>(null);
  protected readonly compact = compactPesos;

  private readonly top = computed(() => {
    const max = Math.max(1, ...this.points().flatMap((p) => [p.sales_cents, p.expenses_cents]));
    const step = this.niceStep(max / 4);

    return step * Math.ceil(max / step);
  });

  protected readonly ticks = computed(() => {
    const top = this.top();

    return [top, (top * 3) / 4, top / 2, top / 4, 0];
  });

  protected readonly summary = computed(() => {
    const pts = this.points();
    const sales = pts.reduce((s, p) => s + p.sales_cents, 0);
    const spent = pts.reduce((s, p) => s + p.expenses_cents, 0);

    return `Income and expenses over ${pts.length} months: income ${fmt.pesos(sales)}, expenses ${fmt.pesos(spent)}.`;
  });

  protected pct(cents: number): number {
    return Math.max(0, (cents / this.top()) * 100);
  }

  protected money(cents: number): string {
    return fmt.pesos(cents);
  }

  private niceStep(raw: number): number {
    const power = 10 ** Math.floor(Math.log10(Math.max(raw, 1)));
    const unit = raw / power;
    const nice = unit <= 1 ? 1 : unit <= 2 ? 2 : unit <= 5 ? 5 : 10;

    return nice * power;
  }
}

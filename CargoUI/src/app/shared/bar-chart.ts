import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';

import { ChartTooltip, TooltipRow } from './chart-tooltip';

export interface Bar {
  key: string;
  label: string;
  value: number;
  rows?: TooltipRow[];
  highlight?: boolean;
}

@Component({
  selector: 'app-bar-chart',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ChartTooltip],
  template: `
    <div class="flex items-end gap-2" role="group" [attr.aria-label]="summary()">
      @for (bar of bars(); track bar.key) {
        <div class="relative flex min-w-0 flex-1 flex-col items-center gap-1.5">
          @if (active() === bar.key) {
            <app-chart-tooltip [title]="bar.label" [rows]="rowsFor(bar)" />
          }

          <button
            type="button"
            class="flex w-full flex-col items-center gap-1.5 rounded-t-[4px] focus:outline-none
                   focus-visible:ring-2 focus-visible:ring-cr-blue focus-visible:ring-offset-2"
            [attr.aria-label]="spoken(bar)"
            (mouseenter)="active.set(bar.key)"
            (mouseleave)="active.set(null)"
            (focus)="active.set(bar.key)"
            (blur)="active.set(null)">
            <span
              class="flex w-full items-end justify-center"
              [style.height.px]="height()">
              <span
                class="w-full rounded-t-[4px] transition-all duration-300"
                [class]="bar.highlight || active() === bar.key ? 'bg-cr-blue' : 'bg-cr-blue/35'"
                [style.height.px]="barHeight(bar.value)"></span>
            </span>
            <span
              class="truncate text-[11px]"
              [class.text-cr-ink]="active() === bar.key"
              [class.text-cr-ink-muted]="active() !== bar.key">
              {{ bar.label }}
            </span>
          </button>
        </div>
      }
    </div>
  `,
})
export class BarChart {
  readonly bars = input.required<Bar[]>();

  readonly height = input(96);

  readonly format = input<(value: number) => string>((v) => String(v));

  readonly unit = input('');

  protected readonly active = signal<string | null>(null);

  protected barHeight(value: number): number {
    const peak = this.peak();
    return Math.max(3, Math.round((value / peak) * this.height()));
  }

  private readonly peak = computed(() => Math.max(1, ...this.bars().map((b) => b.value)));

  protected rowsFor(bar: Bar): TooltipRow[] {
    return bar.rows ?? [{ label: this.unit() || 'Value', value: this.format()(bar.value) }];
  }

  protected spoken(bar: Bar): string {
    const rows = bar.rows;

    if (rows) {
      return `${bar.label}: ${rows.map((r) => `${r.label} ${r.value}`).join(', ')}`;
    }

    return `${bar.label}: ${this.format()(bar.value)} ${this.unit()}`.trim();
  }

  protected readonly summary = computed(() => {
    const bars = this.bars();
    if (bars.length === 0) return '';

    const top = bars.reduce((best, b) => (b.value > best.value ? b : best));

    return `${bars.length} columns. Highest is ${top.label} at ${this.format()(top.value)} ${this.unit()}`.trim();
  });
}

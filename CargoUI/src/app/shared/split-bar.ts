import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';

import { ChartTooltip } from './chart-tooltip';

/** One part of a whole — a segment of the bar and a row of its legend. */
export interface SplitPart {
  key: string;
  label: string;
  /** Integer centavos. Zero parts are dropped. */
  cents: number;
  /** Already formatted, e.g. "₱5,772.36". */
  value: string;
}

/**
 * What a total is made of: one stacked bar, and a legend that says it in words.
 *
 * Colours by position, in a fixed order — the brand blue, then the charting
 * palette's orange and aqua, validated together for colour-blind separation.
 * The legend carries every label and amount, so no segment relies on its
 * colour alone (the aqua is under 3:1 on white, which is why that matters).
 * A 2px gap separates segments; each is a button with the shared tooltip, so
 * hover and keyboard read the same.
 */
export const SPLIT_COLORS = ['#15589c', '#eb6834', '#1baf7a'] as const;

@Component({
  selector: 'app-split-bar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ChartTooltip],
  template: `
    @if (shown().length > 0) {
      <div class="mt-3 flex h-2.5 w-full gap-0.5" role="group" [attr.aria-label]="summary()">
        @for (p of shown(); track p.key; let i = $index; let first = $first; let last = $last) {
          <div class="relative h-full" [style.width.%]="p.pct">
            <button
              type="button"
              class="block h-full w-full focus:outline-none focus-visible:ring-2 focus-visible:ring-cr-blue focus-visible:ring-offset-2"
              [class.rounded-l-full]="first"
              [class.rounded-r-full]="last"
              [style.background-color]="color(i)"
              [style.opacity]="active() === null || active() === p.key ? 1 : 0.35"
              [attr.aria-label]="p.label + ' ' + p.value + ', ' + p.pctLabel"
              (mouseenter)="active.set(p.key)"
              (mouseleave)="active.set(null)"
              (focus)="active.set(p.key)"
              (blur)="active.set(null)"></button>
            @if (active() === p.key) {
              <app-chart-tooltip [title]="p.label" [rows]="[{ label: p.pctLabel, value: p.value }]" />
            }
          </div>
        }
      </div>

      <ul class="mt-2.5 space-y-1 text-[12px]">
        @for (p of shown(); track p.key; let i = $index) {
          <li class="flex items-center gap-2">
            <span class="h-2 w-2 flex-none rounded-full" [style.background-color]="color(i)"></span>
            <span class="min-w-0 flex-1 truncate text-cr-ink-muted">{{ p.label }}</span>
            <span class="cr-num font-medium text-cr-ink">{{ p.value }}</span>
          </li>
        }
      </ul>
    }
  `,
})
export class SplitBar {
  readonly parts = input.required<SplitPart[]>();

  protected readonly active = signal<string | null>(null);

  protected readonly shown = computed(() => {
    const parts = this.parts().filter((p) => p.cents > 0);
    const total = parts.reduce((sum, p) => sum + p.cents, 0);

    return parts.map((p) => {
      const pct = total === 0 ? 0 : (p.cents / total) * 100;

      return { ...p, pct, pctLabel: `${pct < 1 && pct > 0 ? '<1' : Math.round(pct)}% of total` };
    });
  });

  protected readonly summary = computed(() =>
    this.shown()
      .map((p) => `${p.label} ${p.value}`)
      .join(', '),
  );

  protected color(index: number): string {
    return SPLIT_COLORS[index % SPLIT_COLORS.length];
  }
}

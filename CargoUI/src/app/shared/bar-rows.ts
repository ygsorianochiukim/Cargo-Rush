import { ChangeDetectionStrategy, Component, input, signal } from '@angular/core';

import { ChartTooltip, TooltipRow } from './chart-tooltip';

export interface BarRow {
  key: string;
  /** Printed on the left — a plate, or the unit label when there is no plate. */
  label: string;
  /** The headline figure, already formatted. The API never formats money. */
  value: string;
  /** Fill, 0–100. For a diverging row this is the distance from the centre. */
  pct: number;
  tone: 'income' | 'expense' | 'net';
  /** A loss. Only meaningful on a `net` row. */
  negative?: boolean;
  rows?: TooltipRow[];
}

/**
 * A ranked list of horizontal bars — the shape the workbook uses for
 * per-unit income, expenses and net income.
 *
 * The `net` tone is **diverging**: the axis is the centre line, a profit runs
 * right and a loss runs left. That is the point of it. A loss drawn as a short
 * bar from the left edge reads as "did badly"; drawn left of the axis it reads
 * as "lost money", which is what actually happened.
 *
 * Each row is a `<button>` so hover and keyboard focus are the same code path
 * (DESIGN.md section 9).
 */
@Component({
  selector: 'app-bar-rows',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ChartTooltip],
  template: `
    <!-- Centred in the card body: cards in a row are all as tall as the
         tallest, so a chart with one or two units used to sit pinned to the
         top of a mostly empty box. With a full set of units the box is full
         and centring makes no difference. -->
    <ul class="flex h-full flex-col justify-center gap-1">
      @for (bar of bars(); track bar.key) {
        <li class="relative">
          <button
            type="button"
            class="w-full rounded-control px-1.5 py-1.5 text-left transition-colors
                   hover:bg-cr-tint focus:outline-none focus-visible:ring-2
                   focus-visible:ring-cr-blue focus-visible:ring-offset-2"
            [attr.aria-label]="spoken(bar)"
            (mouseenter)="active.set(bar.key)"
            (mouseleave)="active.set(null)"
            (focus)="active.set(bar.key)"
            (blur)="active.set(null)">
            <span class="flex items-baseline justify-between gap-2">
              <span class="cr-num text-[13px] font-semibold">{{ bar.label }}</span>
              <span
                class="cr-num text-[13px]"
                [class.font-semibold]="bar.tone === 'net'"
                [class.text-cr-red]="bar.tone === 'net' && bar.negative"
                [class.text-cr-success]="bar.tone === 'net' && !bar.negative && bar.pct > 0">
                {{ bar.value }}
              </span>
            </span>

            @if (bar.tone === 'net') {
              <span class="relative mt-1.5 block h-2.5 w-full rounded-full bg-cr-line/70">
                <span class="absolute inset-y-0 left-1/2 w-px bg-cr-ink-muted/40"></span>
                @if (bar.negative) {
                  <span
                    class="absolute inset-y-0 right-1/2 rounded-l-full bg-cr-red transition-all duration-300"
                    [style.width.%]="bar.pct"></span>
                } @else {
                  <span
                    class="absolute inset-y-0 left-1/2 rounded-r-full bg-cr-success transition-all duration-300"
                    [style.width.%]="bar.pct"></span>
                }
              </span>
            } @else {
              <span
                class="mt-1.5 block h-2.5 w-full overflow-hidden rounded-full bg-cr-line/70">
                <span
                  class="block h-full rounded-full transition-all duration-300"
                  [class.bg-cr-blue]="bar.tone === 'income'"
                  [class.bg-cr-warning]="bar.tone === 'expense'"
                  [style.width.%]="bar.pct"></span>
              </span>
            }
          </button>

          @if (active() === bar.key) {
            <app-chart-tooltip [title]="bar.label" [rows]="bar.rows ?? []" />
          }
        </li>
      }
    </ul>
  `,
})
export class BarRows {
  readonly bars = input.required<BarRow[]>();

  protected readonly active = signal<string | null>(null);

  protected spoken(bar: BarRow): string {
    const extra = (bar.rows ?? []).map((r) => `${r.label} ${r.value}`).join(', ');

    return `${bar.label}, ${bar.value}${extra ? `. ${extra}` : ''}`;
  }
}

import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';

import { ChartTooltip } from './chart-tooltip';

export interface DonutSlice {
  key: string;
  label: string;
  /** Signed value. Negative slices take arc length from their magnitude. */
  value: number;
  /** Optional formatted amount for the tooltip, e.g. "₱117,714.07". */
  detail?: string;
}

interface Arc extends DonutSlice {
  share: number;
  length: number;
  offset: number;
  negative: boolean;
  /** One brand hue stepped by opacity — no new colours enter the palette. */
  opacity: number;
}

const RADIUS = 54;
const CIRC = 2 * Math.PI * RADIUS;
/** Sequential steps of --cr-blue, darkest first. */
const STEPS = [1, 0.78, 0.6, 0.45, 0.33, 0.24, 0.17, 0.12];

/**
 * The workbook's "% OF NET INCOME" doughnut.
 *
 * Arc length uses each slice's magnitude — the same thing Excel does with a
 * negative value in a pie — but a loss keeps its minus sign in the legend and
 * is drawn in `--cr-red`, so it can never read as a contribution.
 *
 * **Interaction.** Hovering or focusing a legend row lifts the matching arc
 * and shows its figures. The legend is the interactive surface, not the ring:
 * a legend row is a proper hit target at any size, it is already in reading
 * order for a keyboard, and a 6°-wide arc is neither.
 */
@Component({
  selector: 'app-donut',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ChartTooltip],
  template: `
    <!-- Stacked, not side by side: the card is one column of a four-up grid,
         and a legend squeezed beside the ring truncates the plate numbers. -->
    <div class="flex flex-col items-center gap-4">
      <svg
        viewBox="0 0 140 140"
        class="h-[124px] w-[124px] flex-none -rotate-90"
        role="img"
        [attr.aria-label]="summary()">
        <circle cx="70" cy="70" [attr.r]="radius" fill="none" stroke="#E5E7EB" stroke-width="18" />
        @for (a of arcs(); track a.key) {
          <circle
            cx="70"
            cy="70"
            [attr.r]="radius"
            fill="none"
            [attr.stroke]="a.negative ? '#A11807' : '#15589C'"
            [attr.stroke-opacity]="dimmed(a) ? 0.25 : a.negative ? 1 : a.opacity"
            [attr.stroke-width]="active() === a.key ? 24 : 18"
            [attr.stroke-dasharray]="a.length + ' ' + (circumference - a.length)"
            [attr.stroke-dashoffset]="-a.offset"
            class="transition-all duration-150" />
        }
      </svg>

      <ul class="w-full min-w-0 space-y-1">
        @for (a of arcs(); track a.key) {
          <li class="relative">
            <button
              type="button"
              class="flex w-full items-center gap-2.5 rounded-control px-1.5 py-1 text-left
                     transition-colors hover:bg-cr-tint focus:outline-none
                     focus-visible:ring-2 focus-visible:ring-cr-blue focus-visible:ring-offset-2"
              [attr.aria-label]="spoken(a)"
              (mouseenter)="active.set(a.key)"
              (mouseleave)="active.set(null)"
              (focus)="active.set(a.key)"
              (blur)="active.set(null)">
              <span
                class="h-2.5 w-2.5 flex-none rounded-full"
                [style.background-color]="a.negative ? '#A11807' : '#15589C'"
                [style.opacity]="a.negative ? 1 : a.opacity"></span>
              <span class="cr-num min-w-0 flex-1 truncate text-[13px]">{{ a.label }}</span>
              <span class="cr-num text-[13px] font-semibold" [class.text-cr-red]="a.negative">
                {{ (a.share * 100).toFixed(1) }}%
              </span>
            </button>

            @if (active() === a.key) {
              <app-chart-tooltip [title]="a.label" [rows]="rowsFor(a)" />
            }
          </li>
        }
      </ul>
    </div>
  `,
})
export class Donut {
  readonly slices = input.required<DonutSlice[]>();

  protected readonly radius = RADIUS;
  protected readonly circumference = CIRC;

  /** The slice under the pointer or holding focus. Null means none. */
  protected readonly active = signal<string | null>(null);

  protected readonly arcs = computed<Arc[]>(() => {
    const slices = this.slices().filter((s) => s.value !== 0);
    const signedTotal = slices.reduce((t, s) => t + s.value, 0);
    const magnitude = slices.reduce((t, s) => t + Math.abs(s.value), 0);
    if (magnitude === 0) return [];

    let offset = 0;

    return slices.map((s, i) => {
      const length = (Math.abs(s.value) / magnitude) * CIRC;
      const arc: Arc = {
        ...s,
        // Share keeps its sign and divides by the signed total, exactly as the
        // workbook's "% OF NET INCOME" column does.
        share: signedTotal === 0 ? 0 : s.value / signedTotal,
        length,
        offset,
        negative: s.value < 0,
        opacity: STEPS[i % STEPS.length],
      };
      offset += length;

      return arc;
    });
  });

  /** Everything except the active arc fades, so the one in question stands out. */
  protected dimmed(arc: Arc): boolean {
    const active = this.active();

    return active !== null && active !== arc.key;
  }

  protected rowsFor(arc: Arc) {
    return [
      { label: 'Share', value: `${(arc.share * 100).toFixed(1)}%`, negative: arc.negative },
      ...(arc.detail ? [{ label: 'Net', value: arc.detail, negative: arc.negative }] : []),
    ];
  }

  /** What a screen reader hears on the legend row itself. */
  protected spoken(arc: Arc): string {
    const detail = arc.detail ? `, ${arc.detail}` : '';

    return `${arc.label}, ${(arc.share * 100).toFixed(1)} percent of net income${detail}`;
  }

  protected readonly summary = computed(() =>
    this.arcs()
      .map((a) => `${a.label} ${(a.share * 100).toFixed(1)} percent`)
      .join(', '),
  );
}

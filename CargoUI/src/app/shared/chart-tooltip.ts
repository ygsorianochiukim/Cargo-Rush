import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

/** One line of a tooltip: a label and the value against it. */
export interface TooltipRow {
  label: string;
  value: string;
  /** Renders the value in red — used for a loss. */
  negative?: boolean;
}

/**
 * The tooltip every chart uses.
 *
 * Positioned by the marker it belongs to rather than by the pointer, so it
 * appears in the same place whether it was opened by hover or by keyboard —
 * a tooltip that only tracks the mouse is invisible to a keyboard user, which
 * is the whole point of DESIGN.md section 9.
 *
 * `aria-hidden` because the accessible name already carries this text: a
 * screen reader reads the marker's own label and would otherwise hear it twice.
 */
@Component({
  selector: 'app-chart-tooltip',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 -translate-x-1/2
             rounded-control bg-cr-shell px-2.5 py-1.5 shadow-panel"
      aria-hidden="true">
      <p class="text-[11px] font-semibold whitespace-nowrap text-cr-surface">{{ title() }}</p>
      @for (row of rows(); track row.label) {
        <p class="mt-0.5 flex items-center gap-2 text-[11px] whitespace-nowrap">
          <span class="text-cr-surface/70">{{ row.label }}</span>
          <span class="cr-num ml-auto font-semibold" [class.text-cr-surface]="!row.negative">
            <span [class.text-cr-red-bg]="row.negative">{{ row.value }}</span>
          </span>
        </p>
      }
      <!-- The pointer, drawn as a rotated square so it inherits the same colour. -->
      <span
        class="absolute top-full left-1/2 -mt-1 h-2 w-2 -translate-x-1/2 rotate-45 bg-cr-shell"></span>
    </div>
  `,
})
export class ChartTooltip {
  readonly title = input.required<string>();
  readonly rows = input<TooltipRow[]>([]);

  /** The same text a screen reader gets, so the two can never disagree. */
  readonly spoken = computed(
    () => `${this.title()}. ${this.rows().map((r) => `${r.label} ${r.value}`).join(', ')}`,
  );
}

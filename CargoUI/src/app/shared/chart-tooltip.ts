import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

export interface TooltipRow {
  label: string;
  value: string;
  negative?: boolean;
}

@Component({
  selector: 'app-chart-tooltip',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'contents' },
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
      <span
        class="absolute top-full left-1/2 -mt-1 h-2 w-2 -translate-x-1/2 rotate-45 bg-cr-shell"></span>
    </div>
  `,
})
export class ChartTooltip {
  readonly title = input.required<string>();
  readonly rows = input<TooltipRow[]>([]);

  readonly spoken = computed(
    () => `${this.title()}. ${this.rows().map((r) => `${r.label} ${r.value}`).join(', ')}`,
  );
}

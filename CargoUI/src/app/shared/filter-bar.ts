import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';

export interface FilterOption {
  value: string;
  label: string;
  count: number;
}

/** Filters sit in one row above the content (DESIGN.md section 8 / dataviz interaction). */
@Component({
  selector: 'app-filter-bar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="flex flex-wrap items-center gap-2" role="group" [attr.aria-label]="label()">
      @for (f of options(); track f.value) {
        <button
          type="button"
          (click)="select.emit(f.value)"
          [attr.aria-pressed]="selected() === f.value"
          class="flex h-9 items-center gap-2 rounded-control px-3 text-[13px] font-medium transition-colors"
          [class.bg-cr-blue]="selected() === f.value"
          [class.text-cr-surface]="selected() === f.value"
          [class.bg-cr-surface]="selected() !== f.value"
          [class.text-cr-ink]="selected() !== f.value">
          {{ f.label }}
          <span
            class="cr-num rounded-full px-1.5 text-[11px] font-semibold"
            [class.bg-cr-surface]="selected() === f.value"
            [class.text-cr-blue]="selected() === f.value"
            [class.bg-cr-tint]="selected() !== f.value"
            [class.text-cr-ink-muted]="selected() !== f.value">
            {{ f.count }}
          </span>
        </button>
      }
    </div>
  `,
})
export class FilterBar {
  readonly options = input.required<FilterOption[]>();
  readonly selected = input.required<string>();
  readonly label = input('Filter by status');
  readonly select = output<string>();
}

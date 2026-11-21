import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';

import { Icon } from './icon';

const PAGE_SIZES = [5, 10, 15, 20, 50];

@Component({
  selector: 'app-table-pager',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  template: `
    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-cr-line px-4 py-3">
      <label class="flex items-center gap-2 text-[12px] text-cr-ink-muted">
        <span>Rows per page</span>
        <select
          [value]="size()"
          (change)="sizeChange.emit(+$any($event.target).value)"
          class="h-8 rounded-control border border-cr-line bg-cr-surface px-2 text-[13px] text-cr-ink focus:border-cr-blue focus:outline-none"
        >
          @for (option of sizes(); track option) {
            <option [value]="option" [selected]="option === size()">{{ option }}</option>
          }
        </select>
      </label>
      <p class="cr-num text-[12px] text-cr-ink-muted" aria-live="polite">
        {{ total() ? index() * size() + 1 : 0 }}–{{ last() }} of {{ total() }}
      </p>
      <div class="flex items-center gap-1" role="group" aria-label="Table pages">
        <button
          type="button"
          class="flex h-8 w-8 items-center justify-center rounded-control text-cr-ink-muted hover:bg-cr-tint disabled:opacity-40"
          aria-label="Previous page"
          [disabled]="index() === 0"
          (click)="indexChange.emit(index() - 1)"
        >
          <app-icon name="chevron-left" [size]="16" />
        </button>
        <span class="cr-num px-1 text-[12px] text-cr-ink-muted">{{ index() + 1 }} / {{ count() }}</span>
        <button
          type="button"
          class="flex h-8 w-8 items-center justify-center rounded-control text-cr-ink-muted hover:bg-cr-tint disabled:opacity-40"
          aria-label="Next page"
          [disabled]="index() + 1 >= count()"
          (click)="indexChange.emit(index() + 1)"
        >
          <app-icon name="chevron-right" [size]="16" />
        </button>
      </div>
    </div>
  `,
})
export class TablePager {
  readonly total = input.required<number>();
  readonly index = input.required<number>();
  readonly size = input.required<number>();
  readonly indexChange = output<number>();
  readonly sizeChange = output<number>();

  protected readonly sizes = computed(() => [...new Set([...PAGE_SIZES, this.size()])].sort((a, b) => a - b));
  protected readonly count = computed(() => Math.max(1, Math.ceil(this.total() / this.size())));
  protected readonly last = computed(() => Math.min(this.total(), (this.index() + 1) * this.size()));
}

import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';

import { Icon } from './icon';

/** Skeleton rows — DESIGN.md section 8: no spinners for full-panel loads. */
@Component({
  selector: 'app-skeleton-rows',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="flex flex-col gap-3 p-4" role="status" aria-label="Loading">
      @for (row of rows(); track $index) {
        <div class="flex items-center gap-3">
          <div class="cr-skeleton h-8 w-8 rounded-full"></div>
          <div class="cr-skeleton h-3 flex-1" [style.max-width.%]="widths[$index % 4]"></div>
          <div class="cr-skeleton h-3 w-16"></div>
        </div>
      }
    </div>
  `,
})
export class SkeletonRows {
  readonly count = input(4);
  protected readonly widths = [70, 55, 80, 62];
  protected rows() {
    return Array.from({ length: this.count() });
  }
}

/** Empty state — DESIGN.md section 8. */
@Component({
  selector: 'app-empty-state',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  template: `
    <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center">
      <app-icon [name]="icon()" [size]="32" class="text-cr-ink-muted" />
      <h3 class="mt-1 text-[16px] font-semibold">{{ title() }}</h3>
      <p class="max-w-sm text-[14px] text-cr-ink-muted">{{ body() }}</p>
      <ng-content />
    </div>
  `,
})
export class EmptyState {
  readonly icon = input('shipments');
  readonly title = input('Nothing here yet');
  readonly body = input('When there is data to show, it will appear here.');
}

/** Error state — the fourth required list state. */
@Component({
  selector: 'app-error-state',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  template: `
    <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center" role="alert">
      <app-icon name="bell" [size]="32" class="text-cr-red" />
      <h3 class="mt-1 text-[16px] font-semibold">Could not load this panel</h3>
      <p class="max-w-sm text-[14px] text-cr-ink-muted">{{ message() }}</p>
      <button
        type="button"
        class="mt-2 h-10 rounded-control border border-cr-blue px-4 text-[14px] font-semibold text-cr-blue transition-colors hover:bg-cr-tint"
        (click)="retry.emit()">
        Try again
      </button>
    </div>
  `,
})
export class ErrorState {
  readonly message = input('The request failed. Check your connection and try again.');
  readonly retry = output<void>();
}


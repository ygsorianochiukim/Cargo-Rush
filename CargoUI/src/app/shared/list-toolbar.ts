import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';

import { Icon } from './icon';

/**
 * The row above a list: what is in it, and the one action that adds to it.
 *
 * DESIGN.md section 4 puts at most one primary and one secondary action in the
 * canvas header. This is the per-module equivalent, and it exists once so the
 * count wording and the button placement do not drift across eight pages.
 */
@Component({
  selector: 'app-list-toolbar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  template: `
    <div class="mb-4 flex items-center justify-between gap-3">
      <p class="text-[13px] text-cr-ink-muted">
        @if (count() !== null) {
          <span class="cr-num font-semibold text-cr-ink">{{ count() }}</span>
          {{ count() === 1 ? singular() : plural() }}
        }
      </p>

      <button
        type="button"
        class="flex h-10 flex-none items-center gap-1.5 rounded-control bg-cr-blue px-4
               text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover"
        (click)="add.emit()">
        <app-icon name="plus" [size]="16" />
        {{ actionLabel() }}
      </button>
    </div>
  `,
})
export class ListToolbar {
  /** Null while loading, so the count does not flash a zero first. */
  readonly count = input<number | null>(null);
  readonly singular = input.required<string>();
  readonly plural = input.required<string>();
  readonly actionLabel = input.required<string>();

  readonly add = output<void>();
}

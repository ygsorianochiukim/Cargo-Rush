import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';

import { StatusValue } from '../models/shared/status.model';
import { Icon } from './icon';
import { SkeletonRows } from './states';
import { StatusPill } from './status-pill';

/**
 * Column definition for `app-data-table`.
 * `value` keeps the accessor in TypeScript rather than in template string keys,
 * so a renamed field is a compile error instead of a blank cell.
 */
export interface Column<T = any> {
  label: string;
  /** `num` right-aligns and uses tabular figures; `status` renders a StatusPill. */
  kind?: 'text' | 'num' | 'strong' | 'muted' | 'status';
  /**
   * Null is a real answer, not a mistake: a trip may have no helper and a
   * delivery may have no proof yet. The table prints an em dash for it, so
   * no page has to spell out its own placeholder.
   */
  value?: (row: T) => string | number | null;
  status?: (row: T) => StatusValue;
  /** Second line under the main value. */
  sub?: (row: T) => string | null;
}

/**
 * The shared table used by every list module (DESIGN.md section 8). Handles all
 * four required states so no page has to reimplement them.
 */
@Component({
  selector: 'app-data-table',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, StatusPill, SkeletonRows],
  template: `
    @if (rows(); as list) {
      @if (list.length === 0) {
        <div class="flex flex-col items-center gap-2 px-6 py-12 text-center">
          <app-icon [name]="emptyIcon()" [size]="32" class="text-cr-ink-muted" />
          <h3 class="mt-1 text-[16px] font-semibold">{{ emptyTitle() }}</h3>
          <p class="max-w-sm text-[14px] text-cr-ink-muted">{{ emptyBody() }}</p>
        </div>
      } @else {
        <div class="overflow-x-auto">
          <!-- Nowrap so a narrow window scrolls the table rather than wrapping
               every heading and cell onto two lines. Set on the table because
               white-space inherits, which keeps it off every single cell. -->
          <table
            class="w-full border-collapse text-left whitespace-nowrap"
            [style.min-width.px]="minWidth()">
            <thead>
              <tr class="border-b border-cr-line">
                @for (col of columns(); track col.label) {
                  <th class="cr-th px-4 py-2.5" [class.text-right]="col.kind === 'num'">
                    {{ col.label }}
                  </th>
                }
                @if (rowAction()) {
                  <th class="cr-th px-4 py-2.5"><span class="sr-only">Open</span></th>
                }
              </tr>
            </thead>
            <tbody>
              @for (row of list; track $index) {
                <tr
                  class="border-b border-cr-line/70 transition-colors last:border-0 hover:bg-cr-tint">
                  @for (col of columns(); track col.label) {
                    <td
                      class="px-4 py-3 text-[13px]"
                      [class.text-right]="col.kind === 'num'"
                      [class.cr-num]="col.kind === 'num' || col.kind === 'strong'"
                      [class.font-semibold]="col.kind === 'strong'"
                      [class.text-cr-ink-muted]="col.kind === 'muted'">
                      @if (col.kind === 'status' && col.status) {
                        <app-status-pill [status]="col.status(row)" />
                      } @else {
                        {{ col.value ? (col.value(row) ?? dash) : '' }}
                        @if (col.sub) {
                          <span class="block text-[12px] text-cr-ink-muted">{{
                            col.sub(row) ?? dash
                          }}</span>
                        }
                      }
                    </td>
                  }
                  @if (rowAction()) {
                    <td class="px-4 py-3">
                      <div class="flex items-center justify-end gap-1">
                        @if (deletable()) {
                          <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-control text-cr-ink-muted transition-colors hover:bg-cr-red-bg hover:text-cr-red"
                            [attr.aria-label]="'Delete ' + (rowLabel() ? rowLabel()!(row) : 'row')"
                            (click)="remove.emit(row)">
                            <app-icon name="close" [size]="16" />
                          </button>
                        }
                        <button
                          type="button"
                          class="flex h-8 w-8 items-center justify-center rounded-control text-cr-ink-muted transition-colors hover:bg-cr-tint hover:text-cr-blue"
                          [attr.aria-label]="rowAction()"
                          (click)="open.emit(row)">
                          <app-icon name="chevron-right" [size]="16" />
                        </button>
                      </div>
                    </td>
                  }
                </tr>
              }
            </tbody>
          </table>
        </div>
      }
    } @else {
      <app-skeleton-rows [count]="skeletonCount()" />
    }
  `,
})
export class DataTable {
  readonly columns = input.required<Column[]>();
  /** `null` means still loading. */
  readonly rows = input.required<any[] | null>();
  readonly minWidth = input(720);
  readonly skeletonCount = input(6);
  readonly rowAction = input<string | null>(null);
  readonly emptyIcon = input('shipments');
  readonly emptyTitle = input('Nothing here yet');
  readonly emptyBody = input('When there is data to show, it will appear here.');

  /** Shows a destructive action next to the row action. */
  readonly deletable = input(false);
  /** Names the row for the delete button's accessible label. */
  readonly rowLabel = input<((row: any) => string) | null>(null);

  readonly open = output<any>();
  readonly remove = output<any>();

  /** What an absent value prints as, in one place rather than per column. */
  protected readonly dash = '—';
}

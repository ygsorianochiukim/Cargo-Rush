import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  output,
  signal,
} from '@angular/core';

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
 * four required states so no page has to reimplement them — and, since every
 * one of those modules grows past a screenful eventually, the searching and
 * paging too.
 *
 * ## Both are done here, on rows already in hand
 *
 * No request is made and no parameter is sent. The lists this renders are a
 * fleet's own — a few hundred vehicles, a year of expenses — and they are
 * already loaded by the time a table exists to show them. Filtering them in
 * the browser answers instantly and keeps working offline; asking the server
 * to do it would add a round trip per keystroke to make a list of eighty rows
 * shorter.
 *
 * The day one of these lists really is too big to hold, the fix is a paged
 * endpoint behind the same inputs, and no page that uses this has to change.
 *
 * ## What it searches
 *
 * Whatever the columns print. A column already knows how to turn a row into
 * the text somebody is reading, so searching that text is searching exactly
 * what is on screen — no page has to name its searchable fields, and a column
 * added later is searchable the moment it renders. The sub-line counts too,
 * because a plate under a truck's name is the thing people type.
 */
@Component({
  selector: 'app-data-table',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, StatusPill, SkeletonRows],
  template: `
    @if (rows(); as loaded) {
      <!--
        The toolbar, and only when it earns its place.

        There was a threshold here — five rows before a search box appeared —
        and it was wrong. A module with four rows in it today has forty next
        quarter, and a control that only shows up once somebody already has a
        problem is a control they never learn is there. It appears whenever
        there is anything to search.
      -->
      @if (searchable() && loaded.length > 0) {
        <div class="flex flex-wrap items-center gap-2 px-4 py-3">
          <label class="relative min-w-[200px] flex-1">
            <span class="sr-only">Search this list</span>
            <app-icon
              name="search"
              [size]="15"
              class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-cr-ink-muted" />
            <input
              type="search"
              [value]="term()"
              (input)="setTerm($event)"
              [attr.placeholder]="searchPlaceholder()"
              class="h-9 w-full rounded-control border border-cr-line bg-cr-surface pr-3 pl-9 text-[13px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none" />
          </label>

          @if (term()) {
            <p class="cr-num text-[12px] text-cr-ink-muted">
              {{ matched().length }} of {{ loaded.length }}
            </p>
          }
        </div>
      }

      @if (matched().length === 0) {
        <!--
          Two different empties, and telling them apart is the point.

          "Nothing here yet" is a module nobody has used. "Nothing matched" is a
          list full of rows and a search that excluded them, and offering the
          first message for the second sends somebody off to add a record they
          already have.
        -->
        @if (term()) {
          <div class="flex flex-col items-center gap-2 px-6 py-12 text-center">
            <app-icon name="search" [size]="32" class="text-cr-ink-muted" />
            <h3 class="mt-1 text-[16px] font-semibold">Nothing matched “{{ term() }}”</h3>
            <p class="max-w-sm text-[14px] text-cr-ink-muted">
              {{ loaded.length }} rows here, none of them this. Check the spelling, or clear the
              search to see them all.
            </p>
            <button
              type="button"
              class="mt-1 h-9 rounded-control border border-cr-line px-3 text-[13px] font-semibold text-cr-ink transition-colors hover:bg-cr-tint"
              (click)="clear()">
              Clear search
            </button>
          </div>
        } @else {
          <div class="flex flex-col items-center gap-2 px-6 py-12 text-center">
            <app-icon [name]="emptyIcon()" [size]="32" class="text-cr-ink-muted" />
            <h3 class="mt-1 text-[16px] font-semibold">{{ emptyTitle() }}</h3>
            <p class="max-w-sm text-[14px] text-cr-ink-muted">{{ emptyBody() }}</p>
          </div>
        }
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
              @for (row of page(); track $index) {
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

        <!--
          The pager, and it hides itself on a list that fits.

          Shown whenever there are rows, for the reason the search box is. The
          count under a short list answers "is that all of them", which is worth
          having before the list is long rather than after — and the arrows
          disable on a single page rather than vanishing, so the control does
          not move about as a module fills up.
        -->
        @if (paginated() && matched().length > 0) {
          <div
            class="flex flex-wrap items-center justify-between gap-3 border-t border-cr-line px-4 py-3">
            <label class="flex items-center gap-2 text-[12px] text-cr-ink-muted">
              <span>Rows</span>
              <select
                [value]="size()"
                (change)="setSize($event)"
                class="h-8 rounded-control border border-cr-line bg-cr-surface px-2 text-[13px] text-cr-ink focus:border-cr-blue focus:outline-none">
                @for (option of sizes; track option) {
                  <option [value]="option">{{ option }}</option>
                }
              </select>
            </label>

            <p class="cr-num text-[12px] text-cr-ink-muted">
              {{ firstShown() }}–{{ lastShown() }} of {{ matched().length }}
            </p>

            <div class="flex items-center gap-1">
              <button
                type="button"
                class="flex h-8 w-8 items-center justify-center rounded-control text-cr-ink-muted transition-colors hover:bg-cr-tint disabled:opacity-40"
                aria-label="Previous page"
                [disabled]="pageIndex() === 0"
                (click)="back()">
                <app-icon name="chevron-left" [size]="16" />
              </button>
              <span class="cr-num px-1 text-[12px] text-cr-ink-muted">
                {{ pageIndex() + 1 }} / {{ pageCount() }}
              </span>
              <button
                type="button"
                class="flex h-8 w-8 items-center justify-center rounded-control text-cr-ink-muted transition-colors hover:bg-cr-tint disabled:opacity-40"
                aria-label="Next page"
                [disabled]="pageIndex() + 1 >= pageCount()"
                (click)="next()">
                <app-icon name="chevron-right" [size]="16" />
              </button>
            </div>
          </div>
        }
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

  /**
   * Whether this table searches and pages itself.
   *
   * On by default, because "most tables" was the honest answer to which ones
   * want it and an opt-in would have meant editing fifteen pages to get there.
   * A table that has its own filter bar above it can turn the search off and
   * keep the paging.
   */
  readonly searchable = input(true);
  readonly paginated = input(true);

  /** Named for what the rows are, so the box does not say "Search…" everywhere. */
  readonly searchPlaceholder = input('Search…');

  /**
   * Rows per page, and the choices offered.
   *
   * Ten to start with, and five, ten, fifteen or twenty on the control. Five
   * is the smallest page worth turning; past twenty a page is a scroll, which
   * is the thing paging exists to stop.
   */
  readonly pageSize = input(10);

  protected readonly sizes = [5, 10, 15, 20] as const;

  protected readonly term = signal('');
  protected readonly pageIndex = signal(0);
  private readonly chosenSize = signal<number | null>(null);

  /** The page size in force: whatever was picked, else what the page asked for. */
  protected readonly size = computed(() => this.chosenSize() ?? this.pageSize());

  /**
   * The rows the search left, matched against what the columns actually print.
   *
   * Every column's `value` and `sub` are rendered to text and joined, so the
   * haystack is the row as the reader sees it. A status column is skipped: it
   * renders a pill from an enum, and matching "act" against "inactive" would
   * surprise somebody more than it helps them.
   */
  protected readonly matched = computed(() => {
    const loaded = this.rows() ?? [];
    const needle = this.term().trim().toLowerCase();

    if (needle === '' || !this.searchable()) return loaded;

    return loaded.filter((row) => this.haystack(row).includes(needle));
  });

  protected readonly pageCount = computed(() =>
    Math.max(1, Math.ceil(this.matched().length / this.size())),
  );

  /** The slice on screen. The whole list when paging is off. */
  protected readonly page = computed(() => {
    if (!this.paginated()) return this.matched();

    const start = this.pageIndex() * this.size();

    return this.matched().slice(start, start + this.size());
  });

  protected readonly firstShown = computed(() =>
    this.matched().length === 0 ? 0 : this.pageIndex() * this.size() + 1,
  );

  protected readonly lastShown = computed(() =>
    Math.min(this.matched().length, (this.pageIndex() + 1) * this.size()),
  );

  constructor() {
    /**
     * Never strand the reader past the end of the list.
     *
     * Searching on page four of six, or deleting the last row of the last page,
     * leaves an index that no longer has rows under it — and an empty table
     * with "5 / 2" beneath it reads as a broken screen rather than as a stale
     * page number. Clamping is cheaper than remembering every way the list can
     * get shorter.
     */
    effect(() => {
      const last = this.pageCount() - 1;

      if (this.pageIndex() > last) this.pageIndex.set(Math.max(0, last));
    });
  }

  protected setTerm(event: Event): void {
    this.term.set((event.target as HTMLInputElement).value);
    // Back to the top: the matches are a new list, and page three of the old
    // one has nothing to do with page three of this one.
    this.pageIndex.set(0);
  }

  protected clear(): void {
    this.term.set('');
    this.pageIndex.set(0);
  }

  protected setSize(event: Event): void {
    this.chosenSize.set(Number((event.target as HTMLSelectElement).value));
    this.pageIndex.set(0);
  }

  protected back(): void {
    this.pageIndex.update((index) => Math.max(0, index - 1));
  }

  protected next(): void {
    this.pageIndex.update((index) => Math.min(this.pageCount() - 1, index + 1));
  }

  /** One row, as the text it renders to. */
  private haystack(row: any): string {
    return this.columns()
      .filter((col) => col.kind !== 'status')
      .map((col) => `${col.value?.(row) ?? ''} ${col.sub?.(row) ?? ''}`)
      .join(' ')
      .toLowerCase();
  }

  /** What an absent value prints as, in one place rather than per column. */
  protected readonly dash = '—';
}

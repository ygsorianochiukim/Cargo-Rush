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
import { statusLabel } from './status';
import { StatusPill } from './status-pill';
import { TablePager } from './table-pager';
import { matchesTableSearch, TableSearch } from './table-search';

export interface Column<T = any> {
  label: string;
  kind?: 'text' | 'num' | 'strong' | 'muted' | 'status';
  value?: (row: T) => string | number | null;
  status?: (row: T) => StatusValue;
  sub?: (row: T) => string | null;
  badge?: (row: T) => string | null;
  pill?: (row: T) => { label: string; on: boolean };
  date?: (row: T) => string | null | undefined;
}

export function dayOf(value: string | null | undefined): string | null {
  if (!value) return null;
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;

  const parsed = new Date(value);

  return Number.isNaN(parsed.getTime()) ? null : parsed.toLocaleDateString('en-CA');
}

@Component({
  selector: 'app-data-table',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, StatusPill, SkeletonRows, TablePager, TableSearch],
  template: `
    @if (rows(); as loaded) {
      @if ((searchable() || dated()) && loaded.length > 0) {
        <div class="flex flex-wrap items-center gap-3 px-4 py-3">
          @if (searchable()) {
            <app-table-search
              class="min-w-0 flex-[1_1_200px]"
              [class.max-w-md]="compactFilters()"
              [value]="term()"
              [placeholder]="searchPlaceholder()"
              (valueChange)="setTerm($event)"
            />
          }

          @if (dated()) {
            <div class="flex flex-wrap items-center gap-1.5" role="group" [attr.aria-label]="dateLabel()">
              <label class="flex items-center gap-1.5 text-[12px] text-cr-ink-muted">
                <span>From</span>
                <input
                  type="date"
                  [value]="from()"
                  [max]="to() || null"
                  (change)="setFrom($event)"
                  class="h-9 rounded-control border border-cr-line bg-cr-surface px-2 text-[13px] text-cr-ink focus:border-cr-blue focus:outline-none"
                />
              </label>
              <label class="flex items-center gap-1.5 text-[12px] text-cr-ink-muted">
                <span>To</span>
                <input
                  type="date"
                  [value]="to()"
                  [min]="from() || null"
                  (change)="setTo($event)"
                  class="h-9 rounded-control border border-cr-line bg-cr-surface px-2 text-[13px] text-cr-ink focus:border-cr-blue focus:outline-none"
                />
              </label>
              @if (ranged()) {
                <button
                  type="button"
                  class="h-9 rounded-control px-2 text-[12px] font-semibold text-cr-blue transition-colors hover:bg-cr-tint"
                  (click)="clearDates()"
                >
                  All dates
                </button>
              }
            </div>
          }

          @if (term() || ranged()) {
            <p class="cr-num text-[12px] text-cr-ink-muted">
              {{ matched().length }} of {{ loaded.length }}
            </p>
          }
        </div>
      }

      @if (matched().length === 0) {
        @if (term() || ranged()) {
          <div class="flex flex-col items-center gap-2 px-6 py-12 text-center">
            <app-icon name="search" [size]="32" class="text-cr-ink-muted" />
            <h3 class="mt-1 text-[16px] font-semibold">
              @if (term()) {
                Nothing matched “{{ term() }}”
              } @else {
                Nothing in this date range
              }
            </h3>
            <p class="max-w-sm text-[14px] text-cr-ink-muted">
              @if (term()) {
                {{ loaded.length }} rows here, none of them this. Check the spelling, or clear
                the search to see them all.
              } @else {
                {{ loaded.length }} rows here, none between those dates. Widen the range, or clear
                it to see them all.
              }
            </p>
            <button
              type="button"
              class="mt-1 h-9 rounded-control border border-cr-line px-3 text-[13px] font-semibold text-cr-ink transition-colors hover:bg-cr-tint"
              (click)="clear()"
            >
              {{ term() && ranged() ? 'Clear filters' : term() ? 'Clear search' : 'Clear dates' }}
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
          <table
            class="w-full border-collapse text-left whitespace-nowrap"
            [style.min-width.px]="minWidth()"
          >
            <thead>
              <tr class="border-b border-cr-line">
                @if (rowSelection()) {
                  @let selection = matchedSelection();
                  <th scope="col" class="w-[72px] min-w-[72px] px-4">
                    <label class="flex w-max items-center gap-1.5 py-2 whitespace-nowrap">
                      <input
                        #selectAll
                        type="checkbox"
                        aria-label="Select all matching rows"
                        class="size-4 shrink-0 accent-cr-blue"
                        [checked]="selection.all"
                        [indeterminate]="selection.partial"
                        [disabled]="selectionDisabled() || selection.rows.length === 0"
                        (change)="selectionChange.emit({ rows: selection.rows, checked: selectAll.checked })"
                      />
                      <span class="text-[11px] font-semibold text-cr-ink-muted">All</span>
                    </label>
                  </th>
                }
                @for (col of columns(); track col.label) {
                  <th class="cr-th px-4 py-2.5" [class.text-right]="col.kind === 'num'">
                    {{ col.label }}
                  </th>
                }
                @if (rowAction()) {
                  <th class="cr-th px-4 py-2.5" [style.width.px]="deletable() ? 104 : 64">
                    <span class="sr-only">Actions</span>
                  </th>
                }
              </tr>
            </thead>
            <tbody>
              @for (row of page(); track $index) {
                <tr
                  class="border-b border-cr-line/70 transition-colors last:border-0 hover:bg-cr-tint"
                >
                  @if (rowSelection(); as selection) {
                    <td class="px-4 py-3">
                      @if (selection(row) !== null) {
                        <input
                          #checkbox
                          type="checkbox"
                          class="size-4 accent-cr-blue"
                          [attr.aria-label]="'Select ' + (rowLabel()?.(row) ?? 'row')"
                          [checked]="selection(row)"
                          [disabled]="selectionDisabled()"
                          (change)="selectionChange.emit({ rows: [row], checked: checkbox.checked })"
                        />
                      }
                    </td>
                  }
                  @for (col of columns(); track col.label) {
                    <td
                      class="px-4 py-3 text-[13px]"
                      [class.text-right]="col.kind === 'num'"
                      [class.cr-num]="col.kind === 'num' || col.kind === 'strong'"
                      [class.font-semibold]="col.kind === 'strong'"
                      [class.text-cr-ink-muted]="col.kind === 'muted'"
                    >
                      @if (col.kind === 'status' && col.status) {
                        <app-status-pill [status]="col.status(row)" />
                        @if (col.sub?.(row); as detail) {
                          <span class="mt-1 block text-[12px] text-cr-ink-muted">{{ detail }}</span>
                        }
                      } @else if (col.pill) {
                        <span
                          class="inline-flex items-center rounded-full px-2 py-[3px] text-[10px] font-semibold tracking-[0.06em] uppercase"
                          [class.bg-cr-tint]="col.pill(row).on"
                          [class.text-cr-blue]="col.pill(row).on"
                          [class.border]="!col.pill(row).on"
                          [class.border-cr-line]="!col.pill(row).on"
                          [class.text-cr-ink-muted]="!col.pill(row).on"
                        >
                          {{ col.pill(row).label }}
                        </span>
                        @if (col.sub) {
                          <span class="block text-[12px] text-cr-ink-muted">{{
                            col.sub(row) ?? dash
                          }}</span>
                        }
                      } @else if (col.badge && col.badge(row)) {
                        <span
                          class="inline-flex items-center gap-1.5 rounded-full bg-cr-warning-bg px-2 py-[3px] text-[10px] font-semibold tracking-[0.06em] text-cr-warning uppercase"
                        >
                          <span class="h-1.5 w-1.5 rounded-full bg-cr-warning"></span>
                          {{ col.badge(row) }}
                        </span>
                        @if (col.sub) {
                          <span class="block text-[12px] text-cr-ink-muted">{{
                            col.sub(row) ?? dash
                          }}</span>
                        }
                      } @else {
                        {{ col.value ? (col.value(row) ?? dash) : '' }}
                        @if (col.sub?.(row); as detail) {
                          <span class="block text-[12px] text-cr-ink-muted">{{ detail }}</span>
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
                            [attr.aria-label]="removeLabel() + ' ' + (rowLabel() ? rowLabel()!(row) : 'row')"
                            (click)="remove.emit(row)"
                          >
                            <app-icon name="close" [size]="16" />
                          </button>
                        }
                        <button
                          type="button"
                          class="flex h-8 w-8 items-center justify-center rounded-control text-cr-ink-muted transition-colors hover:bg-cr-tint hover:text-cr-blue"
                          [attr.aria-label]="rowAction()"
                          (click)="open.emit(row)"
                        >
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

        @if (paginated() && matched().length > 0) {
          <app-table-pager
            [total]="matched().length"
            [size]="size()"
            [index]="pageIndex()"
            (indexChange)="pageIndex.set($event)"
            (sizeChange)="setSize($event)"
          />
        }
      }
    } @else {
      <app-skeleton-rows [count]="skeletonCount()" />
    }
  `,
})
export class DataTable {
  readonly columns = input.required<Column[]>();
  readonly rows = input.required<any[] | null>();
  readonly minWidth = input(720);
  readonly skeletonCount = input(6);
  readonly rowAction = input<string | null>(null);
  readonly emptyIcon = input('shipments');
  readonly emptyTitle = input('Nothing here yet');
  readonly emptyBody = input('When there is data to show, it will appear here.');

  readonly deletable = input(false);
  readonly rowLabel = input<((row: any) => string) | null>(null);
  readonly removeLabel = input('Delete');

  readonly open = output<any>();
  readonly remove = output<any>();
  readonly rowSelection = input<((row: any) => boolean | null) | null>(null);
  readonly selectionDisabled = input(false);
  readonly selectionChange = output<{ rows: any[]; checked: boolean }>();

  readonly searchable = input(true);
  readonly compactFilters = input(false);
  readonly paginated = input(true);

  readonly searchPlaceholder = input('Search…');

  readonly pageSize = input(10);


  protected readonly term = signal('');
  protected readonly from = signal('');
  protected readonly to = signal('');
  protected readonly pageIndex = signal(0);
  private readonly chosenSize = signal<number | null>(null);

  protected readonly size = computed(() => this.chosenSize() ?? this.pageSize());

  private readonly dateColumn = computed(() => this.columns().find((col) => col.date) ?? null);

  protected readonly dated = computed(() => this.dateColumn() !== null);

  protected readonly dateLabel = computed(() => `Filter by ${this.dateColumn()?.label ?? 'date'}`);

  protected readonly ranged = computed(() => this.from() !== '' || this.to() !== '');

  protected readonly matched = computed(() => {
    const loaded = this.rows() ?? [];
    const needle = this.searchable() ? this.term().trim().toLowerCase() : '';
    const from = this.from();
    const to = this.to();
    const dateOf = this.dateColumn()?.date;

    if (needle === '' && (from === '' && to === '')) return loaded;

    return loaded.filter((row) => {
      if (needle !== '' && !matchesTableSearch(this.haystack(row), needle)) return false;

      if (dateOf && (from !== '' || to !== '')) {
        const day = dayOf(dateOf(row));

        if (day === null) return false;
        if (from !== '' && day < from) return false;
        if (to !== '' && day > to) return false;
      }

      return true;
    });
  });

  protected readonly pageCount = computed(() =>
    Math.max(1, Math.ceil(this.matched().length / this.size())),
  );

  protected readonly matchedSelection = computed(() => {
    const selection = this.rowSelection();
    const rows: any[] = [];
    let checked = 0;

    if (selection) {
      for (const row of this.matched()) {
        const state = selection(row);
        if (state === null) continue;
        rows.push(row);
        if (state) checked++;
      }
    }

    return {
      rows,
      all: rows.length > 0 && checked === rows.length,
      partial: checked > 0 && checked < rows.length,
    };
  });

  protected readonly page = computed(() => {
    if (!this.paginated()) return this.matched();

    const start = this.pageIndex() * this.size();

    return this.matched().slice(start, start + this.size());
  });

  constructor() {
    effect(() => {
      const last = this.pageCount() - 1;

      if (this.pageIndex() > last) this.pageIndex.set(Math.max(0, last));
    });
  }

  protected setTerm(term: string): void {
    this.term.set(term);
    this.pageIndex.set(0);
  }

  protected setFrom(event: Event): void {
    this.from.set((event.target as HTMLInputElement).value);
    this.pageIndex.set(0);
  }

  protected setTo(event: Event): void {
    this.to.set((event.target as HTMLInputElement).value);
    this.pageIndex.set(0);
  }

  protected clearDates(): void {
    this.from.set('');
    this.to.set('');
    this.pageIndex.set(0);
  }

  protected clear(): void {
    this.term.set('');
    this.from.set('');
    this.to.set('');
    this.pageIndex.set(0);
  }

  protected setSize(size: number): void {
    this.chosenSize.set(size);
    this.pageIndex.set(0);
  }

  private haystack(row: any): string {
    return this.columns()
      .map((col) => [
        col.value?.(row), col.sub?.(row), col.badge?.(row), col.pill?.(row).label,
        col.status ? statusLabel(col.status(row)) : null, col.date?.(row),
      ].join(' '))
      .join(' ');
  }

  protected readonly dash = '—';
}

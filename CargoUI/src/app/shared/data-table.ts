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

export interface Column<T = any> {
  label: string;
  kind?: 'text' | 'num' | 'strong' | 'muted' | 'status';
  value?: (row: T) => string | number | null;
  status?: (row: T) => StatusValue;
  sub?: (row: T) => string | null;
  badge?: (row: T) => string | null;
}

@Component({
  selector: 'app-data-table',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, StatusPill, SkeletonRows],
  template: `
    @if (rows(); as loaded) {
      @if (searchable() && loaded.length > 0) {
        <div class="flex flex-wrap items-center gap-2 px-4 py-3">
          <label class="relative min-w-[200px] flex-1">
            <span class="sr-only">Search this list</span>
            <app-icon
              name="search"
              [size]="15"
              class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-cr-ink-muted"
            />
            <input
              type="search"
              [value]="term()"
              (input)="setTerm($event)"
              [attr.placeholder]="searchPlaceholder()"
              class="h-9 w-full rounded-control border border-cr-line bg-cr-surface pr-3 pl-9 text-[13px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none"
            />
          </label>

          @if (term()) {
            <p class="cr-num text-[12px] text-cr-ink-muted">
              {{ matched().length }} of {{ loaded.length }}
            </p>
          }
        </div>
      }

      @if (matched().length === 0) {
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
              (click)="clear()"
            >
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
          <table
            class="w-full border-collapse text-left whitespace-nowrap"
            [style.min-width.px]="minWidth()"
          >
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
                  class="border-b border-cr-line/70 transition-colors last:border-0 hover:bg-cr-tint"
                >
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
          <div
            class="flex flex-wrap items-center justify-between gap-3 border-t border-cr-line px-4 py-3"
          >
            <label class="flex items-center gap-2 text-[12px] text-cr-ink-muted">
              <span>Rows</span>
              <select
                [value]="size()"
                (change)="setSize($event)"
                class="h-8 rounded-control border border-cr-line bg-cr-surface px-2 text-[13px] text-cr-ink focus:border-cr-blue focus:outline-none"
              >
                @for (option of sizes; track option) {
                  <option [value]="option" [selected]="option === size()">{{ option }}</option>
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
                (click)="back()"
              >
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
                (click)="next()"
              >
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
  readonly rows = input.required<any[] | null>();
  readonly minWidth = input(720);
  readonly skeletonCount = input(6);
  readonly rowAction = input<string | null>(null);
  readonly emptyIcon = input('shipments');
  readonly emptyTitle = input('Nothing here yet');
  readonly emptyBody = input('When there is data to show, it will appear here.');

  readonly deletable = input(false);
  readonly rowLabel = input<((row: any) => string) | null>(null);

  readonly open = output<any>();
  readonly remove = output<any>();

  readonly searchable = input(true);
  readonly paginated = input(true);

  readonly searchPlaceholder = input('Search…');

  readonly pageSize = input(10);

  protected readonly sizes = [5, 10, 15, 20] as const;

  protected readonly term = signal('');
  protected readonly pageIndex = signal(0);
  private readonly chosenSize = signal<number | null>(null);

  protected readonly size = computed(() => this.chosenSize() ?? this.pageSize());

  protected readonly matched = computed(() => {
    const loaded = this.rows() ?? [];
    const needle = this.term().trim().toLowerCase();

    if (needle === '' || !this.searchable()) return loaded;

    return loaded.filter((row) => this.haystack(row).includes(needle));
  });

  protected readonly pageCount = computed(() =>
    Math.max(1, Math.ceil(this.matched().length / this.size())),
  );

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
    effect(() => {
      const last = this.pageCount() - 1;

      if (this.pageIndex() > last) this.pageIndex.set(Math.max(0, last));
    });
  }

  protected setTerm(event: Event): void {
    this.term.set((event.target as HTMLInputElement).value);
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

  private haystack(row: any): string {
    return this.columns()
      .filter((col) => col.kind !== 'status')
      .map((col) => `${col.badge?.(row) ?? col.value?.(row) ?? ''} ${col.sub?.(row) ?? ''}`)
      .join(' ')
      .toLowerCase();
  }

  protected readonly dash = '—';
}

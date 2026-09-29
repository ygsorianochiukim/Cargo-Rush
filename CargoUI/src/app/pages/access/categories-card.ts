import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { Observable, map } from 'rxjs';

import { ExpenseService } from '../../services/expense/expense.service';
import { IdentityService } from '../../services/identity/identity.service';
import { SupplierService } from '../../services/supplier/supplier.service';
import { Card } from '../../shared/card';
import { Icon } from '../../shared/icon';

/** One row, whichever list it came from. */
interface CategoryRow {
  id: string;
  name: string;
  active: boolean;
  /** How many records are filed under it, when the API counted them. */
  used: number | null;
}

/** What each list needs to be edited from here. */
interface CategoryList {
  key: 'supplier' | 'expense';
  label: string;
  /** What a row of this list is filed against, for the count. */
  noun: string;
  placeholder: string;
  load: () => Observable<CategoryRow[]>;
  create: (name: string) => Observable<unknown>;
  update: (id: string, changes: { name?: string; status?: string }) => Observable<unknown>;
  remove: (id: string) => Observable<unknown>;
}

/**
 * The firm's own lists behind the dropdowns — supplier categories and expense
 * categories — in one place.
 *
 * A dropdown is only as clean as the list behind it. Both of these are words
 * the office chooses (GARAGE, MALL, FOODS; Food, Lodging, Repairs), so they
 * are edited here, on the administrator's screen, rather than typed free-hand
 * into each record.
 *
 * Deleting a category that has records under it switches it off instead: the
 * API decides, and this card says which happened. Off means it stops being
 * offered in the pickers while the records filed under it keep their label.
 */
@Component({
  selector: 'app-categories-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Icon],
  template: `
    <app-card heading="Categories" icon="tag" hint="The choices in the dropdowns">
      @if (lists.length === 0) {
        <p class="cr-meta">You do not have permission to change any category list.</p>
      } @else {
        <div class="inline-flex rounded-control border border-cr-line p-0.5" role="tablist">
          @for (list of lists; track list.key) {
            <button
              type="button"
              role="tab"
              class="h-9 rounded-control px-3 text-[13px] font-semibold transition-colors"
              [class]="
                list.key === current().key
                  ? 'bg-cr-tint text-cr-blue'
                  : 'text-cr-ink-muted hover:bg-cr-tint'
              "
              [attr.aria-selected]="list.key === current().key"
              (click)="choose(list)"
            >
              {{ list.label }}
            </button>
          }
        </div>

        <!-- Add -->
        <form class="mt-4 flex flex-wrap items-center gap-2" (submit)="add($event)">
          <label class="min-w-[220px] flex-1">
            <span class="sr-only">New category name</span>
            <input
              #name
              type="text"
              maxlength="60"
              [placeholder]="current().placeholder"
              class="h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none"
              [value]="draft()"
              (input)="draft.set(name.value)"
            />
          </label>
          <button
            type="submit"
            class="inline-flex h-10 items-center gap-1 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
            [disabled]="busy() || draft().trim() === ''"
          >
            <app-icon name="plus" [size]="16" />
            Add category
          </button>
        </form>

        @if (notice(); as message) {
          <p role="status" class="mt-3 text-[12px] font-medium" [class]="noticeTone()">
            {{ message }}
          </p>
        }

        <!-- The list -->
        <ul class="mt-4 divide-y divide-cr-line rounded-control border border-cr-line">
          @for (row of rows(); track row.id) {
            <li class="flex flex-wrap items-center gap-3 px-4 py-2.5">
              @if (editing() === row.id) {
                <input
                  #rename
                  type="text"
                  maxlength="60"
                  class="h-9 min-w-[180px] flex-1 rounded-control border border-cr-blue bg-cr-surface px-3 text-[14px] focus:outline-none"
                  [value]="row.name"
                  [attr.aria-label]="'Rename ' + row.name"
                  (keydown.enter)="saveName(row, rename.value)"
                  (keydown.escape)="editing.set(null)"
                />
                <button
                  type="button"
                  class="text-[12px] font-semibold text-cr-blue hover:underline"
                  (click)="saveName(row, rename.value)"
                >
                  Save
                </button>
                <button
                  type="button"
                  class="text-[12px] font-semibold text-cr-ink-muted hover:underline"
                  (click)="editing.set(null)"
                >
                  Cancel
                </button>
              } @else {
                <div class="min-w-0 flex-1">
                  <span class="text-[14px] font-semibold" [class.text-cr-ink-muted]="!row.active">
                    {{ row.name }}
                  </span>
                  <span class="cr-meta ml-2">
                    @if (!row.active) {
                      Off ·
                    }
                    @if (row.used !== null) {
                      {{ row.used }} {{ current().noun }}{{ row.used === 1 ? '' : 's' }}
                    }
                  </span>
                </div>
                <div class="flex items-center gap-3">
                  <button
                    type="button"
                    class="text-[12px] font-semibold text-cr-blue hover:underline disabled:opacity-60"
                    [disabled]="busy()"
                    (click)="editing.set(row.id)"
                  >
                    Rename
                  </button>
                  <button
                    type="button"
                    class="text-[12px] font-semibold text-cr-ink-muted hover:underline disabled:opacity-60"
                    [disabled]="busy()"
                    (click)="toggle(row)"
                  >
                    {{ row.active ? 'Switch off' : 'Switch on' }}
                  </button>
                  <button
                    type="button"
                    class="text-[12px] font-semibold text-cr-red hover:underline disabled:opacity-60"
                    [disabled]="busy()"
                    [attr.aria-label]="'Delete ' + row.name"
                    (click)="remove(row)"
                  >
                    Delete
                  </button>
                </div>
              }
            </li>
          } @empty {
            <li class="cr-meta px-4 py-3">
              {{ loading() ? 'Loading…' : 'No categories yet. Add the first one above.' }}
            </li>
          }
        </ul>

        <p class="cr-meta mt-2">
          Switched-off categories stop appearing in the dropdowns; records already filed under them
          keep their label. Deleting one that is in use switches it off instead.
        </p>
      }
    </app-card>
  `,
})
export class CategoriesCard {
  private readonly suppliers = inject(SupplierService);
  private readonly expenses = inject(ExpenseService);
  private readonly identity = inject(IdentityService);

  /** Only the lists this person may change. */
  protected readonly lists: CategoryList[] = [
    ...(this.identity.has('suppliers.manage')
      ? [
          {
            key: 'supplier' as const,
            label: 'Supplier categories',
            noun: 'supplier',
            placeholder: 'GARAGE, MALL, SUPPLIER, FOODS…',
            load: () =>
              this.suppliers.categories().pipe(
                map((rows) =>
                  rows.map((c) => ({
                    id: c.id,
                    name: c.name,
                    active: c.status === 'active',
                    used: c.suppliers_count ?? null,
                  })),
                ),
              ),
            create: (name: string) => this.suppliers.createCategory({ name }),
            update: (id: string, changes: { name?: string; status?: string }) =>
              this.suppliers.updateCategory(id, changes),
            remove: (id: string) => this.suppliers.removeCategory(id),
          },
        ]
      : []),
    ...(this.identity.has('expenses.manage')
      ? [
          {
            key: 'expense' as const,
            label: 'Expense categories',
            noun: 'expense',
            placeholder: 'Food, Lodging, Repairs…',
            load: () =>
              this.expenses.categories().pipe(
                map((rows) =>
                  rows.map((c) => ({
                    id: c.id,
                    name: c.name,
                    active: c.status === 'active',
                    used: (c as { expense_count?: number }).expense_count ?? null,
                  })),
                ),
              ),
            create: (name: string) => this.expenses.createCategory({ name }),
            update: (id: string, changes: { name?: string; status?: string }) =>
              this.expenses.updateCategory(id, changes as never),
            remove: (id: string) => this.expenses.removeCategory(id),
          },
        ]
      : []),
  ];

  protected readonly current = signal<CategoryList>(this.lists[0]);
  protected readonly rows = signal<CategoryRow[]>([]);
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly draft = signal('');
  protected readonly editing = signal<string | null>(null);

  protected readonly notice = signal<string | null>(null);
  private readonly noticeIsError = signal(false);
  protected readonly noticeTone = computed(() =>
    this.noticeIsError() ? 'text-cr-red' : 'text-cr-green',
  );

  constructor() {
    if (this.lists.length > 0) this.reload();
  }

  protected choose(list: CategoryList): void {
    if (list.key === this.current().key) return;

    this.current.set(list);
    this.editing.set(null);
    this.draft.set('');
    this.say(null);
    this.reload();
  }

  protected add(event: Event): void {
    event.preventDefault();

    const name = this.draft().trim();

    if (name === '') return;

    this.run(this.current().create(name), `Added ${name}.`, () => this.draft.set(''));
  }

  protected saveName(row: CategoryRow, value: string): void {
    const name = value.trim();

    if (name === '' || name === row.name) {
      this.editing.set(null);

      return;
    }

    this.run(this.current().update(row.id, { name }), `Renamed to ${name}.`, () =>
      this.editing.set(null),
    );
  }

  protected toggle(row: CategoryRow): void {
    this.run(
      this.current().update(row.id, { status: row.active ? 'inactive' : 'active' }),
      row.active ? `${row.name} is switched off.` : `${row.name} is back on.`,
    );
  }

  protected remove(row: CategoryRow): void {
    const list = this.current();

    this.busy.set(true);
    this.say(null);

    list.remove(row.id).subscribe({
      next: () => {
        this.busy.set(false);
        // The client drops the response body, so which happened is read off
        // the list: a retired category is still in it, a deleted one is gone.
        this.reload((rows) =>
          this.say(
            rows.some((r) => r.id === row.id)
              ? `${row.name} has ${list.noun}s filed under it, so it was switched off instead.`
              : `Deleted ${row.name}.`,
          ),
        );
      },
      error: (error: HttpErrorResponse) => this.fail(error),
    });
  }

  private run(request: Observable<unknown>, success: string, then?: () => void): void {
    this.busy.set(true);
    this.say(null);

    request.subscribe({
      next: () => {
        this.busy.set(false);
        then?.();
        this.say(success);
        this.reload();
      },
      error: (error: HttpErrorResponse) => this.fail(error),
    });
  }

  private reload(then?: (rows: CategoryRow[]) => void): void {
    const list = this.current();

    this.loading.set(true);

    list.load().subscribe({
      next: (rows) => {
        // Ignore a slow answer for the tab somebody has already left.
        if (list.key !== this.current().key) return;

        this.rows.set(rows);
        this.loading.set(false);
        then?.(rows);
      },
      error: () => {
        this.loading.set(false);
        this.say('Could not load the categories.', true);
      },
    });
  }

  private fail(error: HttpErrorResponse): void {
    this.busy.set(false);

    const errors = error.error?.errors as Record<string, string[]> | undefined;

    this.say(
      (errors && Object.values(errors)[0]?.[0]) ??
        error.error?.message ??
        'Could not save that. Check the connection and try again.',
      true,
    );
  }

  private say(message: string | null, isError = false): void {
    this.notice.set(message);
    this.noticeIsError.set(isError);
  }
}

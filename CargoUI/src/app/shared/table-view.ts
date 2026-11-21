import { ChangeDetectionStrategy, Component, computed, effect, input, signal } from '@angular/core';

import { fmt } from './format';
import { TablePager } from './table-pager';
import { matchesTableSearch, TableSearch } from './table-search';

function fieldValue(row: unknown, path: string): string {
  const value = path.split('.').reduce<unknown>((current, key) =>
    current !== null && typeof current === 'object' ? (current as Record<string, unknown>)[key] : undefined, row);
  if (typeof value === 'number') return path.endsWith('_cents') ? value + ' ' + fmt.pesos(value) : String(value);
  if (typeof value !== 'string') return '';
  return /^\d{4}-\d{2}-\d{2}(?:T|$)/.test(value) ? value + ' ' + fmt.date(value) : value;
}

@Component({
  selector: 'app-table-view',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TablePager, TableSearch],
  styles: ':host { display: block; min-width: 0; }',
  template: `
    @if (rows()?.length || term()) {
      <div class="cr-no-print px-4 py-3">
        <app-table-search [label]="label()" [placeholder]="label()" [value]="term()" (valueChange)="search($event)" />
      </div>
    }
    <ng-content />
    @if (term() && !matched().length) {
      <p class="px-4 py-3 text-[13px] text-cr-ink-muted" role="status">No rows match “{{ term() }}”.</p>
    }
    @if (matched().length) {
      <app-table-pager
        class="cr-no-print"
        [total]="matched().length"
        [index]="index()"
        [size]="size()"
        (indexChange)="index.set($event)"
        (sizeChange)="setSize($event)"
      />
    }
  `,
})
export class TableView<T> {
  readonly rows = input.required<readonly T[] | null>();
  readonly fields = input.required<readonly string[]>();
  readonly label = input('Search this table');

  protected readonly term = signal('');
  protected readonly index = signal(0);
  protected readonly size = signal(10);
  protected readonly matched = computed(() => {
    const term = this.term().trim();
    const rows = this.rows() ?? [];
    const fields = this.fields();
    return term ? rows.filter((row) => matchesTableSearch(fields.map((field) => fieldValue(row, field)).join(' '), term)) : rows;
  });

  readonly page = computed(() => {
    const start = this.index() * this.size();
    return this.matched().slice(start, start + this.size());
  });

  constructor() {
    effect(() => {
      const lastIndex = Math.max(0, Math.ceil(this.matched().length / this.size()) - 1);
      if (this.index() > lastIndex) this.index.set(lastIndex);
    });
  }

  protected search(term: string): void {
    this.term.set(term);
    this.index.set(0);
  }

  protected setSize(size: number): void {
    this.size.set(size);
    this.index.set(0);
  }
}

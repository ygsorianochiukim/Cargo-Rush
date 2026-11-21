import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';

import { Icon } from './icon';

export function matchesTableSearch(text: string, term: string): boolean {
  const normalized = text.toLowerCase();
  return term.trim().toLowerCase().split(/\s+/).every((word) => normalized.includes(word));
}

@Component({
  selector: 'app-table-search',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  styles: ':host { display: block; }',
  template: `
    <label class="relative block">
      <span class="sr-only">{{ label() }}</span>
      <app-icon
        name="search"
        [size]="15"
        class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-cr-ink-muted"
      />
      <input
        type="search"
        [value]="value()"
        [placeholder]="placeholder()"
        [attr.maxlength]="maxLength()"
        (input)="valueChange.emit($any($event.target).value)"
        class="h-9 w-full rounded-control border border-cr-line bg-cr-surface pr-3 pl-9 text-[13px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none"
      />
    </label>
  `,
})
export class TableSearch {
  readonly label = input('Search this table');
  readonly placeholder = input('Search…');
  readonly value = input('');
  readonly maxLength = input<number | null>(null);
  readonly valueChange = output<string>();
}

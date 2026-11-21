import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { expect, it } from 'vitest';

import { TableView } from './table-view';

@Component({
  imports: [TableView],
  template: `
    <app-table-view #records [rows]="rows" [fields]="['name', 'owner.name']">
      <table>
        <tbody>
          @for (row of records.page(); track row.name) {
            <tr><td>{{ row.name }}</td></tr>
          }
        </tbody>
      </table>
    </app-table-view>
  `,
})
class Host {
  readonly rows = Array.from({ length: 12 }, (_, index) => ({
    name: 'Record ' + (index + 1),
    owner: index === 11 ? { name: 'North Depot' } : null,
  }));
}

it('pages a custom table and searches records beyond its current page', () => {
  const fixture = TestBed.createComponent(Host);
  const root = fixture.nativeElement as HTMLElement;
  const visibleRows = () => Array.from(root.querySelectorAll('tbody tr'), (row) => row.textContent?.trim());
  fixture.detectChanges();
  expect(visibleRows()).toHaveLength(10);

  root.querySelector<HTMLButtonElement>('[aria-label="Next page"]')!.click();
  fixture.detectChanges();
  expect(visibleRows()).toEqual(['Record 11', 'Record 12']);

  const search = root.querySelector<HTMLInputElement>('input[type="search"]')!;
  search.value = 'depot north';
  search.dispatchEvent(new Event('input', { bubbles: true }));
  fixture.detectChanges();
  expect(visibleRows()).toEqual(['Record 12']);
  expect(root.querySelector<HTMLButtonElement>('[aria-label="Previous page"]')!.disabled).toBe(true);

  search.value = 'missing';
  search.dispatchEvent(new Event('input', { bubbles: true }));
  fixture.detectChanges();
  expect(visibleRows()).toEqual([]);
  expect(root.textContent).toContain('No rows match');
});

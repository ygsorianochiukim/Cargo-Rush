import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';

import { Column, DataTable } from './data-table';

interface Row {
  id: string;
  name: string;
  plate: string;
}

const columns: Column<Row>[] = [
  { label: 'Name', value: (row) => row.name, sub: (row) => row.plate },
  { label: 'Status', kind: 'status', status: () => 'active' as never },
];

function rows(n: number): Row[] {
  return Array.from({ length: n }, (_, i) => ({
    id: String(i),
    name: i % 2 === 0 ? `Isuzu Elf ${i}` : `Fuso Canter ${i}`,
    plate: `ABC ${1000 + i}`,
  }));
}

function table(list: Row[] | null, inputs: Record<string, unknown> = {}) {
  const fixture = TestBed.createComponent(DataTable);

  fixture.componentRef.setInput('columns', columns);
  fixture.componentRef.setInput('rows', list);

  for (const [key, value] of Object.entries(inputs)) {
    fixture.componentRef.setInput(key, value);
  }

  fixture.detectChanges();

  return fixture.componentInstance as unknown as {
    term: { set(value: string): void };
    pageIndex: { set(value: number): void; (): number };
    matched(): Row[];
    page(): Row[];
    pageCount(): number;
    firstShown(): number;
    lastShown(): number;
  };
}

describe('searching', () => {
  it('matches on what the columns print, including the sub-line', () => {
    const t = table(rows(20));

    t.term.set('ABC 1003');
    expect(t.matched()).toHaveLength(1);

    t.term.set('fuso');
    expect(t.matched()).toHaveLength(10);
  });

  it('ignores case and surrounding space', () => {
    const t = table(rows(20));

    t.term.set('  ISUZU  ');
    expect(t.matched()).toHaveLength(10);
  });

  it('leaves a status column out of the haystack', () => {
    const t = table(rows(20));

    t.term.set('active');
    expect(t.matched()).toHaveLength(0);
  });

  it('returns everything when the box is empty', () => {
    const t = table(rows(20));

    t.term.set('');
    expect(t.matched()).toHaveLength(20);
  });

  it('searches nothing when the page turned it off', () => {
    const t = table(rows(20), { searchable: false });

    t.term.set('fuso');
    expect(t.matched()).toHaveLength(20);
  });
});

describe('paging', () => {
  it('shows a page of the size asked for', () => {
    const t = table(rows(20), { pageSize: 5 });

    expect(t.page()).toHaveLength(5);
    expect(t.pageCount()).toBe(4);
    expect(t.firstShown()).toBe(1);
    expect(t.lastShown()).toBe(5);
  });

  it('counts the last page from where it actually starts', () => {
    const t = table(rows(22), { pageSize: 10 });

    t.pageIndex.set(2);

    expect(t.page()).toHaveLength(2);
    expect(t.firstShown()).toBe(21);
    expect(t.lastShown()).toBe(22);
  });

  it('shows a list shorter than a page whole, on one page', () => {
    const t = table(rows(3), { pageSize: 5 });

    expect(t.page()).toHaveLength(3);
    expect(t.pageCount()).toBe(1);
    expect(t.firstShown()).toBe(1);
    expect(t.lastShown()).toBe(3);
  });

  it('pages a list of exactly one page plus one', () => {
    const t = table(rows(6), { pageSize: 5 });

    expect(t.page()).toHaveLength(5);
    expect(t.pageCount()).toBe(2);
  });

  it('never strands the reader past the end', async () => {
    const t = table(rows(30), { pageSize: 10 });

    t.pageIndex.set(2);
    expect(t.page()).toHaveLength(10);

    t.term.set('ABC 1003');
    await Promise.resolve();
    TestBed.tick();

    expect(t.pageIndex()).toBe(0);
    expect(t.page()).toHaveLength(1);
  });

  it('gives the whole list when the page turned paging off', () => {
    const t = table(rows(30), { paginated: false });

    expect(t.page()).toHaveLength(30);
  });

  it('holds still while the rows are loading', () => {
    const t = table(null);

    expect(t.matched()).toHaveLength(0);
    expect(t.pageCount()).toBe(1);
  });
});

describe('a badge in place of a value', () => {
  interface Priced {
    id: string;
    price: number | null;
    note: string | null;
  }

  const priceColumns: Column<Priced>[] = [
    {
      label: 'Price',
      value: (row) => (row.price === null ? null : `P${row.price}`),
      badge: (row) => (row.price === null ? 'Needs a zone' : null),
      sub: (row) => row.note,
    },
  ];

  function render(list: Priced[]): HTMLElement {
    const fixture = TestBed.createComponent(DataTable);

    fixture.componentRef.setInput('columns', priceColumns);
    fixture.componentRef.setInput('rows', list);
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it('prints the badge and the reason under it where the value does not exist', () => {
    const text = render([{ id: '1', price: null, note: 'No zone covers 712 km.' }]).textContent ?? '';

    expect(text).toContain('Needs a zone');
    expect(text).toContain('No zone covers 712 km.');
  });

  it('prints the value where there is one', () => {
    const text = render([{ id: '1', price: 4753, note: null }]).textContent ?? '';

    expect(text).toContain('P4753');
    expect(text).not.toContain('Needs a zone');
  });
});

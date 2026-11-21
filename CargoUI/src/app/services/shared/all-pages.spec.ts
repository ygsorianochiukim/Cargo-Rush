import { firstValueFrom, of } from 'rxjs';
import { expect, it, vi } from 'vitest';

import { allPages } from './all-pages';

it('uses API pagination metadata to load every page without reusing rows between subscriptions', async () => {
  const load = vi.fn(({ page }: { page: number; per_page: number }) =>
    of({ data: [page], meta: { page, per_page: 1, total: 3 } }),
  );
  const rows = allPages(load);

  expect(await firstValueFrom(rows)).toEqual([1, 2, 3]);
  expect(load.mock.calls.map(([query]) => query.page)).toEqual([1, 2, 3]);
  expect(await firstValueFrom(rows)).toEqual([1, 2, 3]);
});

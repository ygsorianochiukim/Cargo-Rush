import { EMPTY, Observable, defer, expand, reduce } from 'rxjs';

import { Envelope } from '../../models/shared/envelope.model';

export function allPages<T>(
  load: (query: { page: number; per_page: number }) => Observable<Envelope<T[]>>,
): Observable<T[]> {
  const per_page = 100;
  return defer(() => load({ page: 1, per_page }).pipe(
    expand(({ data, meta }) => {
      const page = Number(meta?.['page'] ?? 1);
      const pageSize = Number(meta?.['per_page'] ?? per_page);
      const total = Number(meta?.['total'] ?? data.length);
      return data.length && page * pageSize < total ? load({ page: page + 1, per_page }) : EMPTY;
    }, 1),
    reduce((rows, result) => {
      for (const row of result.data) rows.push(row);
      return rows;
    }, [] as T[]),
  ));
}

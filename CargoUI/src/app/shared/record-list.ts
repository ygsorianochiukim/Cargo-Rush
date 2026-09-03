import { DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Observable } from 'rxjs';

import { Confirm } from './confirm';
import { RecordDialog } from './record-dialog';
import { RecordSpec } from './record-form-spec';

/**
 * List + create + edit + delete for one module, in one call.
 *
 * Every list page needs the same four states and the same three verbs. Written
 * per page that came to the same forty lines seven times over, and the seventh
 * copy is where somebody forgets the error state.
 *
 * Call it from a field initializer so `inject()` has a context:
 *
 *     protected readonly list = recordList(this.spec, () => this.api.list().pipe(...));
 */
export function recordList<T extends { id: string }>(
  spec: RecordSpec<T>,
  load: () => Observable<T[]>,
) {
  const dialog = inject(RecordDialog);
  const confirm = inject(Confirm);
  const destroyRef = inject(DestroyRef);

  /** Null means still loading — the four states depend on telling that apart. */
  const rows = signal<T[] | null>(null);
  const error = signal<string | null>(null);

  const refresh = (): void => {
    load().subscribe({
      next: (loaded) => {
        rows.set(loaded);
        error.set(null);
      },
      error: () => {
        rows.set(null);
        error.set('Could not load this list. Check the connection and try again.');
      },
    });
  };

  refresh();

  // Applied locally rather than refetching: the API already returned the saved
  // row, and a round trip would make the table flicker back to a skeleton.
  dialog.savedFor(spec).pipe(takeUntilDestroyed(destroyRef)).subscribe((saved) => {
    const current = rows() ?? [];
    const exists = current.some((row) => row.id === saved.id);

    rows.set(exists ? current.map((row) => (row.id === saved.id ? saved : row)) : [saved, ...current]);
  });

  dialog.deletedFor(spec).pipe(takeUntilDestroyed(destroyRef)).subscribe((id) => {
    rows.set((rows() ?? []).filter((row) => row.id !== id));
  });

  return {
    rows: rows.asReadonly(),
    error: error.asReadonly(),
    refresh,

    create: (): void => dialog.create(spec),
    edit: (record: T): void => dialog.edit(spec, record),

    /** Confirms first, always — DESIGN.md section 8.1. */
    remove: async (record: T): Promise<void> => {
      if (!spec.remove) return;

      const ok = await confirm.ask({
        title: `Delete ${spec.title(record)}?`,
        body: `This removes the ${spec.noun} from the system. Records already filed against it are kept.`,
        confirmLabel: `Delete ${spec.noun}`,
        danger: true,
      });

      if (!ok) return;

      spec.remove(record.id).subscribe({
        next: () => dialog.announceDeleted(spec, record.id),
        error: () => error.set(`Could not delete this ${spec.noun}.`),
      });
    },
  };
}

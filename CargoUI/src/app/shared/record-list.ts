import { DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Observable } from 'rxjs';

import { Confirm } from './confirm';
import { RecordDialog } from './record-dialog';
import { RecordSpec } from './record-form-spec';

export function recordList<T extends { id: string }>(
  spec: RecordSpec<T>,
  load: () => Observable<T[]>,
) {
  const dialog = inject(RecordDialog);
  const confirm = inject(Confirm);
  const destroyRef = inject(DestroyRef);

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

    remove: async (record: T): Promise<void> => {
      if (!spec.remove) return;

      const action = spec.archiveOnRemove ? 'Archive' : 'Delete';
      const ok = await confirm.ask({
        title: `${action} ${spec.title(record)}?`,
        body: spec.archiveOnRemove ? 'The record will be kept in the archive.' : `This removes the ${spec.noun} from the system. Records already filed against it are kept.`,
        confirmLabel: `${action} ${spec.noun}`,
        danger: true,
      });

      if (!ok) return;

      spec.remove(record.id).subscribe({
        next: () => dialog.announceDeleted(spec, record.id),
        error: () => error.set(`Could not ${action.toLowerCase()} this ${spec.noun}.`),
      });
    },
  };
}

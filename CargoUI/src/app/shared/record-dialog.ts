import { Injectable, signal } from '@angular/core';
import { Observable, Subject, filter, map } from 'rxjs';

import { RecordSpec } from './record-form-spec';

@Injectable({ providedIn: 'root' })
export class RecordDialog {
  readonly open = signal(false);

  readonly spec = signal<RecordSpec<any> | null>(null);

  readonly record = signal<any | null>(null);

  private readonly savedSubject = new Subject<{ spec: RecordSpec<any>; record: unknown }>();
  private readonly deletedSubject = new Subject<{ spec: RecordSpec<any>; id: string }>();

  create<T>(spec: RecordSpec<T>): void {
    this.spec.set(spec);
    this.record.set(null);
    this.open.set(true);
  }

  edit<T>(spec: RecordSpec<T>, record: T): void {
    this.spec.set(spec);
    this.record.set(record);
    this.open.set(true);
  }

  savedFor<T>(spec: RecordSpec<T>): Observable<T> {
    return this.savedSubject.pipe(
      filter((event) => event.spec === spec),
      map((event) => event.record as T),
    );
  }

  deletedFor<T>(spec: RecordSpec<T>): Observable<string> {
    return this.deletedSubject.pipe(
      filter((event) => event.spec === spec),
      map((event) => event.id),
    );
  }

  announceSaved(record: unknown): void {
    const spec = this.spec();
    if (spec !== null) this.savedSubject.next({ spec, record });
  }

  announceDeleted(spec: RecordSpec<any>, id: string): void {
    this.deletedSubject.next({ spec, id });
  }
}

import { Injectable, signal } from '@angular/core';
import { Observable, Subject, filter, map } from 'rxjs';

import { RecordSpec } from './record-form-spec';

/**
 * The one create/edit dialog, shared by every flat-record module.
 *
 * Mounted once in the layout, exactly as `TripDialog` and `LedgerDialog` are
 * (DESIGN.md section 8.1). A page opens it and listens for its own module's
 * saves — the events carry the spec they came from, so the Vehicles page does
 * not refresh itself because somebody edited a customer.
 */
@Injectable({ providedIn: 'root' })
export class RecordDialog {
  readonly open = signal(false);

  /** Which module's form to render. Null while closed. */
  readonly spec = signal<RecordSpec<any> | null>(null);

  /** The record being edited, or null when creating. */
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

  /** Records saved through `spec`, and no other module's. */
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

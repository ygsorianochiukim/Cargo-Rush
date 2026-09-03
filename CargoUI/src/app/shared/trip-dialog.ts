import { Injectable, signal } from '@angular/core';
import { Subject } from 'rxjs';

import { Trip } from '../models/trip/trip.model';

/**
 * Handle for the app's single trip dialog (DESIGN.md section 8).
 *
 * There is exactly one `<app-trip-form />` in the shell. Anything that needs
 * to create or edit a trip calls this service rather than declaring its own
 * copy of the modal, and listens to `saved` / `deleted` to refresh its view.
 *
 * ```ts
 * this.tripDialog.edit(row);
 * this.tripDialog.saved.subscribe((trip) => this.merge(trip));
 * ```
 */
@Injectable({ providedIn: 'root' })
export class TripDialog {
  readonly open = signal(false);
  readonly trip = signal<Trip | null>(null);

  private readonly savedSubject = new Subject<Trip>();
  private readonly deletedSubject = new Subject<string>();

  readonly saved = this.savedSubject.asObservable();
  readonly deleted = this.deletedSubject.asObservable();

  create(): void {
    this.trip.set(null);
    this.open.set(true);
  }

  edit(trip: Trip): void {
    this.trip.set(trip);
    this.open.set(true);
  }

  close(): void {
    this.open.set(false);
  }

  announceSaved(trip: Trip): void {
    this.savedSubject.next(trip);
  }

  announceDeleted(id: string): void {
    this.deletedSubject.next(id);
  }
}

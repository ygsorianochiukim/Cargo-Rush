import { Injectable, signal } from '@angular/core';
import { Subject } from 'rxjs';

import { Trip } from '../models/trip/trip.model';

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

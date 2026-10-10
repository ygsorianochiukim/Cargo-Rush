import { Injectable, signal } from '@angular/core';
import { Subject } from 'rxjs';

import { LedgerEntry } from '../models/finance/finance.model';

@Injectable({ providedIn: 'root' })
export class LedgerDialog {
  readonly open = signal(false);
  readonly entry = signal<LedgerEntry | null>(null);
  readonly truckId = signal<string | null>(null);

  private readonly savedSubject = new Subject<LedgerEntry>();
  private readonly deletedSubject = new Subject<string>();

  readonly saved = this.savedSubject.asObservable();
  readonly deleted = this.deletedSubject.asObservable();

  create(truckId?: string): void {
    this.entry.set(null);
    this.truckId.set(truckId ?? null);
    this.open.set(true);
  }

  edit(entry: LedgerEntry): void {
    this.entry.set(entry);
    this.truckId.set(entry.truck_id);
    this.open.set(true);
  }

  announceSaved(entry: LedgerEntry): void {
    this.savedSubject.next(entry);
  }

  announceDeleted(id: string): void {
    this.deletedSubject.next(id);
  }
}

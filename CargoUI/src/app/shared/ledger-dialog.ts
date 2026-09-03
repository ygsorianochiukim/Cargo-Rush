import { Injectable, signal } from '@angular/core';
import { Subject } from 'rxjs';

import { LedgerEntry } from '../models/finance/finance.model';

/**
 * Handle for the single daily-ledger dialog (DESIGN.md section 8.1).
 * Mounted once in the shell as `<app-ledger-form />`.
 */
@Injectable({ providedIn: 'root' })
export class LedgerDialog {
  readonly open = signal(false);
  readonly entry = signal<LedgerEntry | null>(null);
  /** Pre-selects the truck when adding from a truck's own sheet. */
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

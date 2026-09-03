import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import {
  LedgerEntry,
  LedgerEntryPayload,
  PeriodRollup,
  QuarterKey,
  Truck,
} from '../../models/finance/finance.model';
import { Granularity, SalesReport } from '../../models/finance/sales.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

/**
 * The three Finance modules — Daily Trip Monitoring, Profitability and
 * Quarterly Summary — over one ledger.
 *
 * Profitability and Summary hit the same server-side roll-up with different
 * ranges, so the two pages cannot print different arithmetic.
 */
@Injectable({ providedIn: 'root' })
export class FinanceService {
  private readonly api = inject(ApiService);

  /** Every unit, including the ones with no plate yet. */
  trucks(): Observable<Truck[]> {
    return this.api.get<Truck[]>('finance/trucks');
  }

  createTruck(payload: { label: string; plate?: string | null; vehicle_id?: string | null }): Observable<Truck> {
    return this.api.post<Truck>('finance/trucks', payload);
  }

  updateTruck(id: string, payload: Partial<Truck>): Observable<Truck> {
    return this.api.patch<Truck>(`finance/trucks/${id}`, payload);
  }

  removeTruck(id: string): Observable<void> {
    return this.api.delete(`finance/trucks/${id}`);
  }

  /** Routes already used, to suggest in the entry form. */
  routes(): Observable<string[]> {
    return this.api.get<string[]>('finance/routes');
  }

  /** Daily Trip Monitoring: the rows themselves. */
  ledger(query?: ListQuery): Observable<Envelope<LedgerEntry[]>> {
    return this.api.envelope<LedgerEntry[]>('ledger', query);
  }

  create(payload: LedgerEntryPayload): Observable<LedgerEntry> {
    return this.api.post<LedgerEntry>('ledger', payload);
  }

  update(id: string, payload: Partial<LedgerEntryPayload>): Observable<LedgerEntry> {
    return this.api.patch<LedgerEntry>(`ledger/${id}`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`ledger/${id}`);
  }

  /** The workbook's 10-day window, or any range the caller names. */
  profitability(range?: { from: string; to: string }): Observable<PeriodRollup> {
    return this.api.get<PeriodRollup>('finance/profitability', range);
  }

  /**
   * Sales by day, week or month.
   *
   * The window is left to the API when the caller names none: the useful view
   * of each granularity is a different length of history — a month of days, a
   * quarter of weeks, a year of months — and deciding that in two places is
   * how the two start to disagree.
   */
  sales(granularity: Granularity, range?: { from: string; to: string }): Observable<SalesReport> {
    return this.api.get<SalesReport>('finance/sales', { granularity, ...range });
  }

  /**
   * The same roll-up over a quarter. The envelope, because `meta` carries the
   * quarter list the slicer is built from.
   */
  summary(quarter?: QuarterKey, year?: number): Observable<Envelope<PeriodRollup>> {
    const params = new URLSearchParams();
    if (quarter) params.set('quarter', quarter);
    if (year) params.set('year', String(year));

    const query = params.toString();

    return this.api.envelope<PeriodRollup>(
      query === '' ? 'finance/summary' : `finance/summary?${query}`,
    );
  }
}

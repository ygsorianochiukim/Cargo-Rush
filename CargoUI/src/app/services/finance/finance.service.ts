import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { Payables } from '../../models/finance/payables.model';
import { ApiService } from '../shared/api.service';
import {
  DateRange,
  ExpenseLines,
  LedgerEntry,
  LedgerEntryPayload,
  PeriodRollup,
  QuarterKey,
  ReceivableLines,
  Truck,
} from '../../models/finance/finance.model';
import { Granularity, SalesReport } from '../../models/finance/sales.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

@Injectable({ providedIn: 'root' })
export class FinanceService {
  private readonly api = inject(ApiService);

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

  routes(): Observable<string[]> {
    return this.api.get<string[]>('finance/routes');
  }

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

  profitability(range?: { from: string; to: string }): Observable<PeriodRollup> {
    return this.api.get<PeriodRollup>('finance/profitability', range);
  }

  payables(): Observable<Payables> {
    return this.api.get<Payables>('finance/payables');
  }

  sales(granularity: Granularity, range?: { from: string; to: string }): Observable<SalesReport> {
    return this.api.get<SalesReport>('finance/sales', { granularity, ...range });
  }

  summary(quarter?: QuarterKey, year?: number): Observable<Envelope<PeriodRollup>> {
    const params = new URLSearchParams();
    if (quarter) params.set('quarter', quarter);
    if (year) params.set('year', String(year));

    const query = params.toString();

    return this.api.envelope<PeriodRollup>(
      query === '' ? 'finance/summary' : `finance/summary?${query}`,
    );
  }

  expenseLines(range: DateRange): Observable<ExpenseLines> {
    return this.api.get<ExpenseLines>('finance/expense-lines', { ...range });
  }

  receivableLines(asOf: string): Observable<ReceivableLines> {
    return this.api.get<ReceivableLines>('finance/receivable-lines', { as_of: asOf });
  }
}

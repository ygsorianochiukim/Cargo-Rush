import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import {
  Aging,
  BillingTotals,
  Invoice,
  InvoicePayload,
  Payment,
  PaymentPayload,
} from '../../models/billing/billing.model';
import { InvoiceDocument } from '../../models/billing/invoice-document.model';
import { StatementOfAccount } from '../../models/billing/statement-of-account.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';
import { environment } from '../../../environments/environment';

@Injectable({ providedIn: 'root' })
export class BillingService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Invoice[]>> {
    return this.api.envelope<Invoice[]>('billing', query);
  }

  find(id: string): Observable<Invoice> {
    return this.api.get<Invoice>(`billing/${id}`);
  }

  create(payload: InvoicePayload): Observable<Invoice> {
    return this.api.post<Invoice>('billing', payload);
  }

  update(id: string, payload: Partial<InvoicePayload>): Observable<Invoice> {
    return this.api.patch<Invoice>(`billing/${id}`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`billing/${id}`);
  }

  settle(id: string, details: Partial<PaymentPayload> = {}): Observable<Invoice> {
    return this.api.post<Invoice>(`billing/${id}/settle`, details);
  }

  document(id: string): Observable<InvoiceDocument> {
    return this.api.get<InvoiceDocument>(`billing/${id}/document`);
  }

  documentPdf(id: string): Observable<Blob> {
    return this.api.blob(`billing/${id}/document.pdf`);
  }

  exportUrl(query: ListQuery = {}): string {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query)) {
      if (value === null || value === undefined || value === '') continue;

      params.set(key, String(value));
    }

    const search = params.toString();

    return `${environment.apiUrl}/api/v1/billing/export${search === '' ? '' : '?' + search}`;
  }

  totals(): Observable<BillingTotals> {
    return this.api.get<BillingTotals>('billing/totals');
  }

  aging(direction: 'receivable' | 'payable' = 'receivable'): Observable<Aging> {
    return this.api.get<Aging>('billing/aging', { direction });
  }

  statement(
    customerId: string,
    query: { from?: string; to?: string; direction?: 'receivable' | 'payable' } = {},
  ): Observable<StatementOfAccount> {
    return this.api.get<StatementOfAccount>(`billing/statement/${customerId}`, query);
  }

  payments(query?: ListQuery): Observable<Envelope<Payment[]>> {
    return this.api.envelope<Payment[]>('payments', query);
  }

  recordPayment(payload: PaymentPayload): Observable<Payment> {
    return this.api.post<Payment>('payments', payload);
  }

  removePayment(id: string): Observable<void> {
    return this.api.delete(`payments/${id}`);
  }
}

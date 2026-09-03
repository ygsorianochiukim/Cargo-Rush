import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import {
  BillingTotals,
  Invoice,
  InvoicePayload,
} from '../../models/billing/billing.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

/** Billing & Invoice — receivables, payables, payment history. */
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

  /** Marking one paid, as a verb rather than a status patch. */
  settle(id: string): Observable<Invoice> {
    return this.api.post<Invoice>(`billing/${id}/settle`, {});
  }

  totals(): Observable<BillingTotals> {
    return this.api.get<BillingTotals>('billing/totals');
  }
}

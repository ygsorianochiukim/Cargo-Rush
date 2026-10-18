import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { PaymentSource, PaymentSourcePayload } from '../../models/accounting/payment-source.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';
import { ApiService } from '../shared/api.service';

@Injectable({ providedIn: 'root' })
export class PaymentSourceService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<PaymentSource[]>> {
    return this.api.envelope<PaymentSource[]>('payment-sources', query);
  }

  create(payload: PaymentSourcePayload): Observable<PaymentSource> {
    return this.api.post<PaymentSource>('payment-sources', payload);
  }

  update(id: string, payload: Partial<PaymentSourcePayload>): Observable<PaymentSource> {
    return this.api.patch<PaymentSource>(`payment-sources/${id}`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`payment-sources/${id}`);
  }

  options(): Observable<{ value: string; label: string }[]> {
    return this.list({ active: 1, per_page: 100 } as never).pipe(
      map((res) =>
        res.data.map((s) => ({ value: s.id, label: s.name })),
      ),
    );
  }
}

import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { Invoice } from '../../models/billing/billing.model';
import { Customer, CustomerPayload } from '../../models/customer/customer.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';
import { Trip } from '../../models/trip/trip.model';

/** Customer Management — records, transaction history, feedback. */
@Injectable({ providedIn: 'root' })
export class CustomerService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Customer[]>> {
    return this.api.envelope<Customer[]>('customers', query);
  }

  find(id: string): Observable<Customer> {
    return this.api.get<Customer>(`customers/${id}`);
  }

  create(payload: CustomerPayload): Observable<Customer> {
    return this.api.post<Customer>('customers', payload);
  }

  update(id: string, payload: Partial<CustomerPayload>): Observable<Customer> {
    return this.api.patch<Customer>(`customers/${id}`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`customers/${id}`);
  }

  /** The trips and invoices behind one customer. */
  history(id: string): Observable<{ trips: Trip[]; invoices: Invoice[] }> {
    return this.api.get<{ trips: Trip[]; invoices: Invoice[] }>(
      `customers/${id}/history`,
    );
  }
}

import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { Envelope, ListQuery } from '../../models/shared/envelope.model';
import { Supplier, SupplierPayload } from '../../models/supplier/supplier.model';
import { ApiService } from '../shared/api.service';

/** Suppliers — who the fleet buys from, and what it has spent there. */
@Injectable({ providedIn: 'root' })
export class SupplierService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Supplier[]>> {
    return this.api.envelope<Supplier[]>('suppliers', query);
  }

  find(id: string): Observable<Supplier> {
    return this.api.get<Supplier>(`suppliers/${id}`);
  }

  create(payload: SupplierPayload): Observable<Supplier> {
    return this.api.post<Supplier>('suppliers', payload);
  }

  update(id: string, payload: SupplierPayload): Observable<Supplier> {
    return this.api.patch<Supplier>(`suppliers/${id}`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`suppliers/${id}`);
  }
}

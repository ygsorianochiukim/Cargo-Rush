import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { Envelope, ListQuery } from '../../models/shared/envelope.model';
import { Supplier, SupplierCategory, SupplierPayload } from '../../models/supplier/supplier.model';
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

  /**
   * Every active supplier, as picker options.
   *
   * Sorted by category and then by name, with the category beside each name,
   * so a long list reads as groups — the garages together, the food stalls
   * together — rather than one run of names. A hundred is the API's page cap;
   * asking for nothing got 25 and silently cut the picker short.
   */
  options(): Observable<{ value: string; label: string }[]> {
    return this.list({ active: 1, per_page: 100 } as never).pipe(
      map((res) =>
        [...res.data]
          .sort(
            (a, b) =>
              // Uncategorised shops last, not first.
              (a.category_name ?? '￿').localeCompare(b.category_name ?? '￿') ||
              a.name.localeCompare(b.name),
          )
          .map((s) => ({
            value: s.id,
            label: s.category_name ? `${s.name} · ${s.category_name}` : s.name,
          })),
      ),
    );
  }

  /* ------------------------------------------------------------ Categories */

  /** `activeOnly` drops the retired ones, which is what a form offers. */
  categories(activeOnly = false): Observable<SupplierCategory[]> {
    return this.api.get<SupplierCategory[]>(
      'supplier-categories',
      activeOnly ? { active: 1 } : undefined,
    );
  }

  createCategory(payload: { name: string }): Observable<SupplierCategory> {
    return this.api.post<SupplierCategory>('supplier-categories', payload);
  }

  updateCategory(
    id: string,
    payload: { name?: string; status?: string },
  ): Observable<SupplierCategory> {
    return this.api.patch<SupplierCategory>(`supplier-categories/${id}`, payload);
  }

  /** Deletes an unused category; one with suppliers under it is switched off instead. */
  removeCategory(id: string): Observable<void> {
    return this.api.delete(`supplier-categories/${id}`);
  }
}

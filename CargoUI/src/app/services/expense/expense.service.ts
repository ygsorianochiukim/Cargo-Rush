import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import {
  Expense,
  ExpenseCategory,
  ExpenseCategoryPayload,
  ExpensePayload,
  ExpenseReport,
} from '../../models/expense/expense.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

/** Other Expenses — the categorised spend beside the ledger's five columns. */
@Injectable({ providedIn: 'root' })
export class ExpenseService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Expense[]>> {
    return this.api.envelope<Expense[]>('expenses', query);
  }

  create(payload: ExpensePayload): Observable<Expense> {
    return this.api.post<Expense>('expenses', payload);
  }

  update(id: string, payload: Partial<ExpensePayload>): Observable<Expense> {
    return this.api.patch<Expense>(`expenses/${id}`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`expenses/${id}`);
  }

  /** Where the money went. Defaults to the current month, API-side. */
  report(range?: { from: string; to: string }): Observable<ExpenseReport> {
    return this.api.get<ExpenseReport>('expenses/report', range);
  }

  /** `active` drops the retired ones, which is what a form wants to offer. */
  categories(activeOnly = false): Observable<ExpenseCategory[]> {
    return this.api.get<ExpenseCategory[]>(
      'expenses/categories',
      activeOnly ? { active: 1 } : undefined,
    );
  }

  createCategory(payload: ExpenseCategoryPayload): Observable<ExpenseCategory> {
    return this.api.post<ExpenseCategory>('expenses/categories', payload);
  }

  updateCategory(
    id: string,
    payload: Partial<ExpenseCategoryPayload>,
  ): Observable<ExpenseCategory> {
    return this.api.patch<ExpenseCategory>(`expenses/categories/${id}`, payload);
  }

  /**
   * Delete a category, or retire it.
   *
   * Two outcomes, and the caller has to be able to tell them apart: a category
   * nothing is filed against is deleted, and one with spend against it is
   * switched off instead — deleting it would take the spend with it and a
   * closed quarter would stop balancing. The page re-reads the list afterwards
   * and says which happened, rather than leaving a row that greys out on some
   * presses and vanishes on others with no explanation.
   */
  removeCategory(id: string): Observable<void> {
    return this.api.delete(`expenses/categories/${id}`);
  }
}

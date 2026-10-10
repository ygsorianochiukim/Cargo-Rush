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

@Injectable({ providedIn: 'root' })
export class ExpenseService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Expense[]>> {
    return this.api.envelope<Expense[]>('expenses', query);
  }

  find(id: string): Observable<Expense> {
    return this.api.get<Expense>(`expenses/${id}`);
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

  report(range?: { from: string; to: string }): Observable<ExpenseReport> {
    return this.api.get<ExpenseReport>('expenses/report', range);
  }

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

  removeCategory(id: string): Observable<void> {
    return this.api.delete(`expenses/categories/${id}`);
  }
}

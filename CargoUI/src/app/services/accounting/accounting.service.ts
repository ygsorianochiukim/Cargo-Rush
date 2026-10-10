import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import {
  Account,
  AccountPayload,
  AccountTypeOption,
  JournalCategoryOption,
  JournalEntry,
  JournalEntryPayload,
  LedgerPage,
  LedgerSummary,
  TrialBalance,
} from '../../models/accounting/accounting.model';
import { BalanceSheet, IncomeStatement } from '../../models/accounting/statement.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

export interface JournalQuery extends ListQuery {
  category?: string;
  account_id?: string;
  from?: string;
  to?: string;
}

@Injectable({ providedIn: 'root' })
export class AccountingService {
  private readonly api = inject(ApiService);

  accounts(activeOnly = false): Observable<Envelope<Account[]>> {
    return this.api.envelope<Account[]>(
      'accounting/accounts',
      activeOnly ? { active: 1 } : undefined,
    );
  }

  accountTypes(): Observable<AccountTypeOption[]> {
    return this.api.get<AccountTypeOption[]>('accounting/accounts/types');
  }

  createAccount(payload: AccountPayload): Observable<Account> {
    return this.api.post<Account>('accounting/accounts', payload);
  }

  updateAccount(id: string, payload: Partial<AccountPayload>): Observable<Account> {
    return this.api.patch<Account>(`accounting/accounts/${id}`, payload);
  }

  removeAccount(id: string): Observable<void> {
    return this.api.delete(`accounting/accounts/${id}`);
  }

  journal(query?: JournalQuery): Observable<Envelope<JournalEntry[]>> {
    return this.api.envelope<JournalEntry[]>('accounting/journal', query);
  }

  categories(): Observable<JournalCategoryOption[]> {
    return this.api.get<JournalCategoryOption[]>('accounting/journal/categories');
  }

  entry(id: string): Observable<JournalEntry> {
    return this.api.get<JournalEntry>(`accounting/journal/${id}`);
  }

  createEntry(payload: JournalEntryPayload): Observable<JournalEntry> {
    return this.api.post<JournalEntry>('accounting/journal', payload);
  }

  updateEntry(id: string, payload: Partial<JournalEntryPayload>): Observable<JournalEntry> {
    return this.api.patch<JournalEntry>(`accounting/journal/${id}`, payload);
  }

  postEntry(id: string): Observable<JournalEntry> {
    return this.api.post<JournalEntry>(`accounting/journal/${id}/post`, {});
  }

  voidEntry(id: string, reason: string): Observable<JournalEntry> {
    return this.api.post<JournalEntry>(`accounting/journal/${id}/void`, { reason });
  }

  removeEntry(id: string): Observable<void> {
    return this.api.delete(`accounting/journal/${id}`);
  }

  ledger(range?: { from?: string; to?: string }): Observable<LedgerSummary> {
    return this.api.get<LedgerSummary>('accounting/ledger', range);
  }

  ledgerAccount(id: string, range?: { from?: string; to?: string }): Observable<LedgerPage> {
    return this.api.get<LedgerPage>(`accounting/ledger/accounts/${id}`, range);
  }

  trialBalance(asOf?: string): Observable<TrialBalance> {
    return this.api.get<TrialBalance>(
      'accounting/ledger/trial-balance',
      asOf ? { as_of: asOf } : undefined,
    );
  }

  incomeStatement(range?: { from?: string; to?: string }): Observable<IncomeStatement> {
    return this.api.get<IncomeStatement>('accounting/statements/income', range);
  }

  balanceSheet(asOf?: string): Observable<BalanceSheet> {
    return this.api.get<BalanceSheet>(
      'accounting/statements/balance-sheet',
      asOf ? { as_of: asOf } : undefined,
    );
  }
}

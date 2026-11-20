import { DatePipe, KeyValuePipe, TitleCasePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';

import { ApiService } from '../../services/shared/api.service';
import { Card } from '../../shared/card';
import { fmt } from '../../shared/format';
import { saveFile } from '../../shared/save-file';

type Fields = Record<string, string | null>;
interface Receipt {
  id: string;
  filename: string;
  status: 'pending' | 'archived' | 'confirmed';
  expense_id: string | null;
  created_at: string;
  archived_at: string | null;
  fields: Fields;
  history: { revision: number; fields: Fields; replaced_at: string }[];
}

interface RemovedExpense {
  id: string;
  payee: string | null;
  reference: string | null;
  date: string | null;
  amount_cents: number;
  currency: string;
  note: string | null;
  deleted_at: string;
}

@Component({
  selector: 'app-expense-archive',
  imports: [Card, DatePipe, KeyValuePipe, RouterLink, TitleCasePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './expense-archive.page.html',
})
export class ExpenseArchivePage {
  private readonly api = inject(ApiService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly receipts = signal<Receipt[]>([]);
  protected readonly expenses = signal<RemovedExpense[]>([]);
  protected readonly error = signal('');
  protected readonly receiptPage = signal(0);
  protected readonly expensePage = signal(0);
  protected readonly moreReceipts = signal(true);
  protected readonly moreExpenses = signal(true);
  protected readonly loading = signal(false);
  protected readonly loadingExpenses = signal(false);
  protected readonly fmt = fmt;

  constructor() {
    this.loadReceipts();
    this.loadExpenses();
  }

  protected loadReceipts(): void {
    if (this.loading()) return;
    const page = this.receiptPage() + 1;
    this.loading.set(true);
    this.api.envelope<Receipt[]>('expenses/receipt-archive', { page }).pipe(
      takeUntilDestroyed(this.destroyRef),
    ).subscribe({
      next: ({ data, meta }) => {
        this.receipts.update((items) => [...items, ...data]);
        this.receiptPage.set(page);
        this.moreReceipts.set(this.receipts().length < Number(meta?.['total'] ?? 0));
        this.loading.set(false);
      },
      error: () => {
        this.error.set('Could not load receipt history.');
        this.loading.set(false);
      },
    });
  }

  protected loadExpenses(): void {
    if (this.loadingExpenses()) return;
    const page = this.expensePage() + 1;
    this.loadingExpenses.set(true);
    this.api.envelope<RemovedExpense[]>('expenses/archived', { page }).pipe(
      takeUntilDestroyed(this.destroyRef),
    ).subscribe({
      next: ({ data, meta }) => {
        this.expenses.update((items) => [...items, ...data]);
        this.expensePage.set(page);
        this.moreExpenses.set(this.expenses().length < Number(meta?.['total'] ?? 0));
        this.loadingExpenses.set(false);
      },
      error: () => {
        this.error.set('Could not load archived expenses.');
        this.loadingExpenses.set(false);
      },
    });
  }

  protected fieldLabel(key: string): string {
    return key.replaceAll('_', ' ');
  }

  protected download(receipt: Receipt): void {
    this.api.blob('expenses/receipt-archive/' + receipt.id + '/original').pipe(
      takeUntilDestroyed(this.destroyRef),
    ).subscribe({
      next: (blob) => saveFile(blob, receipt.filename),
      error: () => this.error.set('Could not download the original receipt.'),
    });
  }
}

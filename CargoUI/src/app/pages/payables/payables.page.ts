import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Observable } from 'rxjs';

import { Payables, PayableLine } from '../../models/finance/payables.model';
import { BillingService } from '../../services/billing/billing.service';
import { expenseSpec } from '../../services/expense/expense.form';
import { ExpenseService } from '../../services/expense/expense.service';
import { FinanceService } from '../../services/finance/finance.service';
import { Card } from '../../shared/card';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { Modal } from '../../shared/modal';
import { PaymentDialog } from '../../shared/payment-dialog';
import { RecordDialog } from '../../shared/record-dialog';
import { EmptyState, ErrorState, SkeletonRows } from '../../shared/states';
import { WalletPanel } from '../../shared/wallet-panel';
import { PayrollSettleDialog } from './payroll-settle-dialog';

@Component({
  selector: 'app-payables',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    Card,
    Icon,
    Modal,
    EmptyState,
    ErrorState,
    SkeletonRows,
    PayrollSettleDialog,
    WalletPanel,
  ],
  templateUrl: './payables.page.html',
})
export class PayablesPage {
  private readonly finance = inject(FinanceService);
  private readonly billing = inject(BillingService);
  private readonly expenses = inject(ExpenseService);
  private readonly payments = inject(PaymentDialog);
  private readonly records = inject(RecordDialog);

  private readonly expenseForm = expenseSpec();

  protected readonly fmt = fmt;

  protected readonly payables = signal<Payables | null>(null);

  protected readonly walletFor = signal<PayableLine | null>(null);

  protected readonly opening = signal<string | null>(null);

  protected readonly failure = signal<string | null>(null);

  constructor() {
    this.reload();

    this.payments.recorded.pipe(takeUntilDestroyed()).subscribe(() => this.reload());
    this.records
      .savedFor(this.expenseForm)
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.reload());
  }

  protected readonly groups = computed(() =>
    (this.payables()?.groups ?? []).filter((group) => group.count > 0),
  );

  protected readonly owesNothing = computed(
    () => this.payables() !== null && this.groups().length === 0,
  );

  protected readonly payrollFor = signal<PayableLine | null>(null);

  protected settle(line: PayableLine): void {
    this.failure.set(null);

    switch (line.settle_at) {
      case '/truckers':
        this.walletFor.set(line);

        return;

      case '/payroll':
        this.payrollFor.set(line);

        return;

      case '/billing':
        this.fetch(line, this.billing.find(line.id), (invoice) => {
          if (invoice.balance_cents > 0) this.payments.forInvoice(invoice);
          else this.reload();
        });

        return;

      case '/expenses':
        this.fetch(line, this.expenses.find(line.id), (expense) =>
          this.records.edit(this.expenseForm, expense),
        );

        return;
    }
  }

  protected closeWallet(): void {
    this.walletFor.set(null);
  }

  protected payrollSettled(): void {
    this.payrollFor.set(null);
    this.reload();
  }

  protected reload(): void {
    this.finance.payables().subscribe({
      next: (payables) => this.payables.set(payables),
      error: () => this.failure.set('Could not load what is owed. Check the connection.'),
    });
  }

  private fetch<T>(line: PayableLine, source: Observable<T>, then: (record: T) => void): void {
    this.opening.set(line.id);

    source.subscribe({
      next: (record) => {
        this.opening.set(null);
        then(record);
      },
      error: (error: HttpErrorResponse) => {
        this.opening.set(null);
        this.failure.set(
          error.status === 403
            ? 'You can see this debt but not settle it. Ask someone who manages it.'
            : error.status === 404
              ? 'That record is gone. The list has been refreshed.'
              : 'Could not open that. Check the connection and try again.',
        );

        if (error.status === 404) this.reload();
      },
    });
  }

  protected money = fmt.pesos;
  protected date = fmt.date;
}

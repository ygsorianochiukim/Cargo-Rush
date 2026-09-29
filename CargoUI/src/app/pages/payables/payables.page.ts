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

/**
 * Payables — everything the fleet owes, in one list.
 *
 * The money going out had grown into four places that never met: a partner's
 * wallet, a hired truck's monthly rent, a supplier's bill, and whatever else
 * somebody filed as spend. Each screen was right about its own corner, and
 * nobody could answer "what do we owe this week" without opening all four and
 * adding up by hand.
 *
 * ## Settling happens here, in each flow's own dialog
 *
 * The three settle flows behind these lines are genuinely different — picking
 * which runs a partner payment covers, allocating money against a bill,
 * marking an expense paid — so this page does not flatten them into one form.
 * It opens the dialog that owns each one, over this page, instead of sending
 * the reader to that screen and making them find their way back:
 *
 *   a partner or a rented truck's owner — the wallet panel from Truckers;
 *   a supplier bill — the payment dialog from Billing;
 *   an expense — the expense form.
 *
 *   payroll — a confirmation: the take-home pay was released, or the
 *   contributions and tax were remitted (see `PayrollSettleDialog`).
 *
 * The permissions stay honest because the dialogs are the same ones: this page
 * is `finance.view`, and whatever moves money is still refused by the API
 * without `truckers.manage`, `billing.manage` or the expense permission.
 */
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

  /** Null while loading — the states depend on telling that apart from empty. */
  protected readonly payables = signal<Payables | null>(null);

  /** The partner whose wallet is open over the list, if any. */
  protected readonly walletFor = signal<PayableLine | null>(null);

  /** Which line is being fetched for its dialog, so its button can say so. */
  protected readonly opening = signal<string | null>(null);

  protected readonly failure = signal<string | null>(null);

  constructor() {
    this.reload();

    // Whatever was settled in a dialog changes what is owed.
    this.payments.recorded.pipe(takeUntilDestroyed()).subscribe(() => this.reload());
    this.records
      .savedFor(this.expenseForm)
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.reload());
  }

  /**
   * Only the groups that have something in them.
   *
   * A fleet that hires no trucks should not be shown an empty "Rented trucks"
   * heading every week; the absence of the section is the answer.
   */
  protected readonly groups = computed(() =>
    (this.payables()?.groups ?? []).filter((group) => group.count > 0),
  );

  protected readonly owesNothing = computed(
    () => this.payables() !== null && this.groups().length === 0,
  );

  /** The payroll line being confirmed — paid, or remitted — if any. */
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
          // A bill paid off since the list was read has nothing left to take.
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

  /**
   * To the centavo, like the period pages.
   *
   * Every figure on this screen is part of something somebody adds up — a
   * group's lines against its subtotal, the subtotals against the total at the
   * top — and the Quarterly Summary now prints the same outstanding figure on
   * a tile of its own. Rounded to whole pesos, a column of four lines could
   * miss its own total by two pesos and the two screens could disagree about
   * the same debt.
   */
  protected money = fmt.pesos;
  protected date = fmt.date;
}

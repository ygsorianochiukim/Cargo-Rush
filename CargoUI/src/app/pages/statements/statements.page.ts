import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';

import { BalanceSheet, IncomeStatement } from '../../models/accounting/statement.model';
import { Aging, InvoiceDirection } from '../../models/billing/billing.model';
import { StatementOfAccount } from '../../models/billing/statement-of-account.model';
import { Customer } from '../../models/customer/customer.model';
import { AccountingService } from '../../services/accounting/accounting.service';
import { BillingService } from '../../services/billing/billing.service';
import { CustomerService } from '../../services/customer/customer.service';
import { Card } from '../../shared/card';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { EmptyState, ErrorState } from '../../shared/states';

type View = 'income' | 'sheet' | 'aging' | 'account';

@Component({
  selector: 'app-statements',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Field, Icon, EmptyState, ErrorState],
  templateUrl: './statements.page.html',
})
export class StatementsPage {
  private readonly accounting = inject(AccountingService);
  private readonly billing = inject(BillingService);
  private readonly customers = inject(CustomerService);

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly view = signal<View>('income');

  protected readonly income = signal<IncomeStatement | null>(null);
  protected readonly sheet = signal<BalanceSheet | null>(null);
  protected readonly aging = signal<Aging | null>(null);
  protected readonly account = signal<StatementOfAccount | null>(null);
  protected readonly error = signal<string | null>(null);

  protected readonly from = signal<string>(startOfYear());
  protected readonly to = signal<string>(today());

  protected readonly asOf = signal<string>(today());

  protected readonly direction = signal<InvoiceDirection>('receivable');

  protected readonly customerId = signal<string>('');
  protected readonly roster = signal<Customer[]>([]);

  protected readonly directionLabel = computed(() =>
    this.direction() === 'receivable' ? 'Owed to us' : 'Owed by us',
  );

  protected readonly agingBuckets = computed(() => {
    const report = this.aging();

    if (report === null) return [];

    return [
      { label: 'Not yet due', cents: report.buckets.current, late: false },
      { label: '1–30 days', cents: report.buckets['1_30'], late: true },
      { label: '31–60 days', cents: report.buckets['31_60'], late: true },
      { label: '61–90 days', cents: report.buckets['61_90'], late: true },
      { label: 'Over 90 days', cents: report.buckets.over_90, late: true },
    ];
  });

  protected readonly otherExpenses = computed(() => {
    const statement = this.income();

    if (statement === null || statement.cost_of_services_cents === null) return null;

    return statement.expenses.total_cents - statement.cost_of_services_cents;
  });

  protected readonly initials = computed(() => {
    const name = this.account()?.issuer.name ?? '';

    return (
      name
        .split(/\s+/)
        .filter((part) => part !== '')
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('') || '—'
    );
  });

  constructor() {
    this.loadIncome();
    this.customers.list({ per_page: 200 }).subscribe({
      next: (page) => this.roster.set(page.data),
      error: () => this.roster.set([]),
    });
  }

  protected setView(view: View): void {
    this.view.set(view);
    this.error.set(null);

    if (view === 'sheet' && this.sheet() === null) this.loadSheet();
    if (view === 'aging' && this.aging() === null) this.loadAging();
    if (view === 'account' && this.customerId() !== '') this.loadAccount();
  }

  protected setFrom(value: string): void {
    this.from.set(value);
    this.reload();
  }

  protected setTo(value: string): void {
    this.to.set(value);
    this.reload();
  }

  protected setAsOf(value: string): void {
    this.asOf.set(value);
    this.loadSheet();
  }

  protected setDirection(direction: InvoiceDirection): void {
    this.direction.set(direction);

    if (this.view() === 'aging') this.loadAging();
    else if (this.customerId() !== '') this.loadAccount();
  }

  protected chooseCustomer(id: string): void {
    this.customerId.set(id);

    if (id === '') {
      this.account.set(null);

      return;
    }

    this.loadAccount();
  }

  protected reload(): void {
    if (this.view() === 'sheet') this.loadSheet();
    else if (this.view() === 'aging') this.loadAging();
    else if (this.view() === 'account' && this.customerId() !== '') this.loadAccount();
    else this.loadIncome();
  }

  protected print(): void {
    window.print();
  }

  private range(): { from?: string; to?: string } {
    const range: { from?: string; to?: string } = {};

    if (this.from() !== '') range.from = this.from();
    if (this.to() !== '') range.to = this.to();

    return range;
  }

  private loadIncome(): void {
    this.accounting.incomeStatement(this.range()).subscribe({
      next: (statement) => {
        this.income.set(statement);
        this.error.set(null);
      },
      error: () => {
        this.income.set(null);
        this.error.set('Could not load the income statement. Check the connection and try again.');
      },
    });
  }

  private loadSheet(): void {
    this.accounting.balanceSheet(this.asOf() || undefined).subscribe({
      next: (sheet) => {
        this.sheet.set(sheet);
        this.error.set(null);
      },
      error: () => {
        this.sheet.set(null);
        this.error.set('Could not load the balance sheet.');
      },
    });
  }

  private loadAging(): void {
    this.billing.aging(this.direction()).subscribe({
      next: (report) => {
        this.aging.set(report);
        this.error.set(null);
      },
      error: () => {
        this.aging.set(null);
        this.error.set('Could not load the aging report.');
      },
    });
  }

  private loadAccount(): void {
    this.billing
      .statement(this.customerId(), { ...this.range(), direction: this.direction() })
      .subscribe({
        next: (statement) => {
          this.account.set(statement);
          this.error.set(null);
        },
        error: () => {
          this.account.set(null);
          this.error.set('Could not load that statement of account.');
        },
      });
  }
}

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function startOfYear(): string {
  return `${new Date().getFullYear()}-01-01`;
}

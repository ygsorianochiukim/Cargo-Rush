import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';

import {
  LedgerPage as AccountLedger,
  LedgerSummary,
  TrialBalance,
} from '../../models/accounting/accounting.model';
import { AccountingService } from '../../services/accounting/accounting.service';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { ErrorState } from '../../shared/states';

type View = 'chart' | 'account' | 'trial';

@Component({
  selector: 'app-ledger',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, Field, Icon, ErrorState],
  templateUrl: './ledger.page.html',
})
export class LedgerPage {
  private readonly accounting = inject(AccountingService);

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly view = signal<View>('chart');

  protected readonly summary = signal<LedgerSummary | null>(null);
  protected readonly account = signal<AccountLedger | null>(null);
  protected readonly trial = signal<TrialBalance | null>(null);
  protected readonly error = signal<string | null>(null);

  protected readonly accountId = signal<string>('');
  protected readonly from = signal<string>('');
  protected readonly to = signal<string>('');

  protected readonly pickable = computed(() =>
    (this.summary()?.types ?? []).flatMap((type) =>
      type.accounts.map((account) => ({
        id: account.id,
        label: `${account.code} · ${account.name}`,
        type: type.label,
      })),
    ),
  );

  constructor() {
    this.loadChart();
  }

  protected setView(view: View): void {
    this.view.set(view);

    if (view === 'trial' && this.trial() === null) this.loadTrial();
    if (view === 'account' && this.accountId() !== '') this.loadAccount();
  }

  protected setRange(which: 'from' | 'to', value: string): void {
    (which === 'from' ? this.from : this.to).set(value);
    this.reload();
  }

  protected chooseAccount(id: string): void {
    this.accountId.set(id);

    if (id === '') {
      this.account.set(null);

      return;
    }

    this.view.set('account');
    this.loadAccount();
  }

  protected reload(): void {
    if (this.view() === 'account' && this.accountId() !== '') this.loadAccount();
    else if (this.view() === 'trial') this.loadTrial();
    else this.loadChart();
  }

  private range(): { from?: string; to?: string } {
    const range: { from?: string; to?: string } = {};

    if (this.from() !== '') range.from = this.from();
    if (this.to() !== '') range.to = this.to();

    return range;
  }

  private loadChart(): void {
    this.accounting.ledger(this.range()).subscribe({
      next: (summary) => {
        this.summary.set(summary);
        this.error.set(null);
      },
      error: () => {
        this.summary.set(null);
        this.error.set('Could not load the ledger. Check the connection and try again.');
      },
    });
  }

  private loadAccount(): void {
    this.accounting.ledgerAccount(this.accountId(), this.range()).subscribe({
      next: (page) => {
        this.account.set(page);
        this.error.set(null);
      },
      error: () => {
        this.account.set(null);
        this.error.set('Could not load that account.');
      },
    });
  }

  private loadTrial(): void {
    this.accounting.trialBalance(this.to() || undefined).subscribe({
      next: (trial) => {
        this.trial.set(trial);
        this.error.set(null);
      },
      error: () => {
        this.trial.set(null);
        this.error.set('Could not load the trial balance.');
      },
    });
  }

  protected readonly ledgerColumns: Column<AccountLedger['lines'][number]>[] = [
    { label: 'Date', kind: 'num', value: (l) => fmt.date(l.entry_date) },
    { label: 'Reference', kind: 'strong', value: (l) => l.reference },
    { label: 'Narration', value: (l) => l.memo, sub: (l) => l.category },
    {
      label: 'Debit',
      kind: 'num',
      value: (l) => (l.debit_cents ? fmt.money(l.debit_cents) : null),
    },
    {
      label: 'Credit',
      kind: 'num',
      value: (l) => (l.credit_cents ? fmt.money(l.credit_cents) : null),
    },
    { label: 'Balance', kind: 'num', value: (l) => fmt.money(l.balance_cents) },
  ];

  protected readonly trialColumns: Column<TrialBalance['rows'][number]>[] = [
    { label: 'Number', kind: 'strong', value: (r) => r.code },
    { label: 'Account', value: (r) => r.name, sub: (r) => r.group },
    { label: 'Kind', kind: 'muted', value: (r) => r.type_label },
    {
      label: 'Debit',
      kind: 'num',
      value: (r) => (r.debit_cents ? fmt.money(r.debit_cents) : null),
    },
    {
      label: 'Credit',
      kind: 'num',
      value: (r) => (r.credit_cents ? fmt.money(r.credit_cents) : null),
    },
  ];
}

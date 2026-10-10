import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { map } from 'rxjs';

import { Account, AccountType } from '../../models/accounting/accounting.model';
import { accountSpec } from '../../services/accounting/account.form';
import { AccountingService } from '../../services/accounting/accounting.service';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { ListToolbar } from '../../shared/list-toolbar';
import { recordList } from '../../shared/record-list';
import { ErrorState } from '../../shared/states';

@Component({
  selector: 'app-accounts',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, Icon, ListToolbar, ErrorState],
  templateUrl: './accounts.page.html',
})
export class AccountsPage {
  private readonly accounting = inject(AccountingService);

  protected readonly fmt = fmt;

  private readonly spec = accountSpec();

  protected readonly list = recordList<Account>(this.spec, () =>
    this.accounting.accounts().pipe(map((res) => res.data)),
  );

  protected readonly rows = this.list.rows;

  private readonly collapsed = signal<Record<string, boolean>>({});

  protected readonly sections = computed(() => {
    const rows = this.rows();

    if (rows === null) return null;

    const order: AccountType[] = ['asset', 'liability', 'equity', 'income', 'expense'];
    const seen = new Map<AccountType, { type: AccountType; label: string; rows: Account[] }>();

    for (const account of rows) {
      const section = seen.get(account.type) ?? {
        type: account.type,
        label: account.type_label,
        rows: [] as Account[],
      };

      section.rows.push(account);
      seen.set(account.type, section);
    }

    return order.filter((type) => seen.has(type)).map((type) => seen.get(type)!);
  });

  protected readonly activeCount = computed(
    () => (this.rows() ?? []).filter((account) => account.status === 'active').length,
  );

  protected readonly retiredCount = computed(
    () => (this.rows() ?? []).filter((account) => account.status !== 'active').length,
  );

  protected readonly columns: Column<Account>[] = [
    { label: 'Number', kind: 'strong', value: (a) => a.code },
    { label: 'Name', value: (a) => a.name, sub: (a) => a.description },
    { label: 'Group', kind: 'muted', value: (a) => a.group },
    {
      label: 'Increases on',
      kind: 'muted',
      value: (a) => (a.normal_balance === 'debit' ? 'Debit' : 'Credit'),
    },
    { label: 'Status', kind: 'status', status: (a) => a.status },
  ];

  protected isCollapsed(type: string): boolean {
    return this.collapsed()[type] === true;
  }

  protected toggle(type: string): void {
    this.collapsed.update((state) => ({ ...state, [type]: !state[type] }));
  }
}

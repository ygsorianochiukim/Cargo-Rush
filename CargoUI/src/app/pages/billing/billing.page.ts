import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { map } from 'rxjs';

import { BillingService } from '../../services/billing/billing.service';
import { Invoice } from '../../models/billing/billing.model';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { FilterBar, FilterOption } from '../../shared/filter-bar';
import { fmt } from '../../shared/format';
import { ListToolbar } from '../../shared/list-toolbar';
import { invoiceSpec } from '../../services/billing/billing.form';
import { recordList } from '../../shared/record-list';
import { ErrorState } from '../../shared/states';

type Direction = 'all' | 'receivable' | 'payable';

/** Billing & Invoice — DESIGN.md section 5.1. */
@Component({
  selector: 'app-billing',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, FilterBar, ListToolbar, ErrorState],
  templateUrl: './billing.page.html',
})
export class BillingPage {
  private readonly billingApi = inject(BillingService);
  private readonly spec = invoiceSpec();

  protected readonly list = recordList<Invoice>(this.spec, () =>
    this.billingApi.list().pipe(map((res) => res.data)),
  );

  private readonly all = this.list.rows;

  protected readonly label = (i: Invoice) => i.number;

  protected readonly direction = signal<Direction>('all');

  protected readonly filters = computed<FilterOption[]>(() => {
    const rows = this.all() ?? [];
    return [
      { value: 'all', label: 'All', count: rows.length },
      {
        value: 'receivable',
        label: 'Receivables',
        count: rows.filter((r) => r.direction === 'receivable').length,
      },
      {
        value: 'payable',
        label: 'Payables',
        count: rows.filter((r) => r.direction === 'payable').length,
      },
    ];
  });

  protected readonly rows = computed(() => {
    const rows = this.all();
    if (rows === null) return null;
    const d = this.direction();
    return d === 'all' ? rows : rows.filter((r) => r.direction === d);
  });

  private sum(pred: (i: Invoice) => boolean): number {
    return (this.all() ?? []).filter(pred).reduce((t, i) => t + i.amount_cents, 0);
  }

  // Outstanding means not yet settled. This used to read `!== 'delivered'`,
  // because settling an invoice wrote the same word a closed-out haul does —
  // so the two could not be told apart and "collected" was unanswerable.
  protected readonly receivable = computed(() =>
    this.sum((i) => i.direction === 'receivable' && i.status !== 'paid'),
  );

  protected readonly payable = computed(() =>
    this.sum((i) => i.direction === 'payable' && i.status !== 'paid'),
  );

  /** Money in, as against money merely billed. */
  protected readonly collected = computed(() =>
    this.sum((i) => i.direction === 'receivable' && i.status === 'paid'),
  );

  protected readonly overdue = computed(() => this.sum((i) => i.status === 'overdue'));

  protected readonly fmt = fmt;

  protected readonly columns: Column<Invoice>[] = [
    { label: 'Number', kind: 'strong', value: (i) => i.number },
    {
      label: 'Party',
      value: (i) => i.customer,
      sub: (i) => (i.direction === 'receivable' ? 'Receivable' : 'Payable'),
    },
    // Which haul the document is for, when a delivery raised it. Without it,
    // reconciling an invoice against a run is a human matching dates and
    // amounts by eye.
    { label: 'Trip', kind: 'muted', value: (i) => i.trip_reference },
    { label: 'Issued', kind: 'num', value: (i) => fmt.date(i.issued_at) },
    { label: 'Due', kind: 'num', value: (i) => fmt.date(i.due_at) },
    { label: 'Amount', kind: 'num', value: (i) => fmt.money(i.amount_cents, i.currency) },
    { label: 'Status', kind: 'status', status: (i) => i.status },
  ];
}

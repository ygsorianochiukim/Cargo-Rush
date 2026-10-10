import { ChangeDetectionStrategy, Component, computed, effect, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { map } from 'rxjs';

import { BillingService } from '../../services/billing/billing.service';
import { Invoice } from '../../models/billing/billing.model';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { FilterBar, FilterOption } from '../../shared/filter-bar';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { PaymentDialog } from '../../shared/payment-dialog';
import { ListToolbar } from '../../shared/list-toolbar';
import { invoiceSpec } from '../../services/billing/billing.form';
import { recordList } from '../../shared/record-list';
import { ErrorState } from '../../shared/states';

type Direction = 'all' | 'receivable' | 'payable';

@Component({
  selector: 'app-billing',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, FilterBar, Icon, ListToolbar, ErrorState],
  templateUrl: './billing.page.html',
})
export class BillingPage {
  private readonly billingApi = inject(BillingService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly payments = inject(PaymentDialog);
  private readonly spec = invoiceSpec();

  private settleId: string | null = this.route.snapshot.queryParamMap.get('settle');

  protected readonly list = recordList<Invoice>(this.spec, () =>
    this.billingApi.list().pipe(map((res) => res.data)),
  );

  private readonly all = this.list.rows;

  protected readonly label = (i: Invoice) => i.number;

  protected recordPayment(): void {
    this.payments.forAny((this.all() ?? []).filter((invoice) => invoice.balance_cents > 0));
  }

  constructor() {
    this.payments.recorded.pipe(takeUntilDestroyed()).subscribe(() => this.list.refresh());

    effect(() => {
      const rows = this.all();

      if (this.settleId === null || rows === null) return;

      const invoice = rows.find((row) => row.id === this.settleId);

      this.settleId = null;
      void this.router.navigate([], { relativeTo: this.route, queryParams: {}, replaceUrl: true });

      if (invoice && invoice.balance_cents > 0) this.payments.forInvoice(invoice);
    });
  }

  protected openDocument(invoice: Invoice): void {
    this.router.navigate(['/billing', invoice.id]);
  }

  protected exportList(): void {
    const direction = this.direction();

    window.location.assign(this.billingApi.exportUrl(direction === 'all' ? {} : { direction }));
  }

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

  private sumBalance(pred: (i: Invoice) => boolean): number {
    return (this.all() ?? []).filter(pred).reduce((t, i) => t + i.balance_cents, 0);
  }

  private sumPaid(pred: (i: Invoice) => boolean): number {
    return (this.all() ?? []).filter(pred).reduce((t, i) => t + i.paid_cents, 0);
  }

  private readonly unsettled = (i: Invoice): boolean => i.status !== 'paid';

  protected readonly receivable = computed(() =>
    this.sumBalance((i) => i.direction === 'receivable' && this.unsettled(i)),
  );

  protected readonly payable = computed(() =>
    this.sumBalance((i) => i.direction === 'payable' && this.unsettled(i)),
  );

  protected readonly collected = computed(() => this.sumPaid((i) => i.direction === 'receivable'));

  protected readonly overdue = computed(() => this.sumBalance((i) => i.status === 'overdue'));

  protected readonly fmt = fmt;

  protected readonly columns: Column<Invoice>[] = [
    { label: 'Number', kind: 'strong', value: (i) => i.number },
    {
      label: 'Party',
      value: (i) => i.customer,
      sub: (i) => (i.direction === 'receivable' ? 'Receivable' : 'Payable'),
    },
    { label: 'Trip', kind: 'muted', value: (i) => i.trip_reference },
    { label: 'Issued', kind: 'num', value: (i) => fmt.date(i.issued_at) },
    { label: 'Due', kind: 'num', value: (i) => fmt.date(i.due_at) },
    {
      label: 'Invoiced',
      kind: 'num',
      value: (i) => fmt.money(i.amount_cents, i.currency),
      sub: (i) => (i.vat_cents > 0 ? `incl. VAT ${fmt.money(i.vat_cents, i.currency)}` : null),
    },
    {
      label: 'Balance',
      kind: 'num',
      value: (i) => fmt.money(i.balance_cents, i.currency),
      sub: (i) => {
        const parts: string[] = [];
        if (i.paid_cents > 0) parts.push(`paid ${fmt.money(i.paid_cents, i.currency)}`);
        if (i.withholding_cents > 0)
          parts.push(`withheld ${fmt.money(i.withholding_cents, i.currency)}`);

        return parts.length ? parts.join(' · ') : null;
      },
    },
    { label: 'Status', kind: 'status', status: (i) => i.status },
  ];
}

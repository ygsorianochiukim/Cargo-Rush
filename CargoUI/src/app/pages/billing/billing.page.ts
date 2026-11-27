import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  linkedSignal,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';

import { BillingService } from '../../services/billing/billing.service';
import { IdentityService } from '../../services/identity/identity.service';
import { BillingCutoff, Invoice } from '../../models/billing/billing.model';
import { cutoffDate, cutoffRange } from '../../shared/billing-cutoff';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { FilterBar, FilterOption } from '../../shared/filter-bar';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { PaymentDialog } from '../../shared/payment-dialog';
import { invoiceSpec } from '../../services/billing/billing.form';
import { recordList } from '../../shared/record-list';
import { ErrorState } from '../../shared/states';
import { statusLabel } from '../../shared/status';

type Direction = 'all' | 'receivable' | 'payable';

import { allPages } from '../../services/shared/all-pages';

@Component({
  selector: 'app-billing',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, FilterBar, FormsModule, Icon, ErrorState],
  templateUrl: './billing.page.html',
})
export class BillingPage {
  private readonly billingApi = inject(BillingService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly payments = inject(PaymentDialog);
  private readonly identity = inject(IdentityService);
  private readonly spec = invoiceSpec();

  private settleId: string | null = this.route.snapshot.queryParamMap.get('settle');

  protected readonly list = recordList<Invoice>(this.spec, () =>
    allPages((page) => this.billingApi.list(page)),
  );

  private readonly all = this.list.rows;

  protected readonly label = (i: Invoice) => i.number;

  protected recordPayment(): void {
    this.payments.forAny((this.all() ?? []).filter((invoice) => invoice.balance_cents > 0));
  }

  constructor() {
    this.payments.recorded.pipe(takeUntilDestroyed()).subscribe(() => {
      this.selectedIds.set(new Set());
      this.list.refresh();
    });

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

    window.location.assign(
      this.billingApi.exportUrl({
        direction: direction === 'all' ? undefined : direction,
        cutoff: this.selectedCutoff() === 'all' ? undefined : this.selectedCutoff(),
      }),
    );
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

  private readonly directionRows = computed(() => {
    const rows = this.all();
    if (rows === null) return null;
    const d = this.direction();
    return d === 'all' ? rows : rows.filter((r) => r.direction === d);
  });

  protected readonly cutoffs = computed(() => {
    const periods = new Map<string, BillingCutoff>();
    let hasOtherInvoices = false;
    for (const invoice of this.directionRows() ?? []) {
      const period = invoice.billing_cutoff;
      if (period) periods.set(period.start, period);
      else hasOtherInvoices = true;
    }

    const options = [...periods.values()]
      .sort((a, b) => b.start.localeCompare(a.start))
      .map((period) => ({
        value: period.start,
        label: cutoffRange(period.start, period.end),
        hint: `${Number(period.start.slice(-2))}th–${Number(period.end.slice(-2))}th trip cutoff`,
        schedule: {
          billing: cutoffRange(period.billing_from, period.billing_to),
          payment: cutoffDate(period.payment_target),
        },
      }));
    if (hasOtherInvoices) {
      return [...options, { value: 'none', label: 'No trip cutoff', hint: '', schedule: null }];
    }
    return options;
  });

  protected readonly selectedCutoff = linkedSignal({
    source: this.cutoffs,
    computation: (options, previous): string => {
      const selected = previous?.value;
      if (selected && (selected === 'all' || options.some((option) => option.value === selected))) {
        return selected;
      }
      return options[0]?.value ?? '';
    },
  });

  protected readonly cutoffNavigation = computed(() => {
    const options = this.cutoffs();
    const selected = this.selectedCutoff();
    const current = options.find((period) => period.value === selected);
    const periods = options.filter((period) => period.schedule !== null);
    const index = periods.findIndex((period) => period.value === selected);

    return {
      label: current?.label ?? 'All cutoffs',
      hint: current?.hint || 'Trip cutoff',
      schedule: current?.schedule ?? null,
      previous: index >= 0 ? periods[index + 1]?.value : undefined,
      next: index > 0 ? periods[index - 1]?.value : undefined,
    };
  });

  protected browseCutoff(period: string | undefined): void {
    if (period === undefined) return;
    this.selectedCutoff.set(period);
    this.actionError.set(null);
  }

  protected readonly rows = computed(() => {
    const rows = this.directionRows();
    if (rows === null) return null;

    const selected = this.selectedCutoff();
    if (selected && selected !== 'all') {
      return rows.filter((invoice) => (invoice.billing_cutoff?.start ?? 'none') === selected);
    }

    return [...rows].sort((a, b) =>
      (b.billing_cutoff?.start ?? '').localeCompare(a.billing_cutoff?.start ?? ''),
    );
  });

  protected readonly canManage = computed(() => this.identity.can('billing.manage'));
  protected readonly updating = signal(false);
  protected readonly actionError = signal<string | null>(null);
  private readonly selectedIds = linkedSignal(() => {
    this.direction();
    this.selectedCutoff();
    return new Set<string>();
  });

  private readonly selectedRows = computed(() =>
    (this.rows() ?? []).filter((invoice) => this.selectedIds().has(invoice.id)),
  );
  protected readonly pendingSelection = computed(() =>
    this.selectedRows().filter((invoice) => invoice.billing_status === 'pending'),
  );
  protected readonly billedSelection = computed(() =>
    this.selectedRows().filter((invoice) => invoice.billing_status === 'billed'),
  );
  protected readonly rowSelection = (invoice: Invoice): boolean | null =>
    invoice.billing_status === 'pending' || invoice.billing_status === 'billed'
      ? this.selectedIds().has(invoice.id)
      : null;

  protected selectRows(invoices: Invoice[], checked: boolean): void {
    const ids = new Set(this.selectedIds());
    for (const invoice of invoices) {
      if (checked) ids.add(invoice.id);
      else ids.delete(invoice.id);
    }
    this.selectedIds.set(ids);
    this.actionError.set(null);
  }

  protected markBilled(): void {
    const ids = this.pendingSelection().map((invoice) => invoice.id);
    if (ids.length === 0 || this.updating()) return;

    this.updating.set(true);
    this.actionError.set(null);
    this.billingApi.updateTripStatus(ids, 'billed').subscribe({
      next: () => {
        const changed = new Set(ids);
        this.selectedIds.update(
          (selected) => new Set([...selected].filter((id) => !changed.has(id))),
        );
        this.list.refresh();
        this.updating.set(false);
      },
      error: (failure: { error?: { message?: string } }) => {
        this.actionError.set(failure.error?.message ?? 'Could not mark those trips as billed.');
        this.updating.set(false);
      },
    });
  }

  protected markPaid(): void {
    const ids = this.billedSelection().map((invoice) => invoice.id);
    if (ids.length > 0 && !this.updating()) this.payments.forSettlement(ids);
  }

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

  private readonly cutoffColumns: Column<Invoice>[] = [
    {
      label: 'Trip cutoff',
      value: (i) =>
        i.billing_cutoff ? cutoffRange(i.billing_cutoff.start, i.billing_cutoff.end) : null,
    },
    {
      label: 'Billing window',
      value: (i) =>
        i.billing_cutoff
          ? cutoffRange(i.billing_cutoff.billing_from, i.billing_cutoff.billing_to)
          : null,
    },
    {
      label: 'Recommended payment date',
      value: (i) => (i.billing_cutoff ? cutoffDate(i.billing_cutoff.payment_target) : null),
    },
  ];

  protected readonly columns = computed<Column<Invoice>[]>(() => [
    { label: 'Number', kind: 'strong', value: (i) => i.number },
    {
      label: 'Party',
      value: (i) => i.customer,
      sub: (i) => (i.direction === 'receivable' ? 'Receivable' : 'Payable'),
    },
    { label: 'Trip', kind: 'muted', value: (i) => i.trip_reference },
    ...(this.selectedCutoff() === 'all' ? this.cutoffColumns : []),
    { label: 'Issued', kind: 'num', value: (i) => fmt.date(i.issued_at), date: (i) => i.issued_at },
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
    {
      label: 'Status',
      kind: 'status',
      status: (i) => i.billing_status ?? i.status,
      sub: (i) =>
        i.billing_status && (i.status === 'partial' || i.status === 'overdue')
          ? statusLabel(i.status)
          : null,
    },
  ]);
}

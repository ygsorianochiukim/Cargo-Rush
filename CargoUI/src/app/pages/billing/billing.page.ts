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

/** Billing & Invoice — DESIGN.md section 5.1. */
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

  /**
   * One bill named by a link, waiting for the list to arrive.
   *
   * Payables links here at a supplier bill rather than at the module, and the
   * payment dialog wants the invoice itself, not its id — so the deep link
   * has to wait for the rows. A plain field rather than a signal because
   * nothing renders it: it is a latch that fires once and is spent.
   */
  private settleId: string | null = this.route.snapshot.queryParamMap.get('settle');

  protected readonly list = recordList<Invoice>(this.spec, () =>
    this.billingApi.list().pipe(map((res) => res.data)),
  );

  private readonly all = this.list.rows;

  protected readonly label = (i: Invoice) => i.number;

  /**
   * Money has arrived — record it against a document.
   *
   * This is how an invoice becomes paid. There is no status to set: `paid` and
   * `partial` follow from the payments against a document, and the dropdown
   * that used to offer them wrote the word with no money behind it. The dialog
   * offers the documents that are still owed something, newest first.
   */
  protected recordPayment(): void {
    this.payments.forAny((this.all() ?? []).filter((invoice) => invoice.balance_cents > 0));
  }

  constructor() {
    // The figures on this page are derived from the payments, so a recorded
    // one changes every card as well as the row.
    this.payments.recorded.pipe(takeUntilDestroyed()).subscribe(() => this.list.refresh());

    /**
     * Followed a Payables line to a supplier bill — open its payment.
     *
     * An effect rather than a subscription on the load, because the rows come
     * from `recordList`, which owns its own fetch and hands back a signal.
     * This runs on the first non-null list and then spends the latch, so a
     * later refresh — including the one the dialog itself triggers on success
     * — does not reopen the dialog over the payment just recorded.
     *
     * The parameter goes with it. Left on the URL it would outlive the visit:
     * a refresh an hour later would reopen a payment form for a bill already
     * settled.
     */
    effect(() => {
      const rows = this.all();

      if (this.settleId === null || rows === null) return;

      const invoice = rows.find((row) => row.id === this.settleId);

      this.settleId = null;
      void this.router.navigate([], { relativeTo: this.route, queryParams: {}, replaceUrl: true });

      // Nothing to pay on a bill that is already square, and the dialog would
      // open on a zero. Silence is right here: the row is on screen either
      // way, showing its own status.
      if (invoice && invoice.balance_cents > 0) this.payments.forInvoice(invoice);
    });
  }

  /**
   * Open the printable document for one invoice.
   *
   * A page rather than a dialog — see `InvoicePage` for why — so this is a
   * navigation and not a modal open.
   */
  protected openDocument(invoice: Invoice): void {
    this.router.navigate(['/billing', invoice.id]);
  }

  /**
   * Download the list as a spreadsheet.
   *
   * `window.location` rather than an HTTP call: the API serves the file as an
   * attachment, so the browser has to be the thing that fetches it — an
   * `HttpClient` request would land the CSV in memory with nothing to do with
   * it. The direction filter goes along, because the export somebody wants is
   * the list they are looking at.
   */
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

  /**
   * Totals are summed on the **balance**, not the face value.
   *
   * Two things now sit between what a document says and what is still owed:
   * anything already paid against it, and the withholding tax the customer
   * keeps back and remits on our behalf. Adding up `amount_cents` counted both
   * as receivable — so a half-paid invoice looked entirely unpaid, and a
   * withholding customer looked permanently short.
   */
  private sumBalance(pred: (i: Invoice) => boolean): number {
    return (this.all() ?? []).filter(pred).reduce((t, i) => t + i.balance_cents, 0);
  }

  private sumPaid(pred: (i: Invoice) => boolean): number {
    return (this.all() ?? []).filter(pred).reduce((t, i) => t + i.paid_cents, 0);
  }

  /**
   * Outstanding means anything still owed on it.
   *
   * `partial` belongs here beside pending and overdue: a document part-paid is
   * still owed the rest, and leaving it out would drop the balance out of
   * receivables the moment the first instalment landed.
   */
  private readonly unsettled = (i: Invoice): boolean => i.status !== 'paid';

  protected readonly receivable = computed(() =>
    this.sumBalance((i) => i.direction === 'receivable' && this.unsettled(i)),
  );

  protected readonly payable = computed(() =>
    this.sumBalance((i) => i.direction === 'payable' && this.unsettled(i)),
  );

  /**
   * Money in, as against money merely billed.
   *
   * Summed from what each document has actually received rather than from the
   * face value of the ones marked paid — which used to count the withheld
   * portion as collected, though it never arrives.
   */
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
    // Which haul the document is for, when a delivery raised it. Without it,
    // reconciling an invoice against a run is a human matching dates and
    // amounts by eye.
    { label: 'Trip', kind: 'muted', value: (i) => i.trip_reference },
    { label: 'Issued', kind: 'num', value: (i) => fmt.date(i.issued_at) },
    { label: 'Due', kind: 'num', value: (i) => fmt.date(i.due_at) },
    {
      /**
       * What the document says, with the VAT inside it named underneath.
       *
       * The sub-line is only drawn where there is tax to explain — most
       * payables and any zero-rated customer have none, and "VAT ₱0.00" is
       * noise on every row of those.
       */
      label: 'Invoiced',
      kind: 'num',
      value: (i) => fmt.money(i.amount_cents, i.currency),
      sub: (i) => (i.vat_cents > 0 ? `incl. VAT ${fmt.money(i.vat_cents, i.currency)}` : null),
    },
    {
      /**
       * What is still owed — the column somebody chasing money actually reads.
       *
       * The sub-line names the two reasons it differs from the invoiced
       * figure, and they are different in kind: money that has arrived, and
       * money that never will because the customer remits it to the BIR.
       * Calling the second one "withheld" is what stops it reading as a debt.
       */
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

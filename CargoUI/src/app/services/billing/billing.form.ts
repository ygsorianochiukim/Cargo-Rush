import { inject } from '@angular/core';

import { Invoice } from '../../models/billing/billing.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { CustomerService } from '../customer/customer.service';
import { BillingService } from './billing.service';

/**
 * Billing & Invoice.
 *
 * A receivable names the customer being billed; a payable names who is being
 * paid. Both fields are offered and the API enforces which one is required
 * for the direction chosen — an unaddressed document is not a document.
 */
export function invoiceSpec(): RecordSpec<Invoice> {
  const billing = inject(BillingService);
  const customers = inject(CustomerService);

  const accounts: { value: string; label: string }[] = [];

  customers.list().subscribe((res) => {
    accounts.length = 0;
    accounts.push(...res.data.map((c) => ({ value: c.id, label: c.name })));
  });

  return {
    noun: 'invoice',
    icon: 'billing',

    fields: [
      // No Number field: the API assigns it — `INV-{year}-####` for a
      // receivable, `BILL-{year}-####` for a payable — and strips anything
      // sent. A box whose value is silently discarded is worse than no box,
      // and two people filling this form at once would otherwise race for
      // the same number and one would be rejected by the unique index.
      {
        key: 'direction',
        label: 'Direction',
        kind: 'select',
        required: true,
        options: () => [
          { value: 'receivable', label: 'Receivable — money in' },
          { value: 'payable', label: 'Payable — money out' },
        ],
      },
      {
        key: 'customer_id',
        label: 'Customer',
        kind: 'select',
        options: () => accounts,
        hint: 'Required for a receivable.',
      },
      {
        key: 'payee',
        label: 'Payee',
        kind: 'text',
        placeholder: 'Petron Fleet Card',
        hint: 'Required for a payable.',
      },
      { key: 'amount', label: 'Amount (₱)', kind: 'money', required: true },
      { key: 'issued_at', label: 'Issued', kind: 'date', required: true },
      { key: 'due_at', label: 'Due', kind: 'date', required: true, hint: 'Cannot precede the issue date.' },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['pending', 'overdue', 'paid', 'cancelled']),
        hint: 'Paid means the money has arrived.',
      },
    ],

    title: (invoice) => `${invoice.number} · ${invoice.customer}`,

    toForm: (invoice) => ({
      direction: invoice.direction,
      customer_id: invoice.customer_id ?? '',
      payee: invoice.payee ?? '',
      amount: invoice.amount_cents / 100,
      issued_at: invoice.issued_at,
      due_at: invoice.due_at,
      status: invoice.status,
    }),

    toPayload: (values) => ({
      direction: values['direction'],
      customer_id: values['customer_id'] || null,
      payee: values['payee'] || null,
      amount_cents: Math.round(Number(values['amount'] ?? 0) * 100),
      currency: 'PHP',
      issued_at: values['issued_at'],
      due_at: values['due_at'],
      status: values['status'] || 'pending',
    }),

    save: (payload, id) =>
      id ? billing.update(id, payload as never) : billing.create(payload as never),

    remove: (id) => billing.remove(id),
  };
}

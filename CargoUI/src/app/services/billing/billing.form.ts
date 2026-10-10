import { inject } from '@angular/core';

import { Invoice } from '../../models/billing/billing.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { CustomerService } from '../customer/customer.service';
import { BillingService } from './billing.service';

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
      { key: 'amount', label: 'Amount before VAT (₱)', kind: 'money', required: true },
      { key: 'issued_at', label: 'Issued', kind: 'date', required: true },
      {
        key: 'due_at',
        label: 'Due',
        kind: 'date',
        required: true,
        hint: 'Cannot precede the issue date.',
      },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['pending', 'overdue', 'cancelled']),
        hint: 'Paid follows from a payment — settle it or record one.',
      },
    ],

    title: (invoice) => `${invoice.number} · ${invoice.customer}`,

    toForm: (invoice) => ({
      direction: invoice.direction,
      customer_id: invoice.customer_id ?? '',
      payee: invoice.payee ?? '',
      amount: invoice.taxable_base_cents / 100,
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

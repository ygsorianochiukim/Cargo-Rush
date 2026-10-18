import { inject } from '@angular/core';

import { Expense, PAY_SCHEDULES } from '../../models/expense/expense.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { PaymentSourceService } from '../accounting/payment-source.service';
import { SupplierService } from '../supplier/supplier.service';
import { TripService } from '../trip/trip.service';
import { ExpenseService } from './expense.service';

export function expenseSpec(): RecordSpec<Expense> {
  const expenses = inject(ExpenseService);
  const suppliers = inject(SupplierService);
  const sources = inject(PaymentSourceService);
  const trips = inject(TripService);

  const categories: { value: string; label: string }[] = [];
  const shops: { value: string; label: string }[] = [];
  const wallets: { value: string; label: string }[] = [];
  const runs: { value: string; label: string }[] = [];

  expenses.categories(true).subscribe((rows) => {
    categories.length = 0;
    categories.push(...rows.map((c) => ({ value: c.id, label: c.name })));
  });

  suppliers.options().subscribe((rows) => {
    shops.length = 0;
    shops.push(...rows);
  });

  trips.list({ per_page: 100 }).subscribe((res) => {
    runs.length = 0;
    runs.push(
      ...res.data.map((t) => ({ value: t.id, label: `${t.reference} · ${t.origin} → ${t.destination}` })),
    );
  });

  sources.options().subscribe((rows) => {
    wallets.length = 0;
    wallets.push(...rows);
  });

  return {
    noun: 'expense',
    icon: 'wallet',

    fields: [
      {
        key: 'category_id',
        label: 'Category',
        kind: 'select',
        required: true,
        options: () => categories,
      },
      { key: 'amount', label: 'Amount (₱)', kind: 'money', required: true },
      { key: 'date', label: 'Date', kind: 'date', required: true },
      {
        key: 'payment_source_id',
        label: 'Paid with',
        kind: 'select',
        options: () => wallets,
      },
      {
        key: 'pay_schedule',
        label: 'Paid',
        kind: 'select',
        required: true,
        blank: 'on_receipt',
        options: () => PAY_SCHEDULES,
      },
      {
        key: 'is_vatable',
        label: 'VAT',
        kind: 'checkbox',
        placeholder: 'VAT receipt (VAT-able)',
      },
      {
        key: 'supplier_id',
        label: 'Bought from',
        kind: 'select',
        options: () => shops,
      },
      {
        key: 'payee',
        label: 'Or paid to',
        kind: 'text',
        placeholder: 'Aling Nena, Tagum',
      },
      { key: 'reference', label: 'Reference', kind: 'text', placeholder: 'OR-88214' },
      { key: 'trip_id', label: 'Trip', kind: 'select', options: () => runs },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['active', 'pending', 'cancelled']),
      },
      { key: 'note', label: 'Note', kind: 'textarea', wide: true },
    ],

    title: (record) => `${record.category_name ?? 'Expense'} · ${record.payee ?? record.date}`,

    toForm: (record) => {
      if (record.trip_id && !runs.some((r) => r.value === record.trip_id)) {
        runs.push({ value: record.trip_id, label: record.trip_reference ?? record.trip_id });
      }

      return {
        category_id: record.category_id,
        amount: record.amount_cents / 100,
        date: record.date,
        payment_source_id: record.payment_source_id ?? '',
        pay_schedule: record.pay_schedule,
        is_vatable: record.is_vatable,
        supplier_id: record.supplier_id ?? '',
        payee: record.payee ?? '',
        reference: record.reference ?? '',
        trip_id: record.trip_id ?? '',
        status: record.status,
        note: record.note ?? '',
      };
    },

    toPayload: (values) => ({
      category_id: values['category_id'],
      amount_cents: Math.round(Number(values['amount'] ?? 0) * 100),
      currency: 'PHP',
      date: values['date'],
      payment_source_id: values['payment_source_id'] || null,
      pay_schedule: values['pay_schedule'] || 'on_receipt',
      is_vatable: values['is_vatable'] === true,
      supplier_id: values['supplier_id'] || null,
      payee: values['payee'] || null,
      reference: values['reference'] || null,
      trip_id: values['trip_id'] || null,
      status: values['status'] || 'active',
      note: values['note'] || null,
    }),

    save: (payload, id) =>
      id ? expenses.update(id, payload as never) : expenses.create(payload as never),

    remove: (id) => expenses.remove(id),
  };
}

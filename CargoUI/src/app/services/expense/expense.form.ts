import { inject } from '@angular/core';

import { Expense } from '../../models/expense/expense.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { SupplierService } from '../supplier/supplier.service';
import { ExpenseService } from './expense.service';

export function expenseSpec(): RecordSpec<Expense> {
  const expenses = inject(ExpenseService);
  const suppliers = inject(SupplierService);

  const categories: { value: string; label: string }[] = [];
  const shops: { value: string; label: string }[] = [];

  expenses.categories(true).subscribe((rows) => {
    categories.length = 0;
    categories.push(...rows.map((c) => ({ value: c.id, label: c.name })));
  });

  suppliers.options().subscribe((rows) => {
    shops.length = 0;
    shops.push(...rows);
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
        key: 'supplier_id',
        label: 'Bought from',
        kind: 'select',
        options: () => shops,
        hint: 'Pick the shop so what you spend there adds up. Leave it empty for a one-off.',
      },
      {
        key: 'payee',
        label: 'Or paid to',
        kind: 'text',
        placeholder: 'Aling Nena, Tagum',
        hint: 'For somebody with no record — a roadside sale, a one-off.',
      },
      { key: 'reference', label: 'Reference', kind: 'text', placeholder: 'OR-88214' },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['active', 'pending', 'cancelled']),
        hint: 'Only active counts as spend.',
      },
      { key: 'note', label: 'Note', kind: 'textarea', wide: true },
    ],

    title: (record) => `${record.category_name ?? 'Expense'} · ${record.payee ?? record.date}`,

    toForm: (record) => ({
      category_id: record.category_id,
      amount: record.amount_cents / 100,
      date: record.date,
      supplier_id: record.supplier_id ?? '',
      payee: record.payee ?? '',
      reference: record.reference ?? '',
      status: record.status,
      note: record.note ?? '',
    }),

    toPayload: (values) => ({
      category_id: values['category_id'],
      amount_cents: Math.round(Number(values['amount'] ?? 0) * 100),
      currency: 'PHP',
      date: values['date'],
      supplier_id: values['supplier_id'] || null,
      payee: values['payee'] || null,
      reference: values['reference'] || null,
      status: values['status'] || 'active',
      note: values['note'] || null,
    }),

    save: (payload, id) =>
      id ? expenses.update(id, payload as never) : expenses.create(payload as never),

    remove: (id) => expenses.remove(id),
  };
}

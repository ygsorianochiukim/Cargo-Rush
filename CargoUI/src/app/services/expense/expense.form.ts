import { inject } from '@angular/core';

import { Expense } from '../../models/expense/expense.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { DriverService } from '../driver/driver.service';
import { FinanceService } from '../finance/finance.service';
import { ExpenseService } from './expense.service';

/**
 * Other Expenses — one categorised outgoing.
 *
 * The amount is typed in pesos and sent as centavos: nobody is going to enter
 * 45000 for ₱450.00, and the API rejects a float outright rather than rounding
 * it somewhere out of sight.
 *
 * Truck is deliberately optional, and the hint says what leaving it blank
 * means. It is not a field somebody forgot — office rent and an annual permit
 * belong to the period rather than to a unit, and charging them to whichever
 * truck happened to be picked would make that unit look unprofitable.
 */
export function expenseSpec(): RecordSpec<Expense> {
  const expenses = inject(ExpenseService);
  const finance = inject(FinanceService);
  const drivers = inject(DriverService);

  const categories: { value: string; label: string }[] = [];
  const trucks: { value: string; label: string }[] = [];
  const roster: { value: string; label: string }[] = [];

  // Active only: a retired category is one the office has stopped using, and
  // offering it would quietly put new spend back into it.
  expenses.categories(true).subscribe((rows) => {
    categories.length = 0;
    categories.push(...rows.map((c) => ({ value: c.id, label: c.name })));
  });

  finance.trucks().subscribe((rows) => {
    trucks.length = 0;
    trucks.push(
      ...rows.map((t) => ({ value: t.id, label: `${t.label} — ${t.plate ?? 'unassigned'}` })),
    );
  });

  drivers.list().subscribe((res) => {
    roster.length = 0;
    roster.push(...res.data.map((d) => ({ value: d.id, label: d.name })));
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
        key: 'truck_id',
        label: 'Truck',
        kind: 'select',
        options: () => trucks,
        hint: 'Blank means fleet overhead.',
      },
      { key: 'driver_id', label: 'Driver', kind: 'select', options: () => roster },
      { key: 'payee', label: 'Paid to', kind: 'text', placeholder: 'Shell Buhangin' },
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
      truck_id: record.truck_id ?? '',
      driver_id: record.driver_id ?? '',
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
      truck_id: values['truck_id'] || null,
      driver_id: values['driver_id'] || null,
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

import { inject } from '@angular/core';

import { Expense } from '../../models/expense/expense.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { SupplierService } from '../supplier/supplier.service';
import { ExpenseService } from './expense.service';

/**
 * Other Expenses — one categorised outgoing.
 *
 * The amount is typed in pesos and sent as centavos: nobody is going to enter
 * 45000 for ₱450.00, and the API rejects a float outright rather than rounding
 * it somewhere out of sight.
 *
 * ## It no longer asks about a truck
 *
 * It used to, and that made this screen the place an oil change was filed — so
 * a list otherwise full of meals and tarpaulins was also the fleet's service
 * history, and neither was findable in it. What a unit costs to run is a
 * **maintenance job** on the unit now, and what it came to lands in that
 * truck's Maintenance column on the daily sheet.
 *
 * So the truck and the "is this a truck's?" question are gone, and the API
 * stopped accepting either: a payload naming a truck is ignored rather than
 * refused. What is left here is the spend that belongs to the period rather
 * than to any one unit — meals, tarpaulins, tolls, the office rent.
 *
 * ## It no longer asks about a driver either
 *
 * That one sounded harmless — who was the money for — and did the same damage
 * from the other side: it made this the screen where somebody filed what a crew
 * cost. What a crew costs is payroll and the daily sheet's own driver and
 * helper columns, and a hand-filed third record against a person is how two
 * answers to "what did we pay Marco" get into one system.
 *
 * Old rows keep theirs — the list still prints the name under the payee, and
 * the API still returns it. This is a form that stopped asking, not a fact that
 * stopped existing.
 *
 * ## Bought from, picked rather than typed
 *
 * `payee` was free text, so the same canteen came out three ways over a year
 * and "what do we spend there" could not be asked. A supplier is a record now.
 * The free-text line stays underneath it for the one-off — a tyre bought in
 * Tagum on a Sunday from somebody nobody will see again does not deserve one.
 */
export function expenseSpec(): RecordSpec<Expense> {
  const expenses = inject(ExpenseService);
  const suppliers = inject(SupplierService);

  const categories: { value: string; label: string }[] = [];
  const shops: { value: string; label: string }[] = [];

  // Active only: a retired category is one the office has stopped using, and
  // offering it would quietly put new spend back into it.
  expenses.categories(true).subscribe((rows) => {
    categories.length = 0;
    categories.push(...rows.map((c) => ({ value: c.id, label: c.name })));
  });

  // Active only, for the reason the categories are: an inactive supplier is one
  // the office has stopped buying from, and offering them would quietly put new
  // spend back against a shop nobody uses.
  suppliers.list({ active: 1 } as never).subscribe((res) => {
    shops.length = 0;
    shops.push(...res.data.map((s) => ({ value: s.id, label: s.name })));
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

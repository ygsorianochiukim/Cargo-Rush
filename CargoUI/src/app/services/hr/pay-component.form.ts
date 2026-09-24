import { inject } from '@angular/core';

import { PayComponent } from '../../models/hr/payroll.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { PayComponentService } from './payroll.service';

/**
 * One row of the firm's salary structure.
 *
 * A flat record, so it uses the shared form. Three of its fields carry the only
 * teaching on this screen, and each of them is a question an office gets wrong
 * in a way nobody notices for months:
 *
 * **Basis.** A percentage follows a rise on its own. A fixed figure has to be
 * re-typed on every promotion, and the one that does not get re-typed is
 * somebody quietly underpaid for a year.
 *
 * **Schedule.** "₱2,000 rice allowance" almost always means a month of it;
 * "₱500 a payslip" means a payslip, which on a semi-monthly payroll is ₱1,000 a
 * month. Neither can be guessed from the number, so the form asks — and getting
 * it wrong is a doubling or a halving of somebody's allowance.
 *
 * **Taxable.** Off by default, and that is the honest default rather than a
 * cautious one: rice, uniform and medical allowances are de minimis benefits up
 * to the BIR's ceilings, which is most of what a fleet pays on top of a basic.
 *
 * Both amount fields are on the form at once and each hides when the other's
 * basis is chosen. `showWhen` drops a hidden field's validators, so the
 * `required` on either cannot block a save for a control nobody can see.
 */
export function payComponentSpec(): RecordSpec<PayComponent> {
  const components = inject(PayComponentService);

  const isPercentage = (values: Record<string, unknown>): boolean =>
    values['basis'] === 'percent_of_basic';

  return {
    noun: 'component',
    icon: 'tag',

    fields: [
      {
        key: 'name',
        label: 'Name',
        kind: 'text',
        required: true,
        placeholder: 'Rice allowance',
        hint: 'What it is called on the payslip.',
      },
      {
        key: 'kind',
        label: 'Kind',
        kind: 'select',
        required: true,
        options: () => [
          { value: 'earning', label: 'Earning — paid on top' },
          { value: 'deduction', label: 'Deduction — taken off' },
        ],
        hint: 'A deduction is a positive amount that comes off. There is no negative amount here.',
      },
      {
        key: 'basis',
        label: 'Worked out as',
        kind: 'select',
        required: true,
        options: () => [
          { value: 'fixed', label: 'A fixed amount' },
          { value: 'percent_of_basic', label: 'A percentage of the basic' },
        ],
        hint: 'A percentage follows a rise on its own; a fixed amount has to be re-typed.',
      },
      {
        key: 'amount_cents',
        label: 'Amount',
        kind: 'money',
        min: 0,
        showWhen: (values) => !isPercentage(values),
      },
      {
        key: 'rate_bp',
        label: 'Rate, in basis points',
        kind: 'number',
        min: 0,
        max: 10000,
        placeholder: '1000',
        hint: '1000 is 10% of the monthly basic. 10000 is all of it.',
        showWhen: isPercentage,
      },
      {
        key: 'schedule',
        label: 'Applies',
        kind: 'select',
        required: true,
        wide: true,
        options: () => [
          { value: 'each_run', label: 'On every payslip — the full amount, each time' },
          { value: 'monthly_split', label: 'Monthly, split across both cutoffs' },
          { value: 'first_cutoff', label: 'Monthly, all on the first cutoff' },
          { value: 'second_cutoff', label: 'Monthly, all on the second cutoff' },
        ],
        hint:
          'A monthly amount is divided over the month’s payslips. "On every payslip" is not — ' +
          'on a semi-monthly payroll it pays twice as much a month.',
      },
      {
        key: 'taxable',
        label: 'Goes into the tax base',
        kind: 'select',
        options: () => [
          { value: '0', label: 'No — de minimis or non-taxable' },
          { value: '1', label: 'Yes — taxable pay' },
        ],
        hint: 'Rice, uniform and medical allowances are non-taxable up to the BIR’s ceilings.',
        showWhen: (values) => values['kind'] !== 'deduction',
      },
      {
        key: 'position',
        label: 'Order on the payslip',
        kind: 'number',
        min: 0,
        max: 999,
      },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['active', 'inactive']),
        hint: 'A retired component stays on the payslips that carried it and lands on no new ones.',
      },
      { key: 'notes', label: 'Note', kind: 'textarea', wide: true },
    ],

    title: (record) => record.name,

    toForm: (record) => ({
      name: record.name,
      kind: record.kind,
      basis: record.basis,
      amount_cents: record.amount_cents,
      rate_bp: record.rate_bp,
      schedule: record.schedule,
      // The select carries strings; the payload builder turns it back.
      taxable: record.taxable ? '1' : '0',
      position: record.position,
      status: record.status,
      notes: record.notes,
    }),

    toPayload: (values) => {
      const basis = values['basis'] === 'percent_of_basic' ? 'percent_of_basic' : 'fixed';

      return {
        name: values['name'],
        kind: values['kind'],
        basis,
        /**
         * Only the figure the basis actually uses is sent, and the other is
         * sent as zero rather than left out.
         *
         * Switching a component from a percentage to a fixed amount has to
         * clear the rate, or the row keeps a stale 10% that nothing reads and
         * that reappears the moment somebody switches the basis back — a
         * component that changes its own amount when you look at it twice.
         */
        amount_cents: basis === 'fixed' ? Number(values['amount_cents'] ?? 0) : 0,
        rate_bp: basis === 'percent_of_basic' ? Number(values['rate_bp'] ?? 0) : 0,
        schedule: values['schedule'],
        taxable: values['taxable'] === '1' || values['taxable'] === true,
        position: values['position'] === '' ? 0 : Number(values['position'] ?? 0),
        status: values['status'],
        notes: values['notes'] === '' ? null : values['notes'],
      };
    },

    save: (values, id) =>
      id === undefined
        ? components.create(values as never)
        : components.update(id, values as never),

    /**
     * No `remove` on the spec.
     *
     * Deliberate: removing a component is not an ordinary destroy. One nobody
     * is assigned is deleted and one in use is *retired*, and the shared
     * confirmation cannot say which is about to happen. The page handles it
     * itself so the office is told which of the two it got.
     */
  };
}

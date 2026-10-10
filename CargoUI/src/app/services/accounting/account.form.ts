import { inject } from '@angular/core';

import { Account } from '../../models/accounting/accounting.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { AccountingService } from './accounting.service';

export function accountSpec(): RecordSpec<Account> {
  const accounting = inject(AccountingService);

  const types: { value: string; label: string }[] = [];

  accounting.accountTypes().subscribe((rows) => {
    types.length = 0;
    types.push(...rows.map((t) => ({ value: t.value, label: t.label })));
  });

  return {
    noun: 'account',
    icon: 'tag',

    fields: [
      {
        key: 'code',
        label: 'Number',
        kind: 'text',
        required: true,
        placeholder: '5065',
        hint: '1000s assets, 2000s liabilities, 3000s equity, 4000s income, 5000s expenses.',
      },
      { key: 'name', label: 'Name', kind: 'text', required: true, placeholder: 'Batteries' },
      {
        key: 'type',
        label: 'Kind',
        kind: 'select',
        required: true,
        options: () => types,
        hint: 'Decides which way its balance runs. Fixed once anything is posted to it.',
      },
      {
        key: 'group',
        label: 'Group',
        kind: 'text',
        placeholder: 'Cost of services',
        hint: 'A sub-heading on the statements. Yours to name.',
      },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['active', 'inactive']),
        hint: 'Retired accounts keep their history and take no new postings.',
      },
      { key: 'description', label: 'Note', kind: 'textarea', wide: true },
    ],

    title: (record) => record.label,

    toForm: (record) => ({
      code: record.code,
      name: record.name,
      type: record.type,
      group: record.group ?? '',
      status: record.status,
      description: record.description ?? '',
    }),

    toPayload: (values) => ({
      code: String(values['code'] ?? '').trim(),
      name: values['name'],
      type: values['type'],
      group: values['group'] || null,
      status: values['status'] || 'active',
      description: values['description'] || null,
    }),

    save: (payload, id) =>
      id
        ? accounting.updateAccount(id, payload as never)
        : accounting.createAccount(payload as never),

  };
}

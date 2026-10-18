import { inject } from '@angular/core';

import {
  PAYMENT_SOURCE_KINDS,
  PaymentSource,
} from '../../models/accounting/payment-source.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { PaymentSourceService } from './payment-source.service';

export function paymentSourceSpec(): RecordSpec<PaymentSource> {
  const sources = inject(PaymentSourceService);

  const carded = (values: Record<string, unknown>) =>
    values['kind'] === 'credit_card' || values['kind'] === 'fuel_card';

  return {
    noun: 'wallet or card',
    icon: 'wallet',

    fields: [
      {
        key: 'kind',
        label: 'Type',
        kind: 'select',
        required: true,
        options: () => PAYMENT_SOURCE_KINDS,
      },
      {
        key: 'name',
        label: 'Name',
        kind: 'text',
        required: true,
        placeholder: 'BPI Mastercard',
      },
      {
        key: 'provider',
        label: 'Bank or issuer',
        kind: 'text',
        placeholder: 'BPI, Shell, Petron, GCash',
        showWhen: (values) => values['kind'] !== 'cash',
      },
      {
        key: 'last_four',
        label: 'Last 4 digits',
        kind: 'text',
        placeholder: '4821',
        showWhen: carded,
      },
      { key: 'note', label: 'Note', kind: 'textarea', wide: true },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        wide: true,
        options: statusOptions(['active', 'inactive']),
      },
    ],

    title: (source) => source.label,

    toForm: (source) => ({
      kind: source.kind,
      name: source.name,
      provider: source.provider ?? '',
      last_four: source.last_four ?? '',
      note: source.note ?? '',
      status: source.status,
    }),

    toPayload: (values) => {
      const kind = values['kind'] as string;
      const carded = kind === 'credit_card' || kind === 'fuel_card';

      return {
        kind,
        name: values['name'] as string,
        provider: kind === 'cash' ? null : (values['provider'] as string) || null,
        last_four: carded ? (values['last_four'] as string) || null : null,
        note: (values['note'] as string) || null,
        status: (values['status'] as string) || 'active',
      };
    },

    save: (payload, id) =>
      id ? sources.update(id, payload as never) : sources.create(payload as never),

    remove: (id) => sources.remove(id),
  };
}

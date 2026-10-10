import { inject } from '@angular/core';

import { Customer } from '../../models/customer/customer.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { CustomerService } from './customer.service';

export function customerSpec(): RecordSpec<Customer> {
  const customers = inject(CustomerService);

  return {
    noun: 'customer',
    icon: 'customers',

    fields: [
      { key: 'name', label: 'Name', kind: 'text', required: true, wide: true, placeholder: 'Southline Trading' },
      {
        key: 'contact',
        label: 'Contact',
        kind: 'text',
        required: true,
        wide: true,
        placeholder: 'ops@southline.ph',
        hint: 'Email or phone — whichever the office actually uses.',
      },
      {
        key: 'address',
        label: 'Where their loads leave from',
        kind: 'text',
        wide: true,
        placeholder: 'Carmen, Cagayan de Oro',
        hint: 'Filled in by firms that signed up in the app and pinned their store. Their pin is theirs to move; this line is yours to correct.',
      },
      {
        key: 'email',
        label: 'Portal login',
        kind: 'text',
        wide: true,
        placeholder: 'desk@southline.ph',
        hint: 'Address the firm signs in with. Leave blank to use the contact above, or for a customer who does not need an account.',
      },
      { key: 'rating', label: 'Rating', kind: 'number', min: 0, max: 5, hint: '0 to 5.' },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['active', 'pending', 'inactive']),
      },

      {
        key: 'tin',
        label: 'TIN',
        kind: 'text',
        placeholder: '123-456-789-000',
        hint: 'Printed on the invoice. A VAT invoice without it gets sent back.',
      },
      {
        key: 'vat_treatment',
        label: 'VAT',
        kind: 'select',
        options: () => [
          { value: 'vatable', label: 'VAT (12%)' },
          { value: 'zero_rated', label: 'Zero-rated' },
          { value: 'exempt', label: 'VAT-exempt' },
        ],
        hint: 'Zero-rated is for exporters and PEZA locators.',
      },
      {
        key: 'withholds_tax',
        label: 'Withholds tax',
        kind: 'select',
        options: () => [
          { value: 'no', label: 'No' },
          { value: 'yes', label: 'Yes — keeps back EWT' },
        ],
        hint: 'Government agencies and large corporates usually do. They pay less than the invoice says, on purpose.',
      },
    ],

    title: (customer) => customer.name,

    toForm: (customer) => ({
      name: customer.name,
      contact: customer.contact,
      address: customer.address ?? '',
      email: customer.login_email ?? '',
      rating: customer.rating,
      status: customer.status,
      tin: customer.tin ?? '',
      vat_treatment: customer.vat_treatment,
      withholds_tax: customer.withholds_tax ? 'yes' : 'no',
    }),

    toPayload: (values) => ({
      name: values['name'],
      contact: values['contact'],
      address: String(values['address'] ?? '').trim() || null,
      email: String(values['email'] ?? '').trim() || undefined,
      rating: Number(values['rating'] ?? 0),
      status: values['status'] || 'active',
      tin: String(values['tin'] ?? '').trim() || null,
      vat_treatment: values['vat_treatment'] || 'vatable',
      withholds_tax: values['withholds_tax'] === 'yes',
    }),

    save: (payload, id) =>
      id ? customers.update(id, payload as never) : customers.create(payload as never),

    remove: (id) => customers.remove(id),
  };
}

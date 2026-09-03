import { inject } from '@angular/core';

import { Customer } from '../../models/customer/customer.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { CustomerService } from './customer.service';

/**
 * Customer Management.
 *
 * Trip count and outstanding balance are absent on purpose: both are derived
 * by the API from trips and unsettled invoices, so entering them would create
 * a number that can disagree with the Billing module.
 */
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
    ],

    title: (customer) => customer.name,

    toForm: (customer) => ({
      name: customer.name,
      contact: customer.contact,
      email: customer.login_email ?? '',
      rating: customer.rating,
      status: customer.status,
    }),

    toPayload: (values) => ({
      name: values['name'],
      contact: values['contact'],
      // Absent rather than empty when nothing was typed: the API reads an
      // address as "give this firm a login", and a blank one is not that.
      email: String(values['email'] ?? '').trim() || undefined,
      rating: Number(values['rating'] ?? 0),
      status: values['status'] || 'active',
    }),

    save: (payload, id) =>
      id ? customers.update(id, payload as never) : customers.create(payload as never),

    remove: (id) => customers.remove(id),
  };
}

import { inject } from '@angular/core';

import { Supplier } from '../../models/supplier/supplier.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { SupplierService } from './supplier.service';

/**
 * Suppliers.
 *
 * What has been spent at one is absent on purpose: the API adds it up from the
 * expenses, the maintenance jobs and the bills that name them, so a figure
 * typed here would be a number that can disagree with all three.
 */
export function supplierSpec(): RecordSpec<Supplier> {
  const suppliers = inject(SupplierService);

  return {
    noun: 'supplier',
    icon: 'customers',

    fields: [
      {
        key: 'name',
        label: 'Name',
        kind: 'text',
        required: true,
        wide: true,
        placeholder: 'Davao Lubes & Parts',
        hint: 'One per haulier — the name is how a second record of the same shop is caught.',
      },
      {
        key: 'contact',
        label: 'Contact',
        kind: 'text',
        wide: true,
        placeholder: '0917 555 0101',
        hint: 'Phone or email — whichever the office actually rings.',
      },
      {
        key: 'address',
        label: 'Address',
        kind: 'text',
        wide: true,
        placeholder: 'Bajada, Davao City',
      },
      {
        key: 'supplies',
        label: 'What they sell',
        kind: 'text',
        wide: true,
        placeholder: 'Engine oil, filters, brake parts',
        /**
         * A sentence rather than a category, and deliberately.
         *
         * A garage that also sells tyres and lends a flatbed is three
         * categories, and making somebody choose one would file the record
         * wrong in a way nobody could correct later. What is actually needed is
         * enough to recognise who to ring.
         */
        hint: 'In your own words. Enough to tell one shop from another at a glance.',
      },
      { key: 'note', label: 'Note', kind: 'textarea', wide: true },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['active', 'inactive']),
        hint: 'Inactive keeps their history and takes them out of the pickers.',
      },
    ],

    title: (supplier) => supplier.name,

    toForm: (supplier) => ({
      name: supplier.name,
      contact: supplier.contact ?? '',
      address: supplier.address ?? '',
      supplies: supplier.supplies ?? '',
      note: supplier.note ?? '',
      status: supplier.status,
    }),

    toPayload: (values) => ({
      name: values['name'] as string,
      // Empty means "not recorded", which is a null rather than an empty
      // string — the same boundary every other optional text field crosses.
      contact: (values['contact'] as string) || null,
      address: (values['address'] as string) || null,
      supplies: (values['supplies'] as string) || null,
      note: (values['note'] as string) || null,
      status: (values['status'] as string) || 'active',
    }),

    save: (payload, id) =>
      id ? suppliers.update(id, payload as never) : suppliers.create(payload as never),

    remove: (id) => suppliers.remove(id),
  };
}

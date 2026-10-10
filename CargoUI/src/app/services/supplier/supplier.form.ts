import { inject } from '@angular/core';

import { Supplier } from '../../models/supplier/supplier.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { SupplierService } from './supplier.service';

export function supplierSpec(): RecordSpec<Supplier> {
  const suppliers = inject(SupplierService);

  const categories: { value: string; label: string }[] = [];

  suppliers.categories(true).subscribe((rows) => {
    categories.length = 0;
    categories.push(...rows.map((c) => ({ value: c.id, label: c.name })));
  });

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
        key: 'category_id',
        label: 'Category',
        kind: 'select',
        wide: true,
        options: () => categories,
        hint: 'Garage, mall, food… Add or rename categories on Access Control.',
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
        hint: 'In your own words. Enough to tell one shop from another at a glance.',
      },
      { key: 'note', label: 'Note', kind: 'textarea', wide: true },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        wide: true,
        options: statusOptions(['active', 'inactive']),
        hint: 'Inactive keeps their history and takes them out of the pickers.',
      },
    ],

    title: (supplier) => supplier.name,

    toForm: (supplier) => ({
      name: supplier.name,
      category_id: supplier.category_id ?? '',
      contact: supplier.contact ?? '',
      address: supplier.address ?? '',
      supplies: supplier.supplies ?? '',
      note: supplier.note ?? '',
      status: supplier.status,
    }),

    toPayload: (values) => ({
      name: values['name'] as string,
      category_id: (values['category_id'] as string) || null,
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

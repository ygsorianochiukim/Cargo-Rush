import { inject } from '@angular/core';

import { Asset } from '../../models/asset/asset.model';
import { RecordSpec } from '../../shared/record-form-spec';
import { SupplierService } from '../supplier/supplier.service';
import { AssetService } from './asset.service';

export const ASSET_CONDITIONS = [
  { value: 'good', label: 'Good' },
  { value: 'fair', label: 'Fair' },
  { value: 'damaged', label: 'Damaged' },
];

export const ASSET_SHELF_STATUSES = [
  { value: 'available', label: 'Available' },
  { value: 'maintenance', label: 'Under repair' },
  { value: 'inactive', label: 'Retired / lost' },
];

export function assetSpec(): RecordSpec<Asset> {
  const assets = inject(AssetService);
  const suppliers = inject(SupplierService);

  const categories: { value: string; label: string }[] = [];
  const shops: { value: string; label: string }[] = [];
  const people: { value: string; label: string }[] = [];

  assets.categories(true).subscribe((rows) => {
    categories.length = 0;
    categories.push(...rows.map((c) => ({ value: c.id, label: c.name })));
  });

  suppliers.options().subscribe((rows) => {
    shops.length = 0;
    shops.push(...rows);
  });

  assets.custodians().subscribe((rows) => {
    people.length = 0;
    people.push(
      ...rows.map((p) => ({
        value: p.value,
        label: p.position ? `${p.label} · ${p.position}` : p.label,
      })),
    );
  });

  return {
    noun: 'asset',
    icon: 'clipboard',

    fields: [
      {
        key: 'name',
        label: 'Item',
        kind: 'text',
        required: true,
        wide: true,
        placeholder: 'Lenovo ThinkPad E14',
      },
      {
        key: 'tag',
        label: 'Asset tag',
        kind: 'text',
        placeholder: 'Assigned on save',
        hint: (_values, editing) =>
          editing
            ? 'Change only if the printed label says otherwise.'
            : 'Leave empty — numbered Asset-INV-mmddyy-0001 from the purchase date.',
      },
      {
        key: 'category_id',
        label: 'Category',
        kind: 'select',
        options: () => categories,
        hint: 'Add or rename categories on Access Control.',
      },
      { key: 'serial_no', label: 'Serial no.', kind: 'text', placeholder: 'PF3XK2LM' },
      { key: 'purchased_on', label: 'Date purchased', kind: 'date' },
      { key: 'cost', label: 'Cost (₱)', kind: 'money' },
      { key: 'supplier_id', label: 'Bought from', kind: 'select', options: () => shops },
      { key: 'location', label: 'Location', kind: 'text', placeholder: 'Main office, Bajada' },
      {
        key: 'condition',
        label: 'Condition',
        kind: 'select',
        blank: 'good',
        options: () => ASSET_CONDITIONS,
      },
      {
        key: 'custodian_id',
        label: 'Issue to',
        kind: 'select',
        options: () => people,
        hint: 'Optional. Leave empty to keep it in stock.',
        showWhen: (_values, editing) => !editing,
      },
      {
        key: 'assigned_on',
        label: 'Issued on',
        kind: 'date',
        hint: 'Defaults to the purchase date.',
        showWhen: (values, editing) => !editing && !!values['custodian_id'],
      },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        blank: 'available',
        options: () => ASSET_SHELF_STATUSES,
        hint: 'Who holds it changes through Turn over, not here.',
        showWhen: (values, editing) =>
          editing ? values['status'] !== 'assigned' : !values['custodian_id'],
      },
      { key: 'note', label: 'Note', kind: 'textarea', wide: true },
    ],

    title: (asset) => (asset.tag ? `${asset.tag} · ${asset.name}` : asset.name),

    toForm: (asset) => ({
      name: asset.name,
      tag: asset.tag ?? '',
      category_id: asset.category_id ?? '',
      serial_no: asset.serial_no ?? '',
      purchased_on: asset.purchased_on ?? '',
      cost: asset.cost_cents === null ? 0 : asset.cost_cents / 100,
      supplier_id: asset.supplier_id ?? '',
      location: asset.location ?? '',
      condition: asset.condition,
      status: asset.status,
      note: asset.note ?? '',
    }),

    toPayload: (values) => {
      const payload: Record<string, unknown> = {
        name: values['name'],
        tag: values['tag'] || null,
        category_id: values['category_id'] || null,
        serial_no: values['serial_no'] || null,
        purchased_on: values['purchased_on'] || null,
        cost_cents: values['cost'] ? Math.round(Number(values['cost']) * 100) : null,
        supplier_id: values['supplier_id'] || null,
        location: values['location'] || null,
        condition: values['condition'] || 'good',
        note: values['note'] || null,
      };

      if (values['status'] && values['status'] !== 'assigned') payload['status'] = values['status'];

      if (values['custodian_id'] !== undefined) {
        payload['custodian_id'] = values['custodian_id'] || null;
        payload['assigned_on'] = values['assigned_on'] || null;
      }

      return payload;
    },

    save: (payload, id) =>
      id
        ? assets.update(id, payload as Record<string, unknown>)
        : assets.create(payload as Record<string, unknown>),

    remove: (id) => assets.remove(id),
  };
}

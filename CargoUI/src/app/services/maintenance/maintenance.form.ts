import { inject } from '@angular/core';

import { MaintenanceJob } from '../../models/vehicle/vehicle.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { BillingService } from '../billing/billing.service';
import { SupplierService } from '../supplier/supplier.service';
import { VehicleService } from '../vehicle/vehicle.service';
import { MaintenanceService } from './maintenance.service';

export function maintenanceSpec(): RecordSpec<MaintenanceJob> {
  const jobs = inject(MaintenanceService);
  const vehicles = inject(VehicleService);
  const suppliers = inject(SupplierService);
  const billing = inject(BillingService);

  const fleet: { value: string; label: string }[] = [];
  const shops: { value: string; label: string }[] = [];
  const bills: { value: string; label: string }[] = [];

  vehicles.list().subscribe((res) => {
    fleet.length = 0;
    fleet.push(...res.data.map((v) => ({ value: v.id, label: `${v.plate} · ${v.model}` })));
  });

  suppliers.options().subscribe((rows) => {
    shops.length = 0;
    shops.push(...rows);
  });

  billing.list({ direction: 'payable', per_page: 100 } as never).subscribe((res) => {
    bills.length = 0;
    bills.push(...res.data.map((b) => ({ value: b.id, label: `${b.number} · ${b.payee ?? b.customer}` })));
  });

  return {
    noun: 'service',
    icon: 'fleet',

    fields: [
      {
        key: 'vehicle_id',
        label: 'Truck',
        kind: 'select',
        required: true,
        options: () => fleet,
        hint: 'The unit this was done on. Its Maintenance column is where the cost lands.',
      },
      {
        key: 'kind',
        label: 'Work done',
        kind: 'text',
        required: true,
        placeholder: 'Oil and filter change',
      },
      { key: 'due_at', label: 'Due', kind: 'date', required: true },
      {
        key: 'completed_on',
        label: 'Date done',
        kind: 'date',
        hint: 'The day the work happened — the day it is charged to. Empty while it is only booked.',
      },
      {
        key: 'cost',
        label: 'Cost (₱)',
        kind: 'money',
        blank: '',
        hint: 'Leave it empty until the garage bills. Zero means a job nobody was charged for.',
      },
      {
        key: 'supplier_id',
        label: 'Garage',
        kind: 'select',
        options: () => shops,
        hint: 'Who did the work, so what the fleet spends there adds up.',
      },
      {
        key: 'invoice_id',
        label: 'Garage bill',
        kind: 'select',
        options: () => bills,
        hint: 'The supplier bill for this job, if one was raised. Linked, its payment is not counted a second time.',
      },
      { key: 'reference', label: 'Reference', kind: 'text', placeholder: 'SO-24817' },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['scheduled', 'active', 'delivered', 'cancelled']),
        hint: 'Scheduled is booked, active is in the workshop, delivered is done.',
      },
      { key: 'note', label: 'Note', kind: 'textarea', wide: true },
    ],

    title: (job) => `${job.kind} · ${job.vehicle_plate ?? 'unit'}`,

    toForm: (job) => ({
      vehicle_id: job.vehicle_id,
      kind: job.kind,
      due_at: job.due_at,
      completed_on: job.completed_on ?? '',
      cost: job.cost_cents === null ? '' : job.cost_cents / 100,
      supplier_id: job.supplier_id ?? '',
      invoice_id: job.invoice_id ?? '',
      reference: job.reference ?? '',
      status: job.status,
      note: job.note ?? '',
    }),

    toPayload: (values) => ({
      vehicle_id: values['vehicle_id'],
      kind: values['kind'],
      due_at: values['due_at'],
      completed_on: values['completed_on'] || null,
      cost_cents: values['cost'] === '' || values['cost'] === null || values['cost'] === undefined
        ? null
        : Math.round(Number(values['cost']) * 100),
      supplier_id: values['supplier_id'] || null,
      invoice_id: values['invoice_id'] || null,
      reference: values['reference'] || null,
      status: values['status'] || 'scheduled',
      note: values['note'] || null,
    }),

    save: (payload, id) => (id ? jobs.update(id, payload as never) : jobs.create(payload as never)),

    remove: (id) => jobs.remove(id),
  };
}

import { inject } from '@angular/core';

import { MaintenanceJob } from '../../models/vehicle/vehicle.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { SupplierService } from '../supplier/supplier.service';
import { VehicleService } from '../vehicle/vehicle.service';
import { MaintenanceService } from './maintenance.service';

/**
 * Truck Maintenance — one service on one unit, and what it came to.
 *
 * This is the form Other Expenses used to be asked to be. An oil change filed
 * there put the fleet's service history inside a list of meals and tarpaulins,
 * and the money never reached the truck: Profitability showed a unit that had
 * apparently never been serviced, because an expense belongs to the period and
 * a service belongs to a **truck**.
 *
 * So the unit is the first field and it is required. A service with no truck on
 * it is a cost with nowhere to land.
 *
 * ## Booked and costed are the same form
 *
 * Most of a job's life is "booked for the 12th" with no figure on it at all,
 * and the cost arrives later with the garage's invoice. One form for both,
 * because the common correction is somebody mistyping the figure — and that
 * should not be a decision about which screen to open.
 *
 * Two fields decide whether the money moves, and the hints under them say so:
 *
 *   **Cost** — left empty means nobody has told us yet, which is not the same
 *   as ₱0 for a warranty job. Clearing it takes the figure back off the sheet.
 *
 *   **Date done** — the day the work happened, which is the day it is charged
 *   to. Not the day it was due: a service booked for the 12th and done on the
 *   19th is the 19th's money. A cost with no date done is recorded and charged
 *   to nothing, because there is no day to charge it to.
 */
export function maintenanceSpec(): RecordSpec<MaintenanceJob> {
  const jobs = inject(MaintenanceService);
  const vehicles = inject(VehicleService);
  const suppliers = inject(SupplierService);

  const fleet: { value: string; label: string }[] = [];
  const shops: { value: string; label: string }[] = [];

  vehicles.list().subscribe((res) => {
    fleet.length = 0;
    fleet.push(...res.data.map((v) => ({ value: v.id, label: `${v.plate} · ${v.model}` })));
  });

  // Active only, as every other picker does: an inactive supplier is one the
  // office has stopped using, and offering them puts new spend back on a shop
  // nobody buys from.
  suppliers.list({ active: 1 } as never).subscribe((res) => {
    shops.length = 0;
    shops.push(...res.data.map((s) => ({ value: s.id, label: s.name })));
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
        // Blank rather than 0, because the payload has to tell "nobody has told
        // us yet" from "a warranty job nobody was charged for", and a control
        // that starts at zero can say only one of those.
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
      // Empty rather than zero for an uncosted job, so opening and saving a
      // booked job does not quietly declare it free.
      cost: job.cost_cents === null ? '' : job.cost_cents / 100,
      supplier_id: job.supplier_id ?? '',
      reference: job.reference ?? '',
      status: job.status,
      note: job.note ?? '',
    }),

    toPayload: (values) => ({
      vehicle_id: values['vehicle_id'],
      kind: values['kind'],
      due_at: values['due_at'],
      completed_on: values['completed_on'] || null,
      /**
       * Null when nothing was typed, and `0` when a zero was.
       *
       * `|| null` would collapse the two, and they are the two different facts
       * this field exists to keep apart — a warranty job would come back as
       * "not costed yet" and stay on the workshop's list for ever.
       */
      cost_cents: values['cost'] === '' || values['cost'] === null || values['cost'] === undefined
        ? null
        : Math.round(Number(values['cost']) * 100),
      supplier_id: values['supplier_id'] || null,
      reference: values['reference'] || null,
      status: values['status'] || 'scheduled',
      note: values['note'] || null,
    }),

    save: (payload, id) => (id ? jobs.update(id, payload as never) : jobs.create(payload as never)),

    remove: (id) => jobs.remove(id),
  };
}

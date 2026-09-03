import { inject } from '@angular/core';

import { Truck } from '../../models/finance/finance.model';
import { RecordSpec } from '../../shared/record-form-spec';
import { VehicleService } from '../vehicle/vehicle.service';
import { FinanceService } from './finance.service';

/**
 * A ledger unit — the workbook keeps one sheet per truck.
 *
 * Separate from a vehicle on purpose: a unit can be on the books before it has
 * a plate, and the money history outlives the vehicle it was attached to
 * (DESIGN.md section 5.1). Linking one to a vehicle is optional.
 */
export function truckSpec(): RecordSpec<Truck> {
  const finance = inject(FinanceService);
  const vehicles = inject(VehicleService);

  const fleet: { value: string; label: string }[] = [];

  vehicles.list().subscribe((res) => {
    fleet.length = 0;
    fleet.push(...res.data.map((v) => ({ value: v.id, label: `${v.plate} — ${v.model}` })));
  });

  return {
    noun: 'unit',
    icon: 'fleet',

    fields: [
      {
        key: 'label',
        label: 'Unit name',
        kind: 'text',
        required: true,
        placeholder: 'Truck 1',
        hint: 'How the office refers to it — this titles its sheet.',
      },
      {
        key: 'plate',
        label: 'Plate number',
        kind: 'text',
        placeholder: 'MAR1390',
        hint: 'Leave empty if it has none yet. It still gets a sheet.',
      },
      {
        key: 'vehicle_id',
        label: 'Linked vehicle',
        kind: 'select',
        options: () => fleet,
        wide: true,
        hint: 'Optional. Connects the money to a vehicle record.',
      },
    ],

    title: (truck) => truck.plate ?? truck.label,

    toForm: (truck) => ({
      label: truck.label,
      plate: truck.plate ?? '',
      vehicle_id: truck.vehicle_id ?? '',
    }),

    toPayload: (values) => ({
      label: values['label'],
      // An empty box means "no plate yet", which is a null, not "".
      plate: values['plate'] || null,
      vehicle_id: values['vehicle_id'] || null,
    }),

    save: (payload, id) =>
      id
        ? finance.updateTruck(id, payload as never)
        : finance.createTruck(payload as never),

    remove: (id) => finance.removeTruck(id),
  };
}

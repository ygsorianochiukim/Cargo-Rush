import { inject } from '@angular/core';

import { Driver } from '../../models/driver/driver.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { DriverService } from './driver.service';

/**
 * Drivers Management — DESIGN.md section 5.1.
 *
 * Helpers are entered here too: a helper is a driver record without the keys,
 * so there is no second form for them.
 */
export function driverSpec(): RecordSpec<Driver> {
  const drivers = inject(DriverService);

  return {
    noun: 'driver',
    icon: 'profile',

    fields: [
      { key: 'name', label: 'Full name', kind: 'text', required: true, wide: true, placeholder: 'Juan Dela Cruz' },
      { key: 'licence_no', label: 'Licence number', kind: 'text', required: true, placeholder: 'N02-14-882301' },
      { key: 'licence_expiry', label: 'Licence expiry', kind: 'date', required: true },
      {
        key: 'violations',
        label: 'LTMS violations',
        kind: 'number',
        min: 0,
        max: 999,
        hint: 'Violations on record. Leave at 0 if none.',
      },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['available', 'active', 'inactive']),
        hint: 'Available means free to be assigned a trip.',
      },
    ],

    title: (driver) => driver.name,

    toForm: (driver) => ({
      name: driver.name,
      licence_no: driver.licence_no,
      licence_expiry: driver.licence_expiry,
      violations: driver.violations,
      status: driver.status,
    }),

    toPayload: (values) => ({
      name: values['name'],
      licence_no: values['licence_no'],
      licence_expiry: values['licence_expiry'],
      violations: Number(values['violations'] ?? 0),
      status: values['status'] || 'available',
    }),

    save: (payload, id) =>
      id ? drivers.update(id, payload as never) : drivers.create(payload as never),

    remove: (id) => drivers.remove(id),
  };
}

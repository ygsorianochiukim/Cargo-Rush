import { inject } from '@angular/core';

import { CrewEmployment, Driver } from '../../models/driver/driver.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { DriverService } from './driver.service';

export const employmentOptions = () => [
  { value: 'company', label: 'Company personnel — on our crew payroll' },
  { value: 'subcontractor', label: 'Sub-contractor’s crew — paid by the sub-contractor' },
];

export function driverSpec(): RecordSpec<Driver> {
  const drivers = inject(DriverService);

  return {
    noun: 'driver',
    icon: 'profile',

    fields: [
      { key: 'name', label: 'Full name', kind: 'text', required: true, wide: true, placeholder: 'Juan Dela Cruz' },
      { key: 'contact', label: 'Contact number', kind: 'text', placeholder: '0917 555 0101' },
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
        key: 'employment',
        label: 'Who they work for',
        kind: 'select',
        options: employmentOptions,
        hint: 'Company personnel go on the crew payroll. A sub-contractor’s driver only drives their truck and is paid by them.',
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
      contact: driver.contact ?? '',
      licence_no: driver.licence_no,
      licence_expiry: driver.licence_expiry,
      violations: driver.violations,
      employment: driver.employment ?? 'company',
      status: driver.status,
    }),

    toPayload: (values) => ({
      role: 'driver',
      employment: (values['employment'] || 'company') as CrewEmployment,
      name: values['name'],
      contact: values['contact'] || null,
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

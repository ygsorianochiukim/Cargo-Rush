import { inject } from '@angular/core';

import { CrewEmployment, Driver } from '../../models/driver/driver.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { employmentOptions } from './driver.form';
import { DriverService } from './driver.service';

export function helperSpec(): RecordSpec<Driver> {
  const drivers = inject(DriverService);

  return {
    noun: 'helper',
    icon: 'customers',

    fields: [
      { key: 'name', label: 'Full name', kind: 'text', required: true, wide: true, placeholder: 'Pedro Santos' },
      { key: 'contact', label: 'Contact number', kind: 'text', placeholder: '0917 555 0202' },
      {
        key: 'employment',
        label: 'Who they work for',
        kind: 'select',
        options: employmentOptions,
        hint: 'Company personnel go on the crew payroll. A sub-contractor’s helper is paid by them.',
      },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['available', 'active', 'inactive']),
        hint: 'Available means free to ride along on a trip.',
      },
    ],

    title: (helper) => helper.name,

    toForm: (helper) => ({
      name: helper.name,
      contact: helper.contact ?? '',
      employment: helper.employment ?? 'company',
      status: helper.status,
    }),

    toPayload: (values) => ({
      role: 'helper',
      employment: (values['employment'] || 'company') as CrewEmployment,
      name: values['name'],
      contact: values['contact'] || null,
      status: values['status'] || 'available',
    }),

    save: (payload, id) =>
      id ? drivers.update(id, payload as never) : drivers.create(payload as never),

    remove: (id) => drivers.remove(id),
  };
}

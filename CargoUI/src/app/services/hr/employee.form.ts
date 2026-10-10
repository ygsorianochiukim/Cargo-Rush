import { inject } from '@angular/core';

import { Employee } from '../../models/hr/hr.model';
import { RecordSpec, statusOptions, toFormData } from '../../shared/record-form-spec';
import { AccessService } from '../identity/access.service';
import { EmployeeService } from './employee.service';

const CLEARABLE = [
  'middle_name',
  'birth_date',
  'blood_type',
  'religion',
  'email',
  'address',
  'emergency_contact',
  'emergency_phone',
  'notes',
] as const;

export function employeeSpec(): RecordSpec<Employee> {
  const employees = inject(EmployeeService);
  const access = inject(AccessService);

  const jobs: { value: string; label: string }[] = [];

  const driving = new Set<string>();

  access.positions(true).subscribe((res) => {
    jobs.length = 0;
    jobs.push(...res.data.map((p) => ({ value: p.id, label: p.name })));

    driving.clear();

    for (const position of res.data) {
      if (position.drives) driving.add(position.id);
    }
  });

  const jobDrives = (values: Record<string, unknown>): boolean =>
    driving.has(String(values['position_id'] ?? ''));

  return {
    noun: 'employee',
    icon: 'badge',

    fields: [
      { key: 'first_name', label: 'First name', kind: 'text', required: true },
      { key: 'last_name', label: 'Last name', kind: 'text', required: true },
      { key: 'middle_name', label: 'Middle name', kind: 'text' },
      {
        key: 'position_id',
        label: 'Position',
        kind: 'select',
        options: () => jobs,
        hint: 'Manage the list in Access Control.',
      },
      {
        key: 'position',
        label: 'Custom title',
        kind: 'text',
        placeholder: 'Night Warehouse Supervisor',
        hint: 'Only if the list has no name for it.',
      },
      {
        key: 'employment_type',
        label: 'Employment',
        kind: 'select',
        options: () => [
          { value: 'trainee', label: 'Trainee' },
          { value: 'probationary', label: 'Probationary' },
          { value: 'regular', label: 'Regular' },
          { value: 'contractual', label: 'Contractual' },
          { value: 'part_time', label: 'Part-time' },
        ],
      },
      { key: 'hired_on', label: 'Hired on', kind: 'date', required: true },
      { key: 'birth_date', label: 'Date of birth', kind: 'date' },
      {
        key: 'blood_type',
        label: 'Blood type',
        kind: 'select',
        options: () =>
          ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'].map((type) => ({
            value: type,
            label: type,
          })),
      },
      {
        key: 'religion',
        label: 'Religion',
        kind: 'select',
        options: () =>
          [
            'Roman Catholic',
            'Iglesia ni Cristo',
            'Islam',
            'Evangelical Christian',
            'Born Again Christian',
            'Aglipayan',
            'Seventh-day Adventist',
            'Baptist',
            'Jehovah’s Witness',
            'Latter-day Saints',
            'Buddhism',
            'Other',
            'Prefer not to say',
          ].map((religion) => ({ value: religion, label: religion })),
      },
      { key: 'contact', label: 'Contact number', kind: 'text', required: true },
      {
        key: 'email',
        label: 'Email',
        kind: 'text',
        hint: 'Their own address, not the login.',
      },
      { key: 'address', label: 'Address', kind: 'text', wide: true },
      { key: 'emergency_contact', label: 'Emergency contact', kind: 'text' },
      { key: 'emergency_phone', label: 'Emergency number', kind: 'text' },
      {
        key: 'amount',
        label: 'Negotiated pay (₱)',
        kind: 'money',
        blank: '',
        hint: 'Blank keeps their current pay. A figure here starts a new contract today.',
        showWhen: (_values, editing) => editing,
      },


      {
        key: 'licence_no',
        label: 'Licence number',
        kind: 'text',
        required: true,
        placeholder: 'N01-23-456789',
        hint: 'Exactly as printed. Matches an existing driver record if there is one.',
        showWhen: jobDrives,
      },
      {
        key: 'licence_expiry',
        label: 'Licence expiry',
        kind: 'date',
        required: true,
        hint: 'The system warns you 90 days out.',
        showWhen: jobDrives,
      },

      {
        key: 'photo',
        label: 'Photograph',
        kind: 'file',
        accept: 'image/*',
        hint: (_values, editing) =>
          editing ? 'Blank keeps the photo on file.' : 'Optional. A clear photo of their face.',
      },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        blank: 'active',
        options: statusOptions(['active', 'inactive']),
        showWhen: (_values, editing) => editing,
      },
      { key: 'notes', label: 'Notes', kind: 'textarea', wide: true },
    ],

    title: (record) => `${record.full_name} · ${record.employee_no}`,

    toForm: (record) => ({
      first_name: record.first_name,
      last_name: record.last_name,
      middle_name: record.middle_name ?? '',
      position_id: record.position_id ?? '',
      position: record.position_id ? '' : record.position,
      employment_type: record.employment_type,
      hired_on: record.hired_on,
      birth_date: record.birth_date ?? '',
      contact: record.contact,
      blood_type: record.blood_type ?? '',
      religion: record.religion ?? '',
      email: record.email ?? '',
      address: record.address ?? '',
      emergency_contact: record.emergency_contact ?? '',
      emergency_phone: record.emergency_phone ?? '',
      amount: '',
      licence_no: record.licence_no ?? '',
      licence_expiry: record.licence_expiry ?? '',
      status: record.status,
      notes: record.notes ?? '',
      photo: null,
    }),

    toPayload: (values) => {
      const money = (key: string): number | undefined => {
        const raw = values[key];

        if (raw === '' || raw === null || raw === undefined) return undefined;

        return Math.round(Number(raw) * 100);
      };

      return {
        ...values,
        amount_cents: money('amount'),
        amount: undefined,
      };
    },

    save: (payload, id) => {
      const values = payload as Record<string, unknown>;

      return id
        ? employees.save(toFormData(values, 'PATCH', CLEARABLE), id)
        : employees.save(toFormData(values), id);
    },

    remove: (id) => employees.remove(id),
  };
}

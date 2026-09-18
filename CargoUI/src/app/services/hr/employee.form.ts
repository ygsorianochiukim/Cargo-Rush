import { inject } from '@angular/core';

import { Employee } from '../../models/hr/hr.model';
import { RecordSpec, statusOptions, toFormData } from '../../shared/record-form-spec';
import { AccessService } from '../identity/access.service';
import { EmployeeService } from './employee.service';

/**
 * Employee registration — the record, including the photograph.
 *
 * This is the one spec that sends `FormData` rather than JSON, because of the
 * upload. `toFormData` drops anything the person left blank, which matters
 * more here than it looks: in multipart every value is a string, so an
 * untouched optional field would arrive as `""` and overwrite a real value
 * with a blank on the next edit.
 *
 * The employee number is not a field. It is allocated by the API when the
 * office has none to give, and a number that has been on a payslip is never
 * reissued — which is not something a form control can promise.
 *
 * **The driver details are separate, and conditional.** There used to be a
 * *Driver record* dropdown here listing every driver on file. It asked
 * somebody hiring a mechanic to choose from a fleet of drivers for no reason,
 * and somebody hiring an actual driver to choose a record that does not exist
 * yet — so registering a driver meant creating half a person in Drivers
 * Management first and coming back. It is now a licence number and an expiry,
 * shown only when the chosen job drives, and the API finds or opens the
 * `drivers` row from the licence.
 */
export function employeeSpec(): RecordSpec<Employee> {
  const employees = inject(EmployeeService);
  const access = inject(AccessService);

  const jobs: { value: string; label: string }[] = [];

  /**
   * The positions that need a licence, by id.
   *
   * Held as a set rather than re-derived per keystroke, and filled from the
   * API's own `drives` flag rather than guessed from the job's name — "Long-haul
   * Driver" and "Yard Marshal" are not a pattern a client can match, and the
   * server is the one that will accept or refuse the save.
   */
  const driving = new Set<string>();

  /**
   * What each job pays a regular hire, by position id — for telling the office
   * what leaving the figure blank will do.
   *
   * The form does not fill the box in. It cannot: the shared record form has no
   * hook for setting one field from another, and inventing one for this would
   * be a lot of machinery for a hint. What actually opens the contract is the
   * **API**, when the figure is left out of the payload — see
   * `EmployeeService::openingContract()`. This map only lets the hint say which
   * figure is coming, so leaving it blank is a choice rather than an omission
   * somebody discovers afterwards.
   *
   * It quotes the **regular** rate. The card has three and the one that applies
   * follows from the employment type chosen further up the same form, which
   * this hint cannot see — so it names the one an office is most likely to mean
   * rather than guessing, and the API opens the contract on the right one
   * either way.
   */
  const jobPay = new Map<string, string>();

  // Active only: a retired position is one the office has stopped hiring for,
  // and offering it would quietly put new people back into it.
  access.positions(true).subscribe((res) => {
    jobs.length = 0;
    jobs.push(...res.data.map((p) => ({ value: p.id, label: p.name })));

    driving.clear();
    jobPay.clear();

    for (const position of res.data) {
      if (position.drives) driving.add(position.id);

      if (!position.has_rate_card) continue;

      jobPay.set(
        position.id,
        `₱${(position.regular_amount_cents / 100).toLocaleString()} ${position.pay_basis_unit}`,
      );
    }
  });

  /** Does the job currently chosen on the form need a licence? */
  const jobDrives = (values: Record<string, unknown>): boolean =>
    driving.has(String(values['position_id'] ?? ''));

  /** The job's rate as peso text, or null where nobody has priced it. */
  const payOf = (values: Record<string, unknown>): string | null =>
    jobPay.get(String(values['position_id'] ?? '')) ?? null;

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
      { key: 'department', label: 'Department', kind: 'text', placeholder: 'Operations' },
      {
        key: 'employment_type',
        label: 'Employment',
        kind: 'select',
        // Also the column of the job's rate card the opening contract is taken
        // from — contractual and part-time read the regular figure.
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
      /**
       * One box: what this person is paid.
       *
       * The *kind* of pay is not asked here at all — it belongs to the job, and
       * asking it again on the hire form was the clearest duplication in the
       * whole setup: two places to answer one question, free to disagree. So
       * the position settles whether this is a month, a day or a haul, and this
       * is only the figure.
       *
       * Blank takes the job's rate card, on the tier chosen above. A number
       * here is this person's alone, which is what a negotiated salary is.
       *
       * On an **edit**, a figure here writes a new contract dated today rather
       * than altering the one in force — so last fortnight's payslip still says
       * what it said. Backdating a correction, or dating a rise forward, is the
       * contract screen's job rather than this one's.
       */
      {
        key: 'amount',
        label: 'Pay (₱)',
        kind: 'money',
        // Blank rather than 0, so leaving it alone means "take the job's rate"
        // instead of "pay nothing". See `toPayload`.
        blank: '',
        hint: (values) => {
          const pay = payOf(values);

          return pay === null
            ? 'What the job pays is set on the position; a figure here is for this person only.'
            : `Leave blank to use this job’s rate — ${pay}. A figure here is for this person only.`;
        },
      },

      /**
       * Which contributions come off this person's pay.
       *
       * Three answers rather than one, because they are three separate
       * registrations and a fleet genuinely has people on some and not others:
       * a casual hand taken on for the season, somebody not yet registered,
       * somebody already contributing through another employer.
       *
       * Deducted is the default everywhere and the only safe one — a form that
       * opened on "not deducted" would stop a company's contributions the day
       * somebody edited a phone number.
       *
       * There is **no field for withholding tax**, deliberately. Whether
       * somebody is taxed is not the firm's to choose; the API answers it from
       * the BIR's exemption threshold.
       */
      ...(['sss', 'philhealth', 'pagibig'] as const).map((agency) => ({
        key: `${agency}_enrolled`,
        label: { sss: 'SSS', philhealth: 'PhilHealth', pagibig: 'Pag-IBIG' }[agency],
        kind: 'select' as const,
        options: () => [
          { value: 'yes', label: 'Deducted' },
          { value: 'no', label: 'Not deducted' },
        ],
        hint:
          agency === 'sss'
            ? 'Switching one off stops it being withheld. It does not change what the agency is owed.'
            : undefined,
      })),

      /**
       * The most one payslip may take off the store tab.
       *
       * Blank or zero means the whole outstanding balance, which is what a
       * mini-mart tab settled each cutoff actually does — so the field is for
       * the firm that would rather spread a large one, and doing nothing is the
       * ordinary arrangement rather than an unanswered question.
       */
      {
        key: 'store_deduction_cap',
        label: 'Store deduction cap (₱)',
        kind: 'money' as const,
        hint: 'The most one payslip takes off the mini-mart tab. Zero takes the whole balance.',
      },

      /**
       * The driver details. On screen only when the job drives.
       *
       * Required there, and the API says the same — this is the client half of
       * one rule, not a second rule. A licence typed against an office job is
       * dropped rather than obeyed at the other end, so the two cannot disagree
       * about who ends up on the driver roster.
       */
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
        hint: 'Blank keeps the photo on file.',
      },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['active', 'inactive']),
      },
      { key: 'notes', label: 'Notes', kind: 'textarea', wide: true },
    ],

    title: (record) => `${record.full_name} · ${record.employee_no}`,

    toForm: (record) => ({
      first_name: record.first_name,
      last_name: record.last_name,
      middle_name: record.middle_name ?? '',
      position_id: record.position_id ?? '',
      // Left blank when the title came from the list — the API copies the
      // label across, so resending it would only be a chance to disagree.
      position: record.position_id ? '' : record.position,
      department: record.department ?? '',
      employment_type: record.employment_type,
      hired_on: record.hired_on,
      birth_date: record.birth_date ?? '',
      contact: record.contact,
      email: record.email ?? '',
      address: record.address ?? '',
      emergency_contact: record.emergency_contact ?? '',
      emergency_phone: record.emergency_phone ?? '',
      // Blank rather than the figure in force: this box writes a *new*
      // contract, so pre-filling it with what they are already on would make
      // every unrelated edit look like a pay change waiting to be saved.
      amount: '',
      // `yes`/`no` rather than booleans, because a select's value is a string
      // and `toPayload` turns them back. Defaulting to enrolled on a record
      // that predates the columns keeps the form saying what payroll does.
      sss_enrolled: record.sss_enrolled === false ? 'no' : 'yes',
      philhealth_enrolled: record.philhealth_enrolled === false ? 'no' : 'yes',
      pagibig_enrolled: record.pagibig_enrolled === false ? 'no' : 'yes',
      store_deduction_cap: (record.store_deduction_cap_cents ?? 0) / 100,
      // Read back off the driver record, so reopening somebody shows the
      // licence on file rather than an empty box that looks like it was never
      // entered. Blank for everybody who does not drive — the fields are not
      // on screen for them anyway.
      licence_no: record.licence_no ?? '',
      licence_expiry: record.licence_expiry ?? '',
      status: record.status,
      notes: record.notes ?? '',
      // Never prefilled: a file input cannot be given a value, and the API
      // reads a missing photo as "leave the one on file alone".
      photo: null,
    }),

    /**
     * Blank means **absent**, not zero.
     *
     * The API copies a job's rate onto the hire for any pay field the payload
     * leaves out, and sending a coerced `0` would look like a deliberate
     * instruction and suppress the copy — so a form left blank would silently
     * hire everybody on nothing. A figure typed by the office is sent as
     * typed, including a real 0 for somebody paid per trip.
     *
     * `toFormData` drops `undefined` on the way into the multipart body, which
     * is what makes "absent" reach the server as absent.
     */
    toPayload: (values) => {
      const money = (key: string): number | undefined => {
        const raw = values[key];

        if (raw === '' || raw === null || raw === undefined) return undefined;

        return Math.round(Number(raw) * 100);
      };

      /**
       * The selects come back as `yes`/`no`; the API wants booleans.
       *
       * Sent as `1`/`0` rather than `true`/`false` because this payload
       * becomes multipart — `toFormData` stringifies, and a `false` would
       * arrive as the string "false", which is truthy. Laravel's `boolean`
       * rule reads "1" and "0" exactly as intended.
       */
      const enrolled = (key: string): string | undefined => {
        const raw = values[key];

        if (raw === '' || raw === null || raw === undefined) return undefined;

        return raw === 'no' ? '0' : '1';
      };

      return {
        ...values,
        amount_cents: money('amount'),
        amount: undefined,
        sss_enrolled: enrolled('sss_enrolled'),
        philhealth_enrolled: enrolled('philhealth_enrolled'),
        pagibig_enrolled: enrolled('pagibig_enrolled'),
        store_deduction_cap_cents: money('store_deduction_cap') ?? 0,
        store_deduction_cap: undefined,
      };
    },

    save: (payload, id) => {
      const values = payload as Record<string, unknown>;

      return employees.save(toFormData(values, id ? 'PATCH' : undefined), id);
    },

    remove: (id) => employees.remove(id),
  };
}

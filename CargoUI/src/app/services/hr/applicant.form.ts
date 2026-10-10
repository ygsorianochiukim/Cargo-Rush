import { inject } from '@angular/core';

import { Applicant } from '../../models/hr/hr.model';
import { RecordSpec, toFormData } from '../../shared/record-form-spec';
import { ApplicantService } from './applicant.service';

export function applicantSpec(): RecordSpec<Applicant> {
  const applicants = inject(ApplicantService);

  return {
    noun: 'applicant',
    icon: 'inbox',

    fields: [
      { key: 'first_name', label: 'First name', kind: 'text', required: true },
      { key: 'last_name', label: 'Last name', kind: 'text', required: true },
      {
        key: 'position_applied',
        label: 'Applying for',
        kind: 'text',
        required: true,
        placeholder: 'Helper',
      },
      { key: 'contact', label: 'Contact number', kind: 'text', required: true },
      { key: 'email', label: 'Email', kind: 'text' },
      {
        key: 'source',
        label: 'Source',
        kind: 'text',
        placeholder: 'Walk-in',
        hint: 'Referral, walk-in, job post.',
      },
      { key: 'applied_on', label: 'Applied on', kind: 'date', hint: 'Defaults to today.' },
      {
        key: 'rating',
        label: 'Rating',
        kind: 'number',
        min: 1,
        max: 5,
        hint: 'Out of five.',
      },
      { key: 'address', label: 'Address', kind: 'text', wide: true },
      { key: 'photo', label: 'Photograph', kind: 'file', accept: 'image/*' },
      {
        key: 'resume',
        label: 'CV',
        kind: 'file',
        accept: '.pdf,.doc,.docx,image/*',
        hint: 'PDF, Word, or a photo of one.',
      },
      { key: 'notes', label: 'Notes', kind: 'textarea', wide: true },
    ],

    title: (record) => `${record.full_name} · ${record.position_applied}`,

    toForm: (record) => ({
      first_name: record.first_name,
      last_name: record.last_name,
      position_applied: record.position_applied,
      contact: record.contact,
      email: record.email ?? '',
      source: record.source ?? '',
      applied_on: record.applied_on,
      rating: record.rating ?? 0,
      address: record.address ?? '',
      notes: record.notes ?? '',
      photo: null,
      resume: null,
    }),

    toPayload: (values) => ({
      ...values,
      rating: Number(values['rating'] ?? 0) > 0 ? values['rating'] : undefined,
    }),

    save: (payload, id) => {
      const values = payload as Record<string, unknown>;

      return applicants.save(toFormData(values, id ? 'PATCH' : undefined), id);
    },

    remove: (id) => applicants.remove(id),
  };
}

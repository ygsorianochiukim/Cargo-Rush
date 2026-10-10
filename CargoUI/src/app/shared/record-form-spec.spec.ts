import { describe, expect, it } from 'vitest';

import { toFormData } from './record-form-spec';

describe('toFormData', () => {
  it('drops blanks it was not told may be cleared', () => {
    const form = toFormData({ email: '', photo: null, amount_cents: undefined }, 'PATCH');

    expect(form.has('email')).toBe(false);
    expect(form.has('photo')).toBe(false);
    expect(form.has('amount_cents')).toBe(false);
    expect(form.get('_method')).toBe('PATCH');
  });

  it('sends a clearable blank as an empty string', () => {
    const form = toFormData({ email: '', religion: null, contact: '0917' }, 'PATCH', [
      'email',
      'religion',
    ]);

    expect(form.get('email')).toBe('');
    expect(form.get('religion')).toBe('');
    expect(form.get('contact')).toBe('0917');
  });

  it('still leaves out an undefined clearable key', () => {
    const form = toFormData({ email: undefined }, 'PATCH', ['email']);

    expect(form.has('email')).toBe(false);
  });
});

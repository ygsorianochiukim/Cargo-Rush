import { StatusValue } from '../models/shared/status.model';

export interface FieldSpec {
  key: string;
  label: string;
  kind:
    | 'text'
    | 'number'
    | 'money'
    | 'date'
    | 'datetime'
    | 'select'
    | 'textarea'
    | 'checkbox'
    | 'file';
  required?: boolean;
  hint?: string | ((values: Record<string, unknown>, editing: boolean) => string);
  placeholder?: string;
  blank?: unknown;
  options?: () => { value: string; label: string }[];
  accept?: string;
  min?: number;
  max?: number;
  wide?: boolean;
  showWhen?: (values: Record<string, unknown>, editing: boolean) => boolean;
}

export function toFormData(
  values: Record<string, unknown>,
  method?: 'PATCH' | 'PUT',
  clearable: readonly string[] = [],
): FormData {
  const form = new FormData();

  if (method) form.append('_method', method);

  for (const [key, value] of Object.entries(values)) {
    if ((value === null || value === '') && clearable.includes(key)) {
      form.append(key, '');
      continue;
    }

    if (value === null || value === undefined || value === '') continue;

    if (value instanceof File) {
      form.append(key, value);
    } else if (typeof value === 'boolean') {
      form.append(key, value ? '1' : '0');
    } else {
      form.append(key, String(value));
    }
  }

  return form;
}

export interface RecordSpec<T = Record<string, unknown>> {
  noun: string;
  icon: string;
  fields: FieldSpec[];
  title: (record: T) => string;
  toForm?: (record: T) => Record<string, unknown>;
  toPayload: (values: Record<string, unknown>) => unknown;
  save: (values: unknown, id?: string) => import('rxjs').Observable<T>;
  remove?: (id: string) => import('rxjs').Observable<void>;
}

export function statusOptions(values: StatusValue[]) {
  return () =>
    values.map((value) => ({
      value,
      label: value.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()),
    }));
}

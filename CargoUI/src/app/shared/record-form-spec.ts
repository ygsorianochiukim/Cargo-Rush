import { StatusValue } from '../models/shared/status.model';

/** One control on a record form. */
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
    /**
     * An upload — a staff photograph, an applicant's CV.
     *
     * The control's value is the `File` itself, not a path, because the file
     * has to reach the API as multipart. A spec with a file field builds
     * `FormData` in its `toPayload`; everything else keeps sending JSON.
     */
    | 'file';
  required?: boolean;
  hint?: string;
  placeholder?: string;
  /** `select` only. Resolved when the dialog opens, so it can hold live rows. */
  options?: () => { value: string; label: string }[];
  /** `file` only, e.g. `image/*` or `.pdf,.doc,.docx`. */
  accept?: string;
  min?: number;
  max?: number;
  /** Half width on a two-column form. Defaults to half; `true` spans both. */
  wide?: boolean;
}

/**
 * Turn a flat set of values into `FormData`, dropping what the caller left out.
 *
 * A form with an upload on it cannot be sent as JSON, and the parts of Laravel
 * that read multipart do not read it on PATCH or PUT — so an edit goes out as a
 * POST carrying `_method`, which is the convention Laravel already understands.
 *
 * Empty strings are dropped rather than sent. In multipart everything is a
 * string, so an untouched optional field would otherwise arrive as `""` and
 * overwrite a real value with a blank.
 */
export function toFormData(
  values: Record<string, unknown>,
  method?: 'PATCH' | 'PUT',
): FormData {
  const form = new FormData();

  if (method) form.append('_method', method);

  for (const [key, value] of Object.entries(values)) {
    if (value === null || value === undefined || value === '') continue;

    if (value instanceof File) {
      form.append(key, value);
    } else if (typeof value === 'boolean') {
      // "1"/"0" rather than "true"/"false": PHP reads the former as a boolean
      // and the latter as a non-empty string, which is always truthy.
      form.append(key, value ? '1' : '0');
    } else {
      form.append(key, String(value));
    }
  }

  return form;
}

/**
 * Everything a module needs to offer create and edit.
 *
 * DESIGN.md section 8.1 asks for one form component serving both, with the
 * labels, validation and payload builder written once. Seven modules needing
 * the same flat record form made that a schema rather than seven components:
 * a module declares its fields and how to read and write them, and the shared
 * form does the rest.
 *
 * Anything that is *not* a flat record keeps its own component — the trip form
 * and the ledger form both do, because their derived totals and cross-field
 * rules do not belong in a generic renderer.
 */
export interface RecordSpec<T = Record<string, unknown>> {
  /** Singular, lower case: "vehicle". Used in titles and confirmations. */
  noun: string;
  icon: string;
  fields: FieldSpec[];
  /** Names the record in a delete confirmation, e.g. its plate. */
  title: (record: T) => string;
  /** Existing record to form values. Absent means create. */
  toForm?: (record: T) => Record<string, unknown>;
  /** Form values to the API payload. */
  toPayload: (values: Record<string, unknown>) => unknown;
  save: (values: unknown, id?: string) => import('rxjs').Observable<T>;
  remove?: (id: string) => import('rxjs').Observable<void>;
}

/** The statuses a record form offers, as select options. */
export function statusOptions(values: StatusValue[]) {
  return () =>
    values.map((value) => ({
      value,
      label: value.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()),
    }));
}

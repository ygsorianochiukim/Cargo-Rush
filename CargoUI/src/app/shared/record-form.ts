import { ChangeDetectionStrategy, Component, computed, effect, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';

import { Field } from './field';
import { Modal } from './modal';
import { FieldSpec } from './record-form-spec';
import { RecordDialog } from './record-dialog';

/**
 * Renders whichever module's record form the dialog is holding.
 *
 * Mounted once in the layout. Building the `FormGroup` from the spec means a
 * module adds a field by naming it, and its label, its validation and its
 * place in the payload all follow — rather than being three edits in three
 * files that can drift.
 */
@Component({
  selector: 'app-record-form',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Modal, Field, ReactiveFormsModule],
  templateUrl: './record-form.html',
})
export class RecordForm {
  private readonly dialog = inject(RecordDialog);
  private readonly fb = inject(FormBuilder);

  protected readonly open = this.dialog.open;
  protected readonly spec = this.dialog.spec;
  protected readonly record = this.dialog.record;

  protected readonly saving = signal(false);
  protected readonly failure = signal<string | null>(null);
  protected readonly editing = computed(() => this.record() !== null);

  protected readonly form = signal<FormGroup>(this.fb.group({}));

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly title = computed(() => {
    const spec = this.spec();
    if (spec === null) return '';

    return this.editing() ? `Edit ${spec.noun}` : `New ${spec.noun}`;
  });

  protected readonly subtitle = computed(() => {
    const spec = this.spec();
    const record = this.record();

    if (spec === null) return '';

    return record ? spec.title(record) : `Add a ${spec.noun} to the system`;
  });

  constructor() {
    // Rebuilt whenever the dialog opens, because the next module's fields are
    // a different set of controls, not the same ones with new values.
    effect(() => {
      if (!this.open()) return;

      const spec = this.spec();
      if (spec === null) return;

      const record = this.record();
      const values = record && spec.toForm ? spec.toForm(record) : {};

      const controls: Record<string, FormControl> = {};

      for (const field of spec.fields) {
        controls[field.key] = new FormControl(
          values[field.key] ?? this.blankFor(field),
          this.validatorsFor(field),
        );
      }

      this.form.set(this.fb.group(controls));
      this.failure.set(null);
    });
  }

  /** Options are resolved per open, so a select can hold rows fetched since. */
  protected optionsFor(field: FieldSpec) {
    return field.options?.() ?? [];
  }

  protected errorFor(key: string): string | null {
    const control = this.form().get(key);
    if (!control || control.valid || !(control.touched || control.dirty)) return null;
    if (control.hasError('required')) return 'This field is required.';
    if (control.hasError('min')) return 'This value is too small.';
    if (control.hasError('max')) return 'This value is too large.';

    return 'Check this value.';
  }

  protected reset(): void {
    this.saving.set(false);
    this.failure.set(null);
  }

  protected submit(): void {
    const spec = this.spec();
    const form = this.form();

    if (spec === null) return;

    if (form.invalid) {
      form.markAllAsTouched();

      return;
    }

    this.saving.set(true);
    this.failure.set(null);

    const record = this.record() as { id?: string } | null;

    spec.save(spec.toPayload(form.getRawValue()), record?.id).subscribe({
      next: (saved) => {
        this.saving.set(false);
        this.dialog.announceSaved(saved);
        this.open.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.saving.set(false);
        this.applyServerErrors(error);
      },
    });
  }

  /**
   * Put the API's own validation back on the fields it belongs to.
   *
   * The rules live server-side, so a 422 is the authority — showing it as one
   * banner would make the person hunt for which field it meant.
   */
  private applyServerErrors(error: HttpErrorResponse): void {
    const errors = error.error?.errors as Record<string, string[]> | undefined;

    if (error.status === 422 && errors) {
      for (const [key, messages] of Object.entries(errors)) {
        const control = this.form().get(key);
        if (control) {
          control.setErrors({ server: messages[0] });
          control.markAsTouched();
        }
      }

      // A rule about a field the form does not show still has to be readable.
      const orphan = Object.entries(errors).find(([key]) => !this.form().get(key));
      this.failure.set(orphan ? orphan[1][0] : null);

      return;
    }

    this.failure.set(error.error?.message ?? 'Could not save. Check the connection and try again.');
  }

  /** A server message wins over the generic one for that field. */
  protected serverError(key: string): string | null {
    const control = this.form().get(key);

    return (control?.errors?.['server'] as string | undefined) ?? null;
  }

  /**
   * Hold the chosen file on the control.
   *
   * A file input's `value` cannot be written to, so it is never bound to the
   * form; the control carries the `File` object itself and the spec's
   * `toPayload` packs it into `FormData`.
   */
  protected pickFile(key: string, event: Event): void {
    const input = event.target as HTMLInputElement;

    this.form().get(key)?.setValue(input.files?.[0] ?? null);
    this.form().get(key)?.markAsDirty();
  }

  /** The chosen filename, so the person can see the upload actually took. */
  protected fileName(key: string): string | null {
    const value = this.form().get(key)?.value;

    return value instanceof File ? value.name : null;
  }

  private blankFor(field: FieldSpec): unknown {
    if (field.kind === 'number' || field.kind === 'money') return 0;
    // Null rather than an empty string: the payload builder drops nulls, and a
    // file field that was never touched must not be sent at all.
    if (field.kind === 'file') return null;

    return '';
  }

  private validatorsFor(field: FieldSpec) {
    const validators = [];

    if (field.required) validators.push(Validators.required);
    if (field.min !== undefined) validators.push(Validators.min(field.min));
    if (field.max !== undefined) validators.push(Validators.max(field.max));

    return validators;
  }
}

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import {
  FormBuilder,
  FormControl,
  FormGroup,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';

import { Field } from './field';
import { Modal } from './modal';
import { FieldSpec, RecordSpec } from './record-form-spec';
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

  /**
   * The form's current values, mirrored into a signal.
   *
   * `FormGroup` is not reactive to Angular's signal graph, so `visibleFields`
   * below cannot be computed from it directly — a field whose visibility
   * depends on another field's value would never re-evaluate.
   */
  private readonly values = signal<Record<string, unknown>>({});

  /**
   * The fields actually on screen right now.
   *
   * A spec without any `showWhen` gets its whole list back, which is all but
   * one of them.
   */
  protected readonly visibleFields = computed(() => {
    const spec = this.spec();
    if (spec === null) return [];

    const values = this.values();

    return spec.fields.filter((field) => field.showWhen?.(values) ?? true);
  });

  /**
   * A field's note, resolved against the current values.
   *
   * A plain string for almost every field. A function where the note depends on
   * the rest of the form — the salary hint that says what the chosen job pays
   * is the case — and read here rather than in the template so the template
   * stays a template.
   */
  protected hintFor(field: FieldSpec): string {
    const hint = field.hint;

    if (typeof hint === 'function') return hint(this.values());

    return hint ?? '';
  }

  constructor() {
    // Rebuilt whenever the dialog opens, because the next module's fields are
    // a different set of controls, not the same ones with new values.
    effect((onCleanup) => {
      if (!this.open()) return;

      const spec = this.spec();
      if (spec === null) return;

      const record = this.record();
      const values = record && spec.toForm ? spec.toForm(record) : {};

      const controls: Record<string, FormControl> = {};

      for (const field of spec.fields) {
        controls[field.key] = new FormControl(values[field.key] ?? this.blankFor(field));
      }

      const group = this.fb.group(controls);

      this.form.set(group);
      this.values.set(group.getRawValue());
      this.applyValidators(spec, group);

      // Visibility can depend on any value, so the mirror and the validators
      // are refreshed on every change rather than only on the fields a spec
      // happens to name. Cheap for a form of this size, and it means a spec
      // does not have to declare what its `showWhen` reads.
      const changes = group.valueChanges.subscribe(() => {
        this.values.set(group.getRawValue());
        this.applyValidators(spec, group);
      });

      onCleanup(() => changes.unsubscribe());

      this.failure.set(null);
    });
  }

  /**
   * Validators follow visibility.
   *
   * A hidden `required` field would be invalid with no way for anybody to fix
   * it — the save button would simply stop working, and the field explaining
   * why is not on screen. So a field that is not shown holds no rules.
   *
   * `emitEvent: false` matters: this runs *from* `valueChanges`, and
   * re-emitting would loop.
   */
  private applyValidators(spec: RecordSpec, group: FormGroup): void {
    const values = group.getRawValue();

    for (const field of spec.fields) {
      const control = group.get(field.key);
      if (!control) continue;

      const shown = field.showWhen?.(values) ?? true;

      control.setValidators(shown ? this.validatorsFor(field) : []);
      control.updateValueAndValidity({ emitEvent: false });
    }
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

    this.form()
      .get(key)
      ?.setValue(input.files?.[0] ?? null);
    this.form().get(key)?.markAsDirty();
  }

  /** The chosen filename, so the person can see the upload actually took. */
  protected fileName(key: string): string | null {
    const value = this.form().get(key)?.value;

    return value instanceof File ? value.name : null;
  }

  private blankFor(field: FieldSpec): unknown {
    // A field that says what empty means for it wins. See `FieldSpec.blank` —
    // a money field whose blank is `0` cannot express "not answered", and a
    // payload builder that drops unanswered fields needs it to.
    if (field.blank !== undefined) return field.blank;

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

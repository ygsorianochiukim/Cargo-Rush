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

    const article = /^[aeiou]/i.test(spec.noun) ? 'an' : 'a';

    return record ? spec.title(record) : `Add ${article} ${spec.noun} to the system`;
  });

  private readonly values = signal<Record<string, unknown>>({});

  protected readonly visibleFields = computed(() => {
    const spec = this.spec();
    if (spec === null) return [];

    const values = this.values();

    const editing = this.editing();

    return spec.fields.filter((field) => field.showWhen?.(values, editing) ?? true);
  });

  protected readonly fillsRow = computed(() => {
    const lone = new Set<string>();
    let open: string | null = null;

    for (const field of this.visibleFields()) {
      if (field.wide) {
        if (open !== null) lone.add(open);
        open = null;
      } else {
        open = open === null ? field.key : null;
      }
    }

    if (open !== null) lone.add(open);

    return lone;
  });

  protected hintFor(field: FieldSpec): string {
    const hint = field.hint;

    if (typeof hint === 'function') return hint(this.values(), this.editing());

    return hint ?? '';
  }

  constructor() {
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

      const changes = group.valueChanges.subscribe(() => {
        this.values.set(group.getRawValue());
        this.applyValidators(spec, group);
      });

      onCleanup(() => changes.unsubscribe());

      this.failure.set(null);
    });
  }

  private applyValidators(spec: RecordSpec, group: FormGroup): void {
    const values = group.getRawValue();

    for (const field of spec.fields) {
      const control = group.get(field.key);
      if (!control) continue;

      const shown = field.showWhen?.(values, this.editing()) ?? true;

      control.setValidators(shown ? this.validatorsFor(field) : []);
      control.updateValueAndValidity({ emitEvent: false });
    }
  }

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

      const orphan = Object.entries(errors).find(([key]) => !this.form().get(key));
      this.failure.set(orphan ? orphan[1][0] : null);

      return;
    }

    this.failure.set(error.error?.message ?? 'Could not save. Check the connection and try again.');
  }

  protected serverError(key: string): string | null {
    const control = this.form().get(key);

    return (control?.errors?.['server'] as string | undefined) ?? null;
  }

  protected pickFile(key: string, event: Event): void {
    const input = event.target as HTMLInputElement;

    this.form()
      .get(key)
      ?.setValue(input.files?.[0] ?? null);
    this.form().get(key)?.markAsDirty();
  }

  protected fileName(key: string): string | null {
    const value = this.form().get(key)?.value;

    return value instanceof File ? value.name : null;
  }

  private blankFor(field: FieldSpec): unknown {
    if (field.blank !== undefined) return field.blank;

    if (field.kind === 'number' || field.kind === 'money') return 0;
    if (field.kind === 'file') return null;
    if (field.kind === 'checkbox') return false;

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

import { ChangeDetectionStrategy, Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute } from '@angular/router';
import { finalize } from 'rxjs';

import { PAY_SCHEDULES } from '../../models/expense/expense.model';
import { ApiService } from '../../services/shared/api.service';
import { Card } from '../../shared/card';
import { Field } from '../../shared/field';

type Values = Record<string, string | null>;
type Options = Record<string, string>;

interface Preview {
  filename: string;
  revision: number;
  fields: Values;
  issues: string[];
  categories: Options;
}

interface Editor {
  preview: Preview;
  suppliers: Options;
  payment_sources: Options;
  trips: Options;
}

interface EditorField {
  key: string;
  label: string;
  type?: 'date' | 'text';
  max?: number;
  choices?: 'categories' | 'suppliers' | 'payment_sources' | 'trips' | 'schedule' | 'vat';
}

@Component({
  selector: 'app-receipt-edit-page',
  imports: [Card, Field],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './receipt-edit.page.html',
})
export class ReceiptEditPage {
  private readonly route = inject(ActivatedRoute);
  private readonly api = inject(ApiService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly path = 'expenses/receipt-edit/' + this.route.snapshot.paramMap.get('receipt');
  private readonly ticket = /^ticket=(.+)$/.exec(this.route.snapshot.fragment ?? '')?.[1] ?? null;

  protected readonly editor = signal<Editor | null>(null);
  protected readonly values = signal<Values>({});
  protected readonly failure = signal('');
  protected readonly saved = signal(false);
  protected readonly saving = signal(false);
  protected readonly changed = computed(() => {
    const prior = this.editor()?.preview.fields ?? {};
    return Object.fromEntries(Object.entries(this.values()).filter(([key, value]) => value !== (prior[key] ?? null)));
  });
  protected readonly dirty = computed(() => Object.keys(this.changed()).length > 0);
  protected readonly fields: EditorField[] = [
    { key: 'category', label: 'Category', choices: 'categories' },
    { key: 'amount', label: 'Total amount', max: 13 },
    { key: 'date', label: 'Date', type: 'date' },
    { key: 'currency', label: 'Currency code', max: 3 },
    { key: 'payment_source_id', label: 'Paid with', choices: 'payment_sources' },
    { key: 'pay_schedule', label: 'Payment schedule', choices: 'schedule' },
    { key: 'vat', label: 'VAT', choices: 'vat' },
    { key: 'supplier_id', label: 'Supplier / biller', choices: 'suppliers' },
    { key: 'payee', label: 'Or paid to', max: 120 },
    { key: 'reference', label: 'Reference', max: 60 },
    { key: 'trip_id', label: 'Trip', choices: 'trips' },
  ];

  constructor() {
    if (!this.ticket) {
      this.failure.set('Open the receipt editor using Update in Discord.');
      return;
    }
    this.api.post<Editor>(this.path + '/open', { ticket: this.ticket }).pipe(
      takeUntilDestroyed(this.destroyRef),
    ).subscribe({
      next: (editor) => {
        this.editor.set(editor);
        this.values.set(editor.preview.fields);
      },
      error: (error: unknown) => this.showError(error),
    });
  }

  protected options(field: EditorField, editor: Editor): [string, string][] {
    switch (field.choices) {
      case 'categories': return Object.entries(editor.preview.categories);
      case 'schedule': return PAY_SCHEDULES.map(({ value, label }) => [value, label]);
      case 'vat': return [['yes', 'VAT-able'], ['no', 'Non-VAT']];
      case 'suppliers':
      case 'payment_sources':
      case 'trips': return Object.entries(editor[field.choices]);
      default: return [];
    }
  }

  protected set(key: string, input: string): void {
    this.saved.set(false);
    const value = key === 'currency' ? input.trim().toUpperCase() : input;
    this.values.update((fields) => ({ ...fields, [key]: value || null }));
  }

  protected save(): void {
    const editor = this.editor();
    if (!editor || !this.dirty() || this.saving()) return;
    this.saving.set(true);
    this.saved.set(false);
    this.failure.set('');
    this.api.put<Preview>(this.path, {
      ticket: this.ticket, revision: editor.preview.revision, fields: this.changed(),
    }).pipe(
      takeUntilDestroyed(this.destroyRef),
      finalize(() => this.saving.set(false)),
    ).subscribe({
      next: (preview) => {
        this.editor.set({ ...editor, preview });
        this.values.set(preview.fields);
        this.saved.set(true);
      },
      error: (error: unknown) => this.showError(error),
    });
  }

  private showError(error: unknown): void {
    const message = error instanceof HttpErrorResponse ? error.error?.message : null;
    this.failure.set(typeof message === 'string' ? message : 'Could not open or save this receipt. Reopen Update in Discord.');
  }
}

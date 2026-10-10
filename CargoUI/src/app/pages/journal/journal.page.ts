import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormArray, FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import {
  Account,
  JournalCategoryOption,
  JournalEntry,
  JournalLinePayload,
} from '../../models/accounting/accounting.model';
import { AccountingService, JournalQuery } from '../../services/accounting/accounting.service';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { Column, DataTable } from '../../shared/data-table';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { Modal } from '../../shared/modal';
import { ErrorState } from '../../shared/states';

@Component({
  selector: 'app-journal',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, Field, Icon, Modal, ErrorState, ReactiveFormsModule],
  templateUrl: './journal.page.html',
})
export class JournalPage {
  private readonly accounting = inject(AccountingService);
  private readonly confirm = inject(Confirm);
  private readonly fb = inject(FormBuilder);

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly rows = signal<JournalEntry[] | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly notice = signal<string | null>(null);

  protected readonly accounts = signal<Account[]>([]);
  protected readonly categories = signal<JournalCategoryOption[]>([]);

  protected readonly filters = signal<JournalQuery>({});

  protected readonly editing = signal<JournalEntry | null>(null);
  protected readonly formOpen = signal(false);
  protected readonly saving = signal(false);
  protected readonly formError = signal<string | null>(null);

  protected readonly voiding = signal<JournalEntry | null>(null);
  protected readonly voidReason = signal('');
  protected readonly voidError = signal<string | null>(null);

  protected readonly form = this.fb.nonNullable.group({
    entry_date: ['', Validators.required],
    category: ['', Validators.required],
    memo: ['', Validators.required],
    lines: this.fb.array([this.blankLine(), this.blankLine()]),
  });

  private readonly values = signal(this.form.getRawValue());

  protected readonly totalDebits = computed(() =>
    this.values().lines.reduce(
      (sum, line) => sum + (line.side === 'debit' ? this.centavos(line.amount) : 0),
      0,
    ),
  );

  protected readonly totalCredits = computed(() =>
    this.values().lines.reduce(
      (sum, line) => sum + (line.side === 'credit' ? this.centavos(line.amount) : 0),
      0,
    ),
  );

  protected readonly difference = computed(() => this.totalDebits() - this.totalCredits());
  protected readonly balanced = computed(() => this.difference() === 0 && this.totalDebits() > 0);

  constructor() {
    this.refresh();

    this.accounting.accounts(true).subscribe((res) => this.accounts.set(res.data));
    this.accounting.categories().subscribe((rows) => this.categories.set(rows));

    this.form.valueChanges.subscribe(() => this.values.set(this.form.getRawValue()));
  }

  protected get lines(): FormArray {
    return this.form.get('lines') as FormArray;
  }

  protected refresh(): void {
    this.accounting.journal(this.filters()).subscribe({
      next: (res) => {
        this.rows.set(res.data);
        this.error.set(null);
      },
      error: () => {
        this.rows.set(null);
        this.error.set('Could not load the journal. Check the connection and try again.');
      },
    });
  }

  protected setFilter(key: keyof JournalQuery, value: string): void {
    this.filters.update((current) => {
      const next = { ...current };

      if (value === '') delete next[key];
      else (next as Record<string, string>)[key] = value;

      return next;
    });

    this.refresh();
  }

  protected create(): void {
    this.editing.set(null);
    this.formError.set(null);

    this.lines.clear();
    this.lines.push(this.blankLine());
    this.lines.push(this.blankLine());

    this.form.reset({
      entry_date: new Date().toISOString().slice(0, 10),
      category: 'operations',
      memo: '',
    });

    this.values.set(this.form.getRawValue());
    this.formOpen.set(true);
  }

  protected edit(entry: JournalEntry): void {
    if (!entry.can_edit) {
      this.notice.set(
        `${entry.reference} is ${entry.status === 'void' ? 'voided' : 'posted'} and cannot be changed. ` +
          'Write a new entry — the books keep both.',
      );

      return;
    }

    this.editing.set(entry);
    this.formError.set(null);

    this.lines.clear();
    for (const line of entry.lines) {
      this.lines.push(
        this.fb.nonNullable.group({
          account_id: [line.account_id, Validators.required],
          side: [line.side, Validators.required],
          amount: [line.amount_cents / 100, [Validators.required, Validators.min(0.01)]],
          memo: [line.memo ?? ''],
        }),
      );
    }

    this.form.reset({
      entry_date: entry.entry_date,
      category: entry.category,
      memo: entry.memo,
    });

    this.values.set(this.form.getRawValue());
    this.formOpen.set(true);
  }

  protected addLine(): void {
    this.lines.push(this.blankLine());
  }

  protected removeLine(index: number): void {
    if (this.lines.length <= 2) return;

    this.lines.removeAt(index);
  }

  protected save(post: boolean): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.formError.set('Fill in the date, the kind, the narration and both sides.');

      return;
    }

    if (!this.balanced()) {
      this.formError.set(
        `The two sides do not agree — the entry is out by ${fmt.money(Math.abs(this.difference()))}.`,
      );

      return;
    }

    const values = this.form.getRawValue();

    const payload = {
      entry_date: values.entry_date,
      category: values.category,
      memo: values.memo,
      status: post ? ('posted' as const) : ('draft' as const),
      lines: values.lines.map((line): JournalLinePayload => ({
        account_id: line.account_id,
        side: line.side as 'debit' | 'credit',
        amount_cents: this.centavos(line.amount),
        memo: line.memo || null,
      })),
    };

    this.saving.set(true);
    this.formError.set(null);

    const existing = this.editing();

    const request = existing
      ? this.accounting.updateEntry(existing.id, payload)
      : this.accounting.createEntry(payload);

    request.subscribe({
      next: (entry) => {
        this.saving.set(false);
        this.formOpen.set(false);
        this.notice.set(
          entry.status === 'posted'
            ? `${entry.reference} posted.`
            : `${entry.reference} saved as a draft.`,
        );
        this.refresh();
      },
      error: (failure: { error?: { message?: string } }) => {
        this.saving.set(false);
        this.formError.set(
          failure.error?.message ??
            'The entry was not accepted. Check the two sides and try again.',
        );
      },
    });
  }

  protected async post(entry: JournalEntry): Promise<void> {
    const ok = await this.confirm.ask({
      title: `Post ${entry.reference}?`,
      body:
        'A posted entry is in the books and cannot be edited afterwards. ' +
        'It can be voided, which is kept on the record with your reason.',
      confirmLabel: 'Post entry',
    });

    if (!ok) return;

    this.accounting.postEntry(entry.id).subscribe({
      next: (posted) => {
        this.notice.set(`${posted.reference} posted.`);
        this.refresh();
      },
      error: (failure: { error?: { message?: string } }) =>
        this.notice.set(failure.error?.message ?? 'Could not post that entry.'),
    });
  }

  protected startVoid(entry: JournalEntry): void {
    this.voiding.set(entry);
    this.voidReason.set('');
    this.voidError.set(null);
  }

  protected confirmVoid(): void {
    const entry = this.voiding();

    if (entry === null) return;

    const reason = this.voidReason().trim();

    if (reason.length < 3) {
      this.voidError.set('Say why in a few words — it becomes part of the record.');

      return;
    }

    this.accounting.voidEntry(entry.id, reason).subscribe({
      next: (voided) => {
        this.voiding.set(null);
        this.notice.set(
          `${voided.reference} voided. Its lines stay in the books and count for nothing.`,
        );
        this.refresh();
      },
      error: (failure: { error?: { message?: string } }) =>
        this.voidError.set(failure.error?.message ?? 'Could not void that entry.'),
    });
  }

  protected async remove(entry: JournalEntry): Promise<void> {
    const ok = await this.confirm.ask({
      title: `Delete ${entry.reference}?`,
      body: 'A draft has never been in the books, so deleting it changes no balance.',
      confirmLabel: 'Delete draft',
      danger: true,
    });

    if (!ok) return;

    this.accounting.removeEntry(entry.id).subscribe({
      next: () => {
        this.notice.set(`${entry.reference} deleted.`);
        this.refresh();
      },
      error: (failure: { error?: { message?: string } }) =>
        this.notice.set(failure.error?.message ?? 'Could not delete that entry.'),
    });
  }

  protected readonly columns: Column<JournalEntry>[] = [
    { label: 'Reference', kind: 'strong', value: (e) => e.reference, sub: (e) => this.origin(e) },
    { label: 'Date', kind: 'num', value: (e) => fmt.date(e.entry_date) },
    { label: 'Narration', value: (e) => e.memo, sub: (e) => this.accountsOf(e) },
    { label: 'Debit', kind: 'num', value: (e) => fmt.money(e.debit_cents, e.currency) },
    { label: 'Credit', kind: 'num', value: (e) => fmt.money(e.credit_cents, e.currency) },
    { label: 'Status', kind: 'status', status: (e) => this.pill(e) },
  ];

  protected origin(entry: JournalEntry): string {
    if (entry.source === 'manual') {
      return entry.category_label;
    }

    return `${entry.category_label} · auto-posted · ${entry.source_label ?? entry.source}`;
  }

  private accountsOf(entry: JournalEntry): string {
    return entry.lines.map((line) => line.account_code ?? '—').join(' · ');
  }

  private pill(entry: JournalEntry) {
    if (entry.status === 'posted') return 'active' as const;
    if (entry.status === 'void') return 'cancelled' as const;

    return 'pending' as const;
  }

  protected accountLabel(id: string): string {
    return this.accounts().find((account) => account.id === id)?.label ?? '—';
  }

  private blankLine() {
    return this.fb.nonNullable.group({
      account_id: ['', Validators.required],
      side: ['debit', Validators.required],
      amount: [0, [Validators.required, Validators.min(0.01)]],
      memo: [''],
    });
  }

  private centavos(amount: number | string): number {
    return Math.round(Number(amount ?? 0) * 100);
  }
}

import { ChangeDetectionStrategy, Component, computed, effect, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { map } from 'rxjs';

import {
  Expense,
  ExpenseCategory,
  ExpenseReport,
  PAY_SCHEDULES,
  PaySchedule,
} from '../../models/expense/expense.model';
import { expenseSpec } from '../../services/expense/expense.form';
import { ExpenseService } from '../../services/expense/expense.service';
import { BarRow, BarRows } from '../../shared/bar-rows';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { Column, DataTable } from '../../shared/data-table';
import { Field } from '../../shared/field';
import { FilterBar, FilterOption } from '../../shared/filter-bar';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { ListToolbar } from '../../shared/list-toolbar';
import { recordList } from '../../shared/record-list';
import { ErrorState } from '../../shared/states';

@Component({
  selector: 'app-expenses',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [BarRows, Card, DataTable, Field, FilterBar, Icon, ListToolbar, ErrorState, ReactiveFormsModule],
  templateUrl: './expenses.page.html',
})
export class ExpensesPage {
  private readonly expensesApi = inject(ExpenseService);
  private readonly confirm = inject(Confirm);
  private readonly fb = inject(FormBuilder);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  private settleId: string | null = this.route.snapshot.queryParamMap.get('settle');

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  private readonly spec = expenseSpec();

  protected readonly list = recordList<Expense>(this.spec, () =>
    this.expensesApi.list({ per_page: 100 }).pipe(map((res) => res.data)),
  );

  protected readonly schedule = signal<PaySchedule | 'all'>('all');

  protected readonly scheduleFilters = computed<FilterOption[]>(() => {
    const rows = this.list.rows() ?? [];

    return [
      { value: 'all', label: 'All', count: rows.length },
      ...PAY_SCHEDULES.map((s) => ({
        value: s.value,
        label: s.label,
        count: rows.filter((r) => r.pay_schedule === s.value).length,
      })),
    ];
  });

  protected readonly rows = computed(() => {
    const rows = this.list.rows();
    const schedule = this.schedule();

    if (rows === null) return null;

    return schedule === 'all' ? rows : rows.filter((r) => r.pay_schedule === schedule);
  });

  protected readonly report = signal<ExpenseReport | null>(null);

  private readonly range = signal<{ from: string; to: string } | null>(null);
  protected readonly isThisMonth = computed(() => this.range() === null);
  protected readonly categories = signal<ExpenseCategory[] | null>(null);
  protected readonly categoryNotice = signal<string | null>(null);
  protected readonly managingCategories = signal(false);

  protected readonly categoryForm = this.fb.group({
    name: ['', Validators.required],
    description: [''],
  });

  constructor() {
    this.refreshReport();
    this.refreshCategories();

    effect(() => {
      const rows = this.list.rows();

      if (this.settleId === null || rows === null) return;

      const expense = rows.find((row) => row.id === this.settleId);

      this.settleId = null;
      void this.router.navigate([], { relativeTo: this.route, queryParams: {}, replaceUrl: true });

      if (expense) this.list.edit(expense);
    });
  }

  protected setRange(end: 'from' | 'to', value: string): void {
    const current = this.report()?.range;

    if (!value || !current) return;

    this.range.set({ ...current, [end]: value });
    this.refreshReport();
  }

  protected resetRange(): void {
    this.range.set(null);
    this.refreshReport();
  }

  protected refreshReport(): void {
    this.expensesApi.report(this.range() ?? undefined).subscribe({
      next: (report) => this.report.set(report),
      error: () => this.report.set(null),
    });
  }

  private refreshCategories(): void {
    this.expensesApi.categories().subscribe({
      next: (rows) => this.categories.set(rows),
      error: () => this.categories.set(null),
    });
  }

  protected readonly spendBars = computed<BarRow[]>(() => {
    const rows = this.report()?.categories ?? [];
    const currency = this.report()?.currency ?? 'PHP';
    const largest = Math.max(...rows.map((row) => row.amount_cents), 1);

    return rows.map((row) => ({
      key: row.category.id,
      label: row.category.name,
      value: fmt.money(row.amount_cents, currency),
      pct: Math.round((row.amount_cents / largest) * 100),
      tone: 'expense' as const,
      rows: [
        { label: 'Entries', value: String(row.entry_count) },
        {
          label: 'Share',
          value: `${Math.round((row.amount_cents / Math.max(1, this.report()?.total_cents ?? 1)) * 100)}%`,
        },
      ],
    }));
  });

  protected readonly label = (expense: Expense) =>
    `${expense.category_name ?? 'expense'} of ${fmt.money(expense.amount_cents, expense.currency)}`;

  protected readonly columns: Column<Expense>[] = [
    {
      label: 'Category',
      kind: 'strong',
      value: (e) => e.category_name,
      sub: (e) => e.trip_reference ?? e.note,
    },
    { label: 'Date', kind: 'num', value: (e) => fmt.date(e.date) },
    {
      label: 'Bought from',
      value: (e) => e.supplier_name ?? e.payee,
      sub: (e) => e.driver_name,
    },
    { label: 'Paid with', value: (e) => e.payment_source_label, sub: (e) => e.reference },
    { label: 'Paid', value: (e) => e.pay_schedule_label },
    { label: 'Amount', kind: 'num', value: (e) => fmt.money(e.amount_cents, e.currency) },
    {
      label: 'VAT',
      pill: (e) => ({ label: e.is_vatable ? 'VAT-able' : 'Non-VAT', on: e.is_vatable }),
      sub: (e) => (e.is_vatable ? `${fmt.money(e.vat_cents, e.currency)} input VAT` : null),
    },
    { label: 'Status', kind: 'status', status: (e) => e.status },
  ];

  protected addCategory(): void {
    if (this.categoryForm.invalid) {
      this.categoryForm.markAllAsTouched();

      return;
    }

    const { name, description } = this.categoryForm.getRawValue();

    this.expensesApi
      .createCategory({ name: String(name), description: description || null })
      .subscribe({
        next: () => {
          this.categoryForm.reset({ name: '', description: '' });
          this.categoryNotice.set(null);
          this.refreshCategories();
        },
        error: () => this.categoryNotice.set('Could not add that category.'),
      });
  }

  protected async removeCategory(category: ExpenseCategory): Promise<void> {
    const ok = await this.confirm.ask({
      title: `Delete ${category.name}?`,
      body: 'A category with spend filed against it is switched off instead.',
      confirmLabel: 'Delete category',
      danger: true,
    });

    if (!ok) return;

    this.expensesApi.removeCategory(category.id).subscribe({
      next: () => {
        this.expensesApi.categories().subscribe((rows) => {
          this.categories.set(rows);

          const survivor = rows.find((row) => row.id === category.id);

          this.categoryNotice.set(
            survivor
              ? `${category.name} has expenses filed against it, so it was switched off rather than deleted.`
              : null,
          );
        });
      },
      error: () => this.categoryNotice.set('Could not remove that category.'),
    });
  }

  protected toggleCategory(category: ExpenseCategory): void {
    const next = category.status === 'active' ? 'inactive' : 'active';

    this.expensesApi.updateCategory(category.id, { status: next }).subscribe({
      next: () => this.refreshCategories(),
      error: () => this.categoryNotice.set('Could not change that category.'),
    });
  }
}

import { ChangeDetectionStrategy, Component, computed, effect, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { map } from 'rxjs';

import { Expense, ExpenseCategory, ExpenseReport } from '../../models/expense/expense.model';
import { expenseSpec } from '../../services/expense/expense.form';
import { ExpenseService } from '../../services/expense/expense.service';
import { BarRow, BarRows } from '../../shared/bar-rows';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { Column, DataTable } from '../../shared/data-table';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { ListToolbar } from '../../shared/list-toolbar';
import { recordList } from '../../shared/record-list';
import { ErrorState } from '../../shared/states';

/**
 * Other Expenses — DESIGN.md section 5.1's Finance group.
 *
 * Two things on this page are worth knowing before reading the code.
 *
 * The report answers "what are we spending it on", which the ledger's five
 * fixed columns cannot: everything that was not fuel, salary, maintenance or
 * allowance used to be pushed into `allowance_cents` or left off the books.
 *
 * And overhead is shown as its own figure rather than folded into the total.
 * Spend with no truck on it is real and belongs to the period, but charging it
 * to a unit would make that unit look unprofitable for paying the office rent.
 */
@Component({
  selector: 'app-expenses',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [BarRows, Card, DataTable, Field, Icon, ListToolbar, ErrorState, ReactiveFormsModule],
  templateUrl: './expenses.page.html',
})
export class ExpensesPage {
  private readonly expensesApi = inject(ExpenseService);
  private readonly confirm = inject(Confirm);
  private readonly fb = inject(FormBuilder);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  /**
   * One expense named by a link, waiting for the list to arrive.
   *
   * Payables links here at a row — a month's rent on a hired truck, a bill
   * somebody filed as spend — rather than at the page. The edit dialog wants
   * the record and the list fetches itself, so the link has to wait for it.
   * A plain field, not a signal: nothing renders it, it fires once and is
   * spent.
   */
  private settleId: string | null = this.route.snapshot.queryParamMap.get('settle');

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  private readonly spec = expenseSpec();

  protected readonly list = recordList<Expense>(this.spec, () =>
    this.expensesApi.list().pipe(map((res) => res.data)),
  );

  protected readonly rows = this.list.rows;

  protected readonly report = signal<ExpenseReport | null>(null);

  /**
   * The window the report covers, or null for the API's default of this month.
   *
   * The inputs read their dates back off the report rather than off this, so
   * the page never works out "this month" itself — the server's clock and the
   * browser's disagree around midnight on the last day, and the server's is
   * the one the totals were counted with.
   */
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

    /**
     * Followed a Payables line to one expense — open it.
     *
     * The rent charges `cargo:truck-rent` raises land here as pending
     * expenses, and marking one settled is editing its status. Opening the
     * row is therefore the whole of "settle this" on this page.
     *
     * An effect because `recordList` owns the fetch and returns a signal. It
     * spends the latch on the first non-null list, so the refresh that
     * follows a save does not reopen the dialog over the edit just made, and
     * drops the parameter so a refresh tomorrow does not reopen a row already
     * dealt with.
     */
    effect(() => {
      const rows = this.list.rows();

      if (this.settleId === null || rows === null) return;

      const expense = rows.find((row) => row.id === this.settleId);

      this.settleId = null;
      void this.router.navigate([], { relativeTo: this.route, queryParams: {}, replaceUrl: true });

      if (expense) this.list.edit(expense);
    });
  }

  /** Move one end of the window, keeping the other where the report has it. */
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

  /**
   * Where the money went, as bars. Biggest first — the API already sorted it.
   *
   * Scaled against the biggest category rather than against the total, so the
   * shape of the spend is readable. Against the total, a month spread over
   * eight categories draws eight bars nobody can compare.
   */
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

  protected readonly overheadShare = computed(() => {
    const report = this.report();
    if (!report || report.total_cents === 0) return 0;

    return Math.round((report.overhead_cents / report.total_cents) * 100);
  });

  protected readonly label = (expense: Expense) =>
    `${expense.category_name ?? 'expense'} of ${fmt.money(expense.amount_cents, expense.currency)}`;

  protected readonly columns: Column<Expense>[] = [
    {
      label: 'Category',
      kind: 'strong',
      value: (e) => e.category_name,
      sub: (e) => e.note,
    },
    { label: 'Date', kind: 'num', value: (e) => fmt.date(e.date) },
    /**
     * Who it was bought from, and who it was for.
     *
     * This column used to be "Charged to", naming the truck the spend was
     * filed against. The form stopped asking for a truck — what a unit costs to
     * run is a maintenance job on the unit now — so the column would have read
     * "Fleet overhead" on every row for ever, which is a column that has
     * stopped saying anything.
     *
     * The supplier's name where there is one, and the typed `payee` where there
     * is not: a one-off from a roadside seller never gets a record, and still
     * has to print as something.
     *
     * The driver underneath is history rather than a field: the form stopped
     * asking who spend was for — what a crew costs is payroll and the sheet's
     * own crew columns — and the rows filed before that still have to read back
     * the way they were entered.
     */
    {
      label: 'Bought from',
      value: (e) => e.supplier_name ?? e.payee,
      sub: (e) => e.driver_name,
    },
    { label: 'Reference', kind: 'muted', value: (e) => e.reference },
    { label: 'Amount', kind: 'num', value: (e) => fmt.money(e.amount_cents, e.currency) },
    { label: 'Status', kind: 'status', status: (e) => e.status },
  ];

  /* --------------------------------------------------------- Categories */

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

  /**
   * Delete a category, or hear that it was retired instead.
   *
   * The list is re-read rather than the row being removed locally, because the
   * two outcomes look identical from the call site and only the server knows
   * which happened. A category that comes back switched off was kept, and the
   * office is told why.
   */
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

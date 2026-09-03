import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

import { LedgerEntry, Truck } from '../../models/finance/finance.model';
import { netIncome, totalExpenses } from '../../services/finance/finance.math';
import { FinanceService } from '../../services/finance/finance.service';
import { truckSpec } from '../../services/finance/truck.form';
import { RecordDialog } from '../../shared/record-dialog';
import { EmptyState } from '../../shared/states';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { LedgerDialog } from '../../shared/ledger-dialog';

/**
 * Daily Trip Monitoring — the workbook's per-truck sheets (MAR1390, CBS8862, …).
 * Pick a unit, see and edit its daily rows; the header totals mirror the
 * summary row the workbook keeps above each table.
 */
@Component({
  selector: 'app-monitoring',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Icon, EmptyState],
  templateUrl: './monitoring.page.html',
})
export class MonitoringPage {
  private readonly financeApi = inject(FinanceService);
  private readonly confirm = inject(Confirm);
  private readonly dialog = inject(LedgerDialog);
  private readonly records = inject(RecordDialog);
  private readonly unitSpec = truckSpec();

  /** Local so a newly added unit shows without a refetch. */
  private readonly loadedTrucks = toSignal(this.financeApi.trucks(), { initialValue: null });
  private readonly addedTrucks = signal<Truck[]>([]);

  protected readonly trucks = computed<Truck[] | null>(() => {
    const loaded = this.loadedTrucks();
    if (loaded === null) return null;

    return [...loaded, ...this.addedTrucks()];
  });

  private readonly loaded = toSignal(
    this.financeApi.ledger({ per_page: 100 }).pipe(map((r) => r.data)),
    { initialValue: null },
  );

  /** Local overlay so create/edit/delete show without a refetch. */
  private readonly overrides = signal<LedgerEntry[] | null>(null);
  private readonly all = computed(() => this.overrides() ?? this.loaded());

  /**
   * Which sheet is open. Empty until the trucks arrive — ids come from the
   * API now, so there is no id this page could sensibly guess at first paint.
   */
  private readonly picked = signal<string | null>(null);

  protected readonly selectedId = computed(
    () => this.picked() ?? this.trucks()?.[0]?.id ?? '',
  );

  protected readonly selected = computed(
    () => (this.trucks() ?? []).find((t) => t.id === this.selectedId()) ?? null,
  );

  protected readonly rows = computed(() => {
    const all = this.all();
    if (all === null) return null;
    return all
      .filter((e) => e.truck_id === this.selectedId())
      .slice()
      .sort((a, b) => a.date.localeCompare(b.date));
  });

  /** The sheet-header totals for the selected truck. */
  protected readonly totals = computed(() => {
    const rows = this.rows() ?? [];
    const sum = (pick: (e: LedgerEntry) => number) => rows.reduce((t, e) => t + pick(e), 0);
    const income = sum((e) => e.trip_income_cents);
    const expenses = sum(totalExpenses);
    return {
      income,
      fuel: sum((e) => e.fuel_cents),
      driver: sum((e) => e.driver_salary_cents),
      helper: sum((e) => e.helper_salary_cents),
      maintenance: sum((e) => e.maintenance_cents),
      allowance: sum((e) => e.allowance_cents),
      expenses,
      net: income - expenses,
    };
  });

  constructor() {
    this.records.savedFor(this.unitSpec)
      .pipe(takeUntilDestroyed())
      .subscribe((truck) => {
        this.addedTrucks.update((added) => [...added, truck]);
        this.picked.set(truck.id);
      });

    this.dialog.saved.pipe(takeUntilDestroyed()).subscribe((entry) => {
      const current = this.all() ?? [];
      const exists = current.some((e) => e.id === entry.id);
      this.overrides.set(
        exists ? current.map((e) => (e.id === entry.id ? entry : e)) : [...current, entry],
      );
    });
  }

  protected select(id: string): void {
    this.picked.set(id);
  }

  /** Add a unit to the ledger — its own sheet, with or without a plate. */
  protected addUnit(): void {
    this.records.create(this.unitSpec);
  }

  protected add(): void {
    this.dialog.create(this.selectedId());
  }

  protected edit(entry: LedgerEntry): void {
    this.dialog.edit(entry);
  }

  protected async remove(entry: LedgerEntry): Promise<void> {
    const ok = await this.confirm.ask({
      title: `Delete the ${fmt.date(entry.date)} entry?`,
      body: `This removes the trip income and expenses recorded for ${this.selected()?.plate ?? this.selected()?.label} on that day.`,
      confirmLabel: 'Delete entry',
      danger: true,
    });
    if (!ok) return;

    this.financeApi.remove(entry.id).subscribe(() => {
      this.overrides.set((this.all() ?? []).filter((e) => e.id !== entry.id));
    });
  }

  protected expensesOf = totalExpenses;
  protected netOf = netIncome;
  protected readonly fmt = fmt;
}

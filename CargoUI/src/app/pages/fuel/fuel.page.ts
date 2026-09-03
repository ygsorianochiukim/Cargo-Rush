import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

import { FuelRecord } from '../../models/fuel/fuel.model';
import { fuelSpec } from '../../services/fuel/fuel.form';
import { FuelService } from '../../services/fuel/fuel.service';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { fmt } from '../../shared/format';
import { ListToolbar } from '../../shared/list-toolbar';
import { recordList } from '../../shared/record-list';
import { ErrorState } from '../../shared/states';

/** Fuel Expense Monitoring — DESIGN.md section 5.1. */
@Component({
  selector: 'app-fuel',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, ListToolbar, ErrorState],
  templateUrl: './fuel.page.html',
})
export class FuelPage {
  private readonly fuelApi = inject(FuelService);

  protected readonly budget = toSignal(this.fuelApi.budget(), {
    initialValue: null,
  });

  private readonly spec = fuelSpec();

  protected readonly list = recordList<FuelRecord>(this.spec, () =>
    this.fuelApi.list().pipe(map((res) => res.data)),
  );

  protected readonly rows = this.list.rows;

  protected readonly label = (f: FuelRecord) => f.receipt_no;

  protected readonly spentPct = computed(() => {
    const b = this.budget();
    if (!b || b.daily_budget_cents === 0) return 0;
    return Math.min(100, Math.round((b.spent_today_cents / b.daily_budget_cents) * 100));
  });

  protected readonly overBudget = computed(() => this.spentPct() >= 90);

  protected readonly remaining = computed(() => {
    const b = this.budget();
    if (!b) return 0;
    return Math.max(0, b.daily_budget_cents - b.spent_today_cents);
  });

  /** Litres per 100 km across the logged records — the consumption headline. */
  protected readonly totalLitres = computed(() =>
    (this.rows() ?? []).reduce((sum, r) => sum + r.litres, 0),
  );

  protected readonly fmt = fmt;

  protected readonly columns: Column<FuelRecord>[] = [
    { label: 'Vehicle', kind: 'strong', value: (f) => f.vehicle_plate, sub: (f) => f.driver_name },
    { label: 'Litres', kind: 'num', value: (f) => fmt.litres(f.litres) },
    { label: 'Amount', kind: 'num', value: (f) => fmt.money(f.amount_cents, f.currency) },
    { label: 'Odometer', kind: 'num', value: (f) => fmt.km(f.odometer_km) },
    { label: 'Receipt', kind: 'muted', value: (f) => f.receipt_no },
    { label: 'Logged', kind: 'num', value: (f) => fmt.dateTime(f.logged_at) },
    { label: 'Status', kind: 'status', status: (f) => f.status },
  ];
}

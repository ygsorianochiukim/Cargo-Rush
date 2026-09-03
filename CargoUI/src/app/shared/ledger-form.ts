import { ChangeDetectionStrategy, Component, computed, effect, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import { Customer } from '../models/customer/customer.model';
import { LedgerEntryPayload, Truck } from '../models/finance/finance.model';
import { CustomerService } from '../services/customer/customer.service';
import { FinanceService } from '../services/finance/finance.service';
import { Field } from './field';
import { fmt } from './format';
import { LedgerDialog } from './ledger-dialog';
import { Modal } from './modal';

/**
 * Create/edit one daily trip row — the workbook's per-truck "Daily Trip
 * Monitoring" line. Total expenses and net income are shown live as you type,
 * because they are derived, never entered.
 */
@Component({
  selector: 'app-ledger-form',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Modal, Field, ReactiveFormsModule],
  templateUrl: './ledger-form.html',
})
export class LedgerForm {
  private readonly financeApi = inject(FinanceService);
  private readonly customerApi = inject(CustomerService);
  private readonly fb = inject(FormBuilder);
  private readonly dialog = inject(LedgerDialog);

  protected readonly open = this.dialog.open;
  protected readonly entry = this.dialog.entry;
  protected readonly truckId = this.dialog.truckId;

  protected readonly saving = signal(false);
  protected readonly editing = computed(() => this.entry() !== null);

  protected readonly trucks = signal<Truck[]>([]);
  protected readonly routes = signal<string[]>([]);

  /** For naming whose work a day was, which is what puts it on their history. */
  protected readonly customers = signal<Customer[]>([]);

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly form = this.fb.nonNullable.group({
    truck_id: ['', Validators.required],
    date: ['', Validators.required],
    trip_income: [0, [Validators.required, Validators.min(0)]],
    fuel: [0, [Validators.required, Validators.min(0)]],
    driver_salary: [0, [Validators.required, Validators.min(0)]],
    helper_salary: [0, [Validators.required, Validators.min(0)]],
    maintenance: [0, [Validators.required, Validators.min(0)]],
    allowance: [0, [Validators.required, Validators.min(0)]],
    customer_id: [''],
    route: [''],
    remarks: [''],
  });

  /** Live derivation, mirroring the two workbook formulas. */
  private readonly values = signal(this.form.getRawValue());

  protected readonly totalExpenses = computed(() => {
    const v = this.values();
    return (
      Number(v.fuel) +
      Number(v.driver_salary) +
      Number(v.helper_salary) +
      Number(v.maintenance) +
      Number(v.allowance)
    );
  });

  protected readonly net = computed(() => Number(this.values().trip_income) - this.totalExpenses());

  constructor() {
    this.financeApi.trucks().subscribe((trucks) => this.trucks.set(trucks));
    this.financeApi.routes().subscribe((routes) => this.routes.set(routes));
    this.customerApi.list().subscribe((res) => this.customers.set(res.data));

    this.form.valueChanges.subscribe(() => this.values.set(this.form.getRawValue()));

    effect(() => {
      if (!this.open()) return;
      const e = this.entry();
      this.form.reset({
        truck_id: e?.truck_id ?? this.truckId() ?? '',
        date: e?.date ?? new Date().toISOString().slice(0, 10),
        trip_income: e ? e.trip_income_cents / 100 : 0,
        fuel: e ? e.fuel_cents / 100 : 0,
        driver_salary: e ? e.driver_salary_cents / 100 : 0,
        helper_salary: e ? e.helper_salary_cents / 100 : 0,
        maintenance: e ? e.maintenance_cents / 100 : 0,
        allowance: e ? e.allowance_cents / 100 : 0,
        customer_id: e?.customer_id ?? '',
        route: e?.route ?? '',
        remarks: e?.remarks ?? '',
      });
      this.values.set(this.form.getRawValue());
    });
  }

  protected errorFor(name: string): string | null {
    const c = this.form.get(name);
    if (!c || c.valid || !(c.touched || c.dirty)) return null;
    if (c.hasError('required')) return 'This field is required.';
    if (c.hasError('min')) return 'Cannot be negative.';
    return 'Check this value.';
  }

  protected reset(): void {
    this.saving.set(false);
    this.form.markAsPristine();
    this.form.markAsUntouched();
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.saving.set(true);
    const v = this.form.getRawValue();
    const existing = this.entry();
    const cents = (n: number | string) => Math.round(Number(n) * 100);

    const payload: LedgerEntryPayload = {
      truck_id: v.truck_id,
      date: v.date,
      trip_income_cents: cents(v.trip_income),
      fuel_cents: cents(v.fuel),
      driver_salary_cents: cents(v.driver_salary),
      helper_salary_cents: cents(v.helper_salary),
      maintenance_cents: cents(v.maintenance),
      allowance_cents: cents(v.allowance),
      customer_id: v.customer_id || null,
      route: v.route || null,
      remarks: v.remarks || null,
    };

    const request = existing
      ? this.financeApi.update(existing.id, payload)
      : this.financeApi.create(payload);

    request.subscribe({
      next: (saved) => {
        this.saving.set(false);
        this.dialog.announceSaved(saved);
        this.open.set(false);
      },
      error: () => this.saving.set(false),
    });
  }

  protected readonly fmt = fmt;
}

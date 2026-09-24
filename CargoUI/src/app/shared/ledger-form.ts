import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core';
import { FormBuilder, FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';

import { Customer } from '../models/customer/customer.model';
import { Driver } from '../models/driver/driver.model';
import { LedgerEntryPayload, LedgerHelperLine, Truck } from '../models/finance/finance.model';
import { CustomerService } from '../services/customer/customer.service';
import { DriverService } from '../services/driver/driver.service';
import { FinanceService } from '../services/finance/finance.service';
import { Field } from './field';
import { fmt } from './format';
import { Icon } from './icon';
import { LedgerDialog } from './ledger-dialog';
import { Modal } from './modal';

/** As many as the API takes on one day. */
const MAX_HELPERS = 5;

/** One helper's line: who, and what they were paid in pesos. */
type HelperLine = FormGroup<{ driver_id: FormControl<string>; salary: FormControl<number> }>;

/**
 * Create/edit one daily trip row — the workbook's per-truck "Daily Trip
 * Monitoring" line. Total expenses and net income are shown live as you type,
 * because they are derived, never entered.
 */
@Component({
  selector: 'app-ledger-form',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Modal, Field, Icon, ReactiveFormsModule],
  templateUrl: './ledger-form.html',
})
export class LedgerForm {
  private readonly financeApi = inject(FinanceService);
  private readonly customerApi = inject(CustomerService);
  private readonly driverApi = inject(DriverService);
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

  /**
   * The crew, for naming whose the day's two salary figures are.
   *
   * Drivers rather than employees, because that is the operational record the
   * rest of the sheet already names — and a helper is a driver record without
   * the keys, so one list serves both pickers.
   */
  protected readonly drivers = signal<Driver[]>([]);

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly form = this.fb.nonNullable.group({
    truck_id: ['', Validators.required],
    date: ['', Validators.required],
    trip_income: [0, [Validators.required, Validators.min(0)]],
    fuel: [0, [Validators.required, Validators.min(0)]],
    driver_salary: [0, [Validators.required, Validators.min(0)]],
    maintenance: [0, [Validators.required, Validators.min(0)]],
    allowance: [0, [Validators.required, Validators.min(0)]],
    /**
     * Who the driver salary belongs to.
     *
     * Optional, because plenty of days are recorded before anybody knows or
     * cares — and because every row filed before this column existed has
     * nobody in it. An unattributed row is counted toward nobody's payslip,
     * which is the safe direction.
     */
    driver_id: [''],
    /**
     * The day's helpers, one line each with their own pay.
     *
     * A line per person rather than one figure for all of them, because the
     * helpers on one day are not on one rate. The day's helper salary is the
     * sum, and the API works it out the same way.
     */
    helpers: this.fb.nonNullable.array<HelperLine>([]),
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
      this.helperTotal() +
      Number(v.maintenance) +
      Number(v.allowance)
    );
  });

  /** The day's helper salary: every line added up. */
  protected readonly helperTotal = computed(() =>
    this.values().helpers.reduce((total, line) => total + Number(line.salary), 0),
  );

  protected readonly maxHelpers = MAX_HELPERS;

  protected get helperLines() {
    return this.form.controls.helpers;
  }

  /** Nobody on two lines, and not the driver: either would be paid twice. */
  protected availableFor(index: number): Driver[] {
    const v = this.values();
    const taken = new Set(
      v.helpers.filter((_, i) => i !== index).map((line) => line.driver_id).concat(v.driver_id),
    );

    return this.drivers().filter((d) => !taken.has(d.id));
  }

  protected addHelper(line?: LedgerHelperLine): void {
    this.helperLines.push(
      this.fb.nonNullable.group({
        driver_id: [line?.driver_id ?? ''],
        salary: [line ? line.salary_cents / 100 : 0, [Validators.required, Validators.min(0)]],
      }),
    );
  }

  protected removeHelper(index: number): void {
    this.helperLines.removeAt(index);
  }

  protected readonly net = computed(() => Number(this.values().trip_income) - this.totalExpenses());

  constructor() {
    this.financeApi.trucks().subscribe((trucks) => this.trucks.set(trucks));
    this.financeApi.routes().subscribe((routes) => this.routes.set(routes));
    this.customerApi.list().subscribe((res) => this.customers.set(res.data));
    this.driverApi.list().subscribe((res) => this.drivers.set(res.data));

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
        maintenance: e ? e.maintenance_cents / 100 : 0,
        allowance: e ? e.allowance_cents / 100 : 0,
        driver_id: e?.driver_id ?? '',
        customer_id: e?.customer_id ?? '',
        route: e?.route ?? '',
        remarks: e?.remarks ?? '',
      });
      // Rebuilt rather than reset: `reset` keeps the array's length, and a
      // day with three helpers opened after one with none needs three lines.
      this.helperLines.clear();
      (e?.helpers ?? []).forEach((line) => this.addHelper(line));
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
      helper_salary_cents: cents(this.helperTotal()),
      maintenance_cents: cents(v.maintenance),
      allowance_cents: cents(v.allowance),
      driver_id: v.driver_id || null,
      // Always sent, so removing a line really removes it.
      helpers: v.helpers.map((line) => ({
        driver_id: line.driver_id || null,
        salary_cents: cents(line.salary),
      })),
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

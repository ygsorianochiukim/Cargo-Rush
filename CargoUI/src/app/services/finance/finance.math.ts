import {
  DateRange,
  LedgerEntry,
  LedgerEntryPayload,
  PeriodTotals,
  Quarter,
  QuarterKey,
  TruckPnl,
} from '../../models/finance/finance.model';

export function totalExpenses(
  e: Pick<
    LedgerEntryPayload,
    | 'fuel_cents'
    | 'driver_salary_cents'
    | 'helper_salary_cents'
    | 'maintenance_cents'
    | 'allowance_cents'
  > & { owner_share_cents?: number | null },
): number {
  return (
    e.fuel_cents +
    e.driver_salary_cents +
    e.helper_salary_cents +
    e.maintenance_cents +
    e.allowance_cents +
    (e.owner_share_cents ?? 0)
  );
}

export function netIncome(e: LedgerEntryPayload & { owner_share_cents?: number | null }): number {
  return e.trip_income_cents - totalExpenses(e);
}

export function tenDayRange(from: string): DateRange {
  const start = new Date(`${from}T00:00:00Z`);
  const end = new Date(start);
  end.setUTCDate(end.getUTCDate() + 9);

  return { from, to: end.toISOString().slice(0, 10) };
}

export function currentTenDayStart(): string {
  const start = new Date();
  start.setUTCDate(start.getUTCDate() - 9);

  return start.toISOString().slice(0, 10);
}

export function currentQuarter(): QuarterKey {
  const quarter = Math.floor(new Date().getUTCMonth() / 3) + 1;

  return `q${quarter}` as QuarterKey;
}

export function quarters(year: number): Quarter[] {
  return [
    { key: 'q1', label: '1st Quarter', from: `${year}-01-01`, to: `${year}-03-31` },
    { key: 'q2', label: '2nd Quarter', from: `${year}-04-01`, to: `${year}-06-30` },
    { key: 'q3', label: '3rd Quarter', from: `${year}-07-01`, to: `${year}-09-30` },
    { key: 'q4', label: '4th Quarter', from: `${year}-10-01`, to: `${year}-12-31` },
  ];
}

export function inRange(date: string, range: DateRange): boolean {
  return date >= range.from && date <= range.to;
}

export function pnlFromEntries(entries: LedgerEntry[]): PeriodTotals {
  const sum = (pick: (e: LedgerEntry) => number) =>
    entries.reduce((total, e) => total + pick(e), 0);

  const income = sum((e) => e.trip_income_cents);
  const expenses = sum((e) => totalExpenses(e));

  return {
    trip_income_cents: income,
    fuel_cents: sum((e) => e.fuel_cents),
    fuel_log_cents: 0,
    driver_salary_cents: sum((e) => e.driver_salary_cents),
    helper_salary_cents: sum((e) => e.helper_salary_cents),
    maintenance_cents: sum((e) => e.maintenance_cents),
    allowance_cents: sum((e) => e.allowance_cents),
    owner_share_cents: sum((e) => e.owner_share_cents ?? 0),
    other_expenses_cents: 0,
    overhead_cents: 0,
    supplier_bills_cents: 0,
    payroll_cents: 0,
    trucker_payouts_cents: 0,
    trucker_commission_cents: 0,
    other_income_cents: 0,
    total_income_cents: income,
    vat_collected_cents: 0,
    held_for_partners_cents: 0,
    payables_already_costed_cents: 0,
    total_expenses_cents: expenses,
    net_income_cents: income - expenses,
    payables_cents: 0,
    receivables_cents: 0,
    actual_income_cents: income - expenses,
    margin: income === 0 ? null : (income - expenses) / income,
  };
}

export function hasActivity(r: TruckPnl): boolean {
  return r.trip_income_cents !== 0 || r.total_expenses_cents !== 0;
}

export function emptyTotals(): PeriodTotals {
  return {
    trip_income_cents: 0,
    fuel_cents: 0,
    fuel_log_cents: 0,
    driver_salary_cents: 0,
    helper_salary_cents: 0,
    maintenance_cents: 0,
    allowance_cents: 0,
    owner_share_cents: 0,
    other_expenses_cents: 0,
    overhead_cents: 0,
    supplier_bills_cents: 0,
    payroll_cents: 0,
    trucker_payouts_cents: 0,
    trucker_commission_cents: 0,
    other_income_cents: 0,
    total_income_cents: 0,
    vat_collected_cents: 0,
    held_for_partners_cents: 0,
    payables_already_costed_cents: 0,
    total_expenses_cents: 0,
    net_income_cents: 0,
    payables_cents: 0,
    receivables_cents: 0,
    actual_income_cents: 0,
    margin: null,
  };
}

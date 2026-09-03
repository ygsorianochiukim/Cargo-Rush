import {
  DateRange,
  LedgerEntry,
  LedgerEntryPayload,
  PeriodTotals,
  Quarter,
  QuarterKey,
  TruckPnl,
} from '../../models/finance/finance.model';

/**
 * The workbook's arithmetic, client-side.
 *
 * The API computes the same figures for a period roll-up, so why keep these?
 * Because the entry form has to show total expenses and net income updating
 * live as somebody types — DESIGN.md section 5.1 requires that so the person
 * recording can sanity-check the row before it is saved, and a round trip per
 * keystroke is not that.
 *
 * These are the only place in this client the formulas appear. A page never
 * adds up expenses itself; `finance.math.spec.ts` pins them to the figures the
 * workbook prints.
 */

/** fuel + driver + helper + maintenance + allowance. */
export function totalExpenses(
  e: Pick<
    LedgerEntryPayload,
    | 'fuel_cents'
    | 'driver_salary_cents'
    | 'helper_salary_cents'
    | 'maintenance_cents'
    | 'allowance_cents'
  >,
): number {
  return (
    e.fuel_cents +
    e.driver_salary_cents +
    e.helper_salary_cents +
    e.maintenance_cents +
    e.allowance_cents
  );
}

/** trip income - total expenses. Negative is a real, first-class loss. */
export function netIncome(e: LedgerEntryPayload): number {
  return e.trip_income_cents - totalExpenses(e);
}

/* -------------------------------------------------------------------------- */
/* Periods                                                                     */
/* -------------------------------------------------------------------------- */

/** The workbook's default view: a 10-day window from a chosen start. */
export function tenDayRange(from: string): DateRange {
  const start = new Date(`${from}T00:00:00Z`);
  const end = new Date(start);
  end.setUTCDate(end.getUTCDate() + 10);

  return { from, to: end.toISOString().slice(0, 10) };
}

/**
 * The start of a 10-day window ending today — what the API itself defaults to.
 *
 * Profitability used to open on the workbook's 5 April 2026, which was the
 * transcription's opening view rather than a date that means anything now. A
 * day filed today fell outside it, so the ledger looked as though it never
 * reached the page. The From control still reaches April.
 */
export function currentTenDayStart(): string {
  const start = new Date();
  start.setUTCDate(start.getUTCDate() - 10);

  return start.toISOString().slice(0, 10);
}

/** The quarter today falls in, for the slicer's opening position. */
export function currentQuarter(): QuarterKey {
  const quarter = Math.floor(new Date().getUTCMonth() / 3) + 1;

  return `q${quarter}` as QuarterKey;
}

/** Quarter boundaries exactly as the workbook's Table11 defines them. */
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

/* -------------------------------------------------------------------------- */
/* Aggregation — used for a local preview; the API is authoritative            */
/* -------------------------------------------------------------------------- */

export function pnlFromEntries(entries: LedgerEntry[]): PeriodTotals {
  const sum = (pick: (e: LedgerEntry) => number) =>
    entries.reduce((total, e) => total + pick(e), 0);

  const income = sum((e) => e.trip_income_cents);
  const expenses = sum((e) => totalExpenses(e));

  return {
    trip_income_cents: income,
    fuel_cents: sum((e) => e.fuel_cents),
    driver_salary_cents: sum((e) => e.driver_salary_cents),
    helper_salary_cents: sum((e) => e.helper_salary_cents),
    maintenance_cents: sum((e) => e.maintenance_cents),
    allowance_cents: sum((e) => e.allowance_cents),
    // Zero, and honestly so: a ledger row carries the five workbook columns and
    // knows nothing about the categorised expense lines filed against its day.
    // This function is a local preview over rows the client already holds, and
    // the API's roll-up — which does see the lines — is the authority.
    other_expenses_cents: 0,
    overhead_cents: 0,
    total_expenses_cents: expenses,
    net_income_cents: income - expenses,
    margin: income === 0 ? null : (income - expenses) / income,
  };
}

/**
 * Did this unit actually trade in the period?
 *
 * A scheduled row with a route but no money is not activity — several units
 * carry zero-value rows. Counting those as "ran" would dilute the average and
 * put empty bars on every chart.
 */
export function hasActivity(r: TruckPnl): boolean {
  return r.trip_income_cents !== 0 || r.total_expenses_cents !== 0;
}

/**
 * A zeroed roll-up.
 *
 * The period pages read `totals().net_income_cents` straight in the template,
 * so the first paint needs a real object rather than null — otherwise every
 * figure in the summary row would need its own null guard.
 */
export function emptyTotals(): PeriodTotals {
  return {
    trip_income_cents: 0,
    fuel_cents: 0,
    driver_salary_cents: 0,
    helper_salary_cents: 0,
    maintenance_cents: 0,
    allowance_cents: 0,
    other_expenses_cents: 0,
    overhead_cents: 0,
    total_expenses_cents: 0,
    net_income_cents: 0,
    margin: null,
  };
}

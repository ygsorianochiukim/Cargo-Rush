import { describe, expect, it } from 'vitest';

import { LedgerEntry, LedgerEntryPayload, TruckPnl } from '../../models/finance/finance.model';
import {
  emptyTotals,
  hasActivity,
  inRange,
  netIncome,
  pnlFromEntries,
  quarters,
  tenDayRange,
  totalExpenses,
} from './finance.math';

/**
 * The client's half of the workbook arithmetic — what the entry form shows
 * live while somebody types.
 *
 * The period roll-ups are the API's and are pinned there
 * (`CargoApi/tests/Feature/FinanceRollupTest.php`) against the figures the
 * workbook itself prints. What is tested here is that the two derivations this
 * client still performs agree with that same definition, so the preview a
 * person sanity-checks a row against cannot differ from what gets stored.
 */

/** Compare in pesos — the unit the workbook shows — to two decimal places. */
const pesos = (cents: number) => +(cents / 100).toFixed(2);

/** The MAR1390 row for 28 March 2026, in centavos. */
const row: LedgerEntryPayload = {
  truck_id: 'tr1',
  date: '2026-03-28',
  trip_income_cents: 3_154_068,
  fuel_cents: 0,
  driver_salary_cents: 150_000,
  helper_salary_cents: 200_000,
  maintenance_cents: 0,
  allowance_cents: 120_000,
  route: 'Maranding, Lala',
  remarks: null,
};

describe('the two workbook formulas', () => {
  it('adds the five expense columns and nothing else', () => {
    expect(pesos(totalExpenses(row))).toBe(4700);
  });

  it('takes expenses off income', () => {
    expect(pesos(netIncome(row))).toBe(26840.68);
  });

  it('reports a loss as a negative, not as a smaller positive', () => {
    const underwater = { ...row, trip_income_cents: 0, fuel_cents: 500_261 };

    expect(netIncome(underwater)).toBeLessThan(0);
    expect(pesos(netIncome(underwater))).toBe(-9702.61);
  });

  it('keeps centavo precision on a figure with decimals', () => {
    // The workbook's 31,540.68 has to land on exactly 3_154_068 centavos.
    expect(row.trip_income_cents).toBe(3_154_068);
    expect(pesos(row.trip_income_cents)).toBe(31540.68);
  });
});

describe('period helpers', () => {
  it('builds the workbook default 10-day window', () => {
    expect(tenDayRange('2026-04-05')).toEqual({ from: '2026-04-05', to: '2026-04-15' });
  });

  it('uses the workbook Table11 quarter boundaries', () => {
    const [q1, , , q4] = quarters(2026);

    expect(q1).toMatchObject({ key: 'q1', from: '2026-01-01', to: '2026-03-31' });
    expect(q4).toMatchObject({ key: 'q4', from: '2026-10-01', to: '2026-12-31' });
  });

  it('treats a range as inclusive at both ends', () => {
    const range = { from: '2026-04-05', to: '2026-04-15' };

    expect(inRange('2026-04-05', range)).toBe(true);
    expect(inRange('2026-04-15', range)).toBe(true);
    expect(inRange('2026-04-16', range)).toBe(false);
  });
});

describe('local aggregation', () => {
  const entries: LedgerEntry[] = [
    row,
    { ...row, date: '2026-03-29', trip_income_cents: 0 },
  ].map((e) => ({
    ...e,
    id: e.date,
    truck_label: 'Truck 1',
    truck_plate: 'MAR1390',
    route: e.route ?? null,
    remarks: e.remarks ?? null,
    total_expenses_cents: totalExpenses(e),
    net_income_cents: netIncome(e),
    currency: 'PHP',
    // Rows entered by hand carry neither a trip nor a single customer, which
    // is the ordinary case for the transcribed workbook these figures come
    // from.
    trip_id: null,
    trip_reference: null,
    customer_id: null,
    customer: null,
  }));

  it('sums a set of rows the same way the API does', () => {
    const totals = pnlFromEntries(entries);

    expect(pesos(totals.trip_income_cents)).toBe(31540.68);
    expect(pesos(totals.total_expenses_cents)).toBe(9400);
    expect(pesos(totals.net_income_cents)).toBe(22140.68);
  });

  it('has no margin to report when nothing came in', () => {
    expect(pnlFromEntries([]).margin).toBeNull();
    expect(emptyTotals().margin).toBeNull();
  });
});

describe('hasActivity', () => {
  const pnl = (income: number, expenses: number): TruckPnl => ({
    truck: { id: 'tr6', label: 'Truck 6', plate: 'CDF5211' },
    trip_income_cents: income,
    fuel_cents: 0,
    driver_salary_cents: 0,
    helper_salary_cents: 0,
    maintenance_cents: 0,
    allowance_cents: 0,
    other_expenses_cents: 0,
    total_expenses_cents: expenses,
    net_income_cents: income - expenses,
    net_share: 0,
    entry_count: 9,
  });

  it('does not count a scheduled unit that moved no money', () => {
    // Truck 6 carries nine dated rows with a route and nothing else. Counting
    // those as activity would dilute the average and draw empty bars.
    expect(hasActivity(pnl(0, 0))).toBe(false);
  });

  it('counts a unit that only spent', () => {
    expect(hasActivity(pnl(0, 380_000))).toBe(true);
  });
});

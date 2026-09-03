import { Timestamped } from '../shared/envelope.model';

/**
 * The money side — the shapes behind "v3 Cargorush Master Dashboard 2026.xlsx".
 *
 * The workbook keeps one sheet per truck with a row per date and derives
 * everything else from those rows:
 *
 *   total_expenses = fuel + driver_salary + helper_salary + maintenance + allowance
 *   net_income     = trip_income - total_expenses
 *
 * Money is integer centavos (DESIGN.md section 7.1) — the workbook's
 * PHP 30,721.00 is 3_072_100. Formatting to pesos happens in the view only.
 */

export interface Truck {
  id: string;
  /** "Truck 1" — the workbook's TRUCK NO. */
  label: string;
  /** "MAR1390" — the UNIT NO. Null for a unit with no plate yet. */
  plate: string | null;
  vehicle_id: string | null;
  position: number;
}

/** One day of trip income and expenses for one truck. */
export interface LedgerEntry extends Timestamped {
  id: string;
  truck_id: string;
  truck_label: string | null;
  truck_plate: string | null;
  /** ISO date, no time — the ledger is daily. */
  date: string;
  trip_income_cents: number;
  fuel_cents: number;
  driver_salary_cents: number;
  helper_salary_cents: number;
  maintenance_cents: number;
  allowance_cents: number;
  /** Both derived by the API from the five expense columns above. */
  total_expenses_cents: number;
  net_income_cents: number;
  currency: string;
  route: string | null;
  remarks: string | null;
  /**
   * The trip whose delivery opened this row, and its reference — the only
   * trip identity a human reads. Both null for a row the office entered by
   * hand. A day can cover several runs; this names the one that opened it.
   */
  trip_id: string | null;
  trip_reference: string | null;
  /**
   * Whose work the day was, when it was one customer's — this is what puts
   * the money on their history. Null is ordinary: a row covers a truck for a
   * day, which can be several customers' work or the company's own freight.
   */
  customer_id: string | null;
  customer: string | null;
}

/** What an entry form sends. Neither derived figure is accepted. */
export interface LedgerEntryPayload {
  truck_id: string;
  date: string;
  trip_income_cents: number;
  fuel_cents: number;
  driver_salary_cents: number;
  helper_salary_cents: number;
  maintenance_cents: number;
  allowance_cents: number;
  customer_id?: string | null;
  route?: string | null;
  remarks?: string | null;
}

/** A truck's rolled-up figures for a period. */
export interface TruckPnl {
  truck: Pick<Truck, 'id' | 'label' | 'plate'>;
  trip_income_cents: number;
  fuel_cents: number;
  driver_salary_cents: number;
  helper_salary_cents: number;
  maintenance_cents: number;
  allowance_cents: number;
  /** Categorised expense lines, which sit beside the five columns above. */
  other_expenses_cents: number;
  total_expenses_cents: number;
  net_income_cents: number;
  /** Share of the period's total net income. Negative for a loss-maker. */
  net_share: number;
  entry_count: number;
}

export interface PeriodTotals {
  trip_income_cents: number;
  fuel_cents: number;
  driver_salary_cents: number;
  helper_salary_cents: number;
  maintenance_cents: number;
  allowance_cents: number;
  other_expenses_cents: number;
  /**
   * Counted spend belonging to the period but to no truck — office rent, an
   * annual permit. It is inside `total_expenses_cents` and inside no truck
   * row, which is why the truck rows do not add up to the total on their own.
   */
  overhead_cents: number;
  total_expenses_cents: number;
  net_income_cents: number;
  /** net / income, or null when there was no income to divide by. */
  margin: number | null;
}

export interface DateRange {
  from: string;
  to: string;
}

export type QuarterKey = 'q1' | 'q2' | 'q3' | 'q4';

export interface Quarter {
  key: QuarterKey;
  label: string;
  from: string;
  to: string;
}

/** What `GET /api/v1/finance/profitability` and `/summary` both return. */
export interface PeriodRollup {
  range: DateRange;
  trucks: TruckPnl[];
  totals: PeriodTotals;
  average_profit_per_truck: { cents: number; trucks: number };
  /** Null when nobody is in profit, which really does happen. */
  best_performer: TruckPnl | null;
  currency: string;
}

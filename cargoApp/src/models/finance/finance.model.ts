/**
 * The day's trip income and expenses, recorded from the cab.
 *
 * This is the same row the back office reads in Daily Trip Monitoring — the
 * workbook's "record the daily trip income and expenses in each truck" step,
 * done where the run actually happened. Money is integer centavos.
 */
export interface Truck {
  id: string;
  label: string;
  /** Null for a unit with no plate yet; renders as "Unassigned". */
  plate: string | null;
  vehicle_id: string | null;
  position: number;
}

export interface LedgerEntry {
  id: string;
  truck_id: string;
  truck_label: string | null;
  truck_plate: string | null;
  date: string;
  trip_income_cents: number;
  fuel_cents: number;
  driver_salary_cents: number;
  helper_salary_cents: number;
  maintenance_cents: number;
  allowance_cents: number;
  /** Both derived by the API from the five expense columns. */
  total_expenses_cents: number;
  net_income_cents: number;
  currency: string;
  route: string | null;
  remarks: string | null;
}

/** What the daily log sheet sends. Neither derived figure is accepted. */
export interface LedgerEntryPayload {
  truck_id: string;
  date: string;
  trip_income_cents: number;
  fuel_cents: number;
  driver_salary_cents: number;
  helper_salary_cents: number;
  maintenance_cents: number;
  allowance_cents: number;
  route?: string | null;
  remarks?: string | null;
}

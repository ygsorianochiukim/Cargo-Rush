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
  /** The sum of `helpers` below — what the day's helpers cost between them. */
  helper_salary_cents: number;
  maintenance_cents: number;
  allowance_cents: number;
  /**
   * Each helper on the day and what they were paid. `driver_id` is null for
   * pay filed without saying whose — which is what this app files, see
   * `LedgerEntryPayload`.
   */
  helpers: { driver_id: string | null; name: string | null; salary_cents: number }[];
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
  /**
   * One figure for every helper on the run.
   *
   * The API keeps a line per helper now, each with their own pay, but the cab
   * only knows its helpers by name — `trips/current` carries no ids — so the
   * lines cannot be attributed from here. One figure is what the API accepts
   * from a sheet like this: it files it as the day's helper pay, and the
   * office splits it by person on Daily Trip Monitoring.
   */
  helper_salary_cents: number;
  maintenance_cents: number;
  allowance_cents: number;
  route?: string | null;
  remarks?: string | null;
}

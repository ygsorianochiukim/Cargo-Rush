import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/** Fuel Expense Monitoring — one fill-up. Money is integer centavos. */
export interface FuelRecord extends Timestamped {
  id: string;
  vehicle_id: string | null;
  vehicle_plate: string | null;
  driver_id: string | null;
  driver_name: string | null;
  litres: number;
  amount_cents: number;
  currency: string;
  odometer_km: number;
  receipt_no: string;
  logged_at: string;
  status: StatusValue;
}

export interface FuelRecordPayload {
  vehicle_id: string;
  driver_id?: string | null;
  litres: number;
  amount_cents: number;
  currency?: string;
  odometer_km: number;
  receipt_no: string;
  logged_at: string;
  status?: StatusValue;
}

/**
 * The budget tile.
 *
 * Only the allowance is stored; spend and projection are summed by the API
 * from the records themselves, so the tile cannot disagree with its table.
 */
export interface FuelBudget {
  date: string;
  daily_budget_cents: number;
  spent_today_cents: number;
  currency: string;
  /** Month-end spend, straight-lined from the rate so far. */
  projection_cents: number;
  open_requests: number;
}

import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

export interface FuelRecord extends Timestamped {
  id: string;
  vehicle_id: string | null;
  vehicle_plate: string | null;
  driver_id: string | null;
  driver_name: string | null;
  payment_source_id: string | null;
  payment_source_label: string | null;
  payment_source_kind: string | null;
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
  payment_source_id?: string | null;
  litres: number;
  amount_cents: number;
  currency?: string;
  odometer_km: number;
  receipt_no: string;
  logged_at: string;
  status?: StatusValue;
}

export interface TripFuel {
  id: string;
  trip_id: string;
  reference: string | null;
  route: string | null;
  vehicle_plate: string | null;
  driver_name: string | null;
  litres: number | null;
  amount_cents: number;
  currency: string;
  delivered_at: string | null;
  posted_on: string | null;
}

export interface FuelBudget {
  date: string;
  daily_budget_cents: number;
  spent_today_cents: number;
  trip_fuel_today_cents: number;
  trip_fuel_month_cents: number;
  pending_today_cents: number;
  currency: string;
  projection_cents: number;
  open_requests: number;
}

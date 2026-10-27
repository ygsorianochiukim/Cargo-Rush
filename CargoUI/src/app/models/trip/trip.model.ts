import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

export interface TripCheckItem {
  key: string;
  label: string;
  hint: string;
  passed: boolean | null;
  critical: boolean;
}

export interface TripInspection {
  required: boolean;
  passed: boolean;
  inspected_at: string | null;
  checked_by: string | null;
  notes: string | null;
  items: TripCheckItem[];
  failures: string[];
  total_items: number;
  passed_items: number;
}

export interface Trip extends Timestamped {
  id: string;
  reference: string;
  origin: string;
  destination: string;
  origin_lat: number | null;
  origin_lng: number | null;
  destination_lat: number | null;
  destination_lng: number | null;
  mapped: boolean;
  cargo: string;
  weight_kg: number;
  truck_category_id?: string | null;
  truck_size_id?: string | null;
  pieces: number;
  handling: string | null;

  price_cents: number | null;
  currency: string;
  driver_allowance_cents?: number | null;
  helper_allowance_cents?: number | null;
  toll_cents?: number | null;
  needs_zone: boolean;
  pricing_note: string | null;
  pricing_source: 'zone' | 'manual' | 'unzoned' | null;
  manually_priced: boolean;
  pricing_zone_id: string | null;
  zone_pinned: boolean;
  pricing_zone?: string | null;

  customer_id: string | null;
  customer: string | null;
  driver_id: string | null;
  driver_name: string | null;
  helper_ids: string[];
  helpers: { id: string; name: string }[];
  vehicle_id: string | null;
  vehicle_plate: string | null;
  vehicle_driver_id: string | null;
  vehicle_driver_name: string | null;
  driver_swapped: boolean;

  hauled_by: 'company' | 'trucker';

  trucker_id: string | null;
  trucker_name: string | null;
  trucker_business_name?: string | null;
  trucker_phone: string | null;
  trucker_vehicle_id: string | null;
  trucker_plate: string | null;
  trucker_driver_id?: string | null;
  trucker_driver_name?: string | null;

  booking_source: 'cargo_rush' | 'direct';
  booking_source_label: string;

  commission_bp: number | null;
  commission_cents: number | null;

  trip_income_cents?: number;
  costs_cents?: number;
  net_cents?: number;

  inspection: TripInspection;

  status: StatusValue;
  pickup_place: string | null;
  dropoff_place: string | null;
  scheduled_at: string;
  eta: string | null;
  distance_total_m: number;
  distance_source: 'road' | 'estimate' | 'manual' | null;
  billed_at: string | null;
  invoice: TripInvoice | null;
}

export interface TripInvoice {
  id: string;
  number: string;
  status: StatusValue;
  amount_cents: number;
  due_cents: number;
  paid_cents: number;
  balance_cents: number;
  currency: string;
  due_at: string | null;
}

export type TripPaymentStatus = 'unpaid' | 'paid' | 'partial';

export interface TripPayload {
  customer_id?: string | null;
  new_customer?: { name: string; contact: string; address?: string | null };
  origin: string;
  origin_lat?: number | null;
  origin_lng?: number | null;
  destination: string;
  destination_lat?: number | null;
  destination_lng?: number | null;
  cargo: string;
  weight_kg: number;
  pieces?: number;
  handling?: string | null;
  driver_id?: string | null;
  helper_ids?: string[];
  vehicle_id?: string | null;
  status?: StatusValue;
  pickup_place?: string | null;
  dropoff_place?: string | null;
  scheduled_at: string;
  eta?: string | null;
  delivered_at?: string | null;
  receiver_name?: string | null;
  price_cents?: number | null;
  pricing_zone_id?: string | null;
  driver_allowance_cents?: number | null;
  helper_allowance_cents?: number | null;
  toll_cents?: number | null;
  distance_total_m?: number;
  payment_status?: TripPaymentStatus;
  paid_cents?: number | null;
  paid_on?: string | null;
  payment_method?: string | null;
  payment_reference?: string | null;
}

export interface TripConfirmPayload {
  driver_id: string;
  helper_ids?: string[];
  vehicle_id: string;
  scheduled_at: string;
  eta?: string | null;
  weight_kg?: number;
  price_cents?: number | null;
  pricing_zone_id?: string | null;
}

export interface TripHistoryChange {
  field: string;
  from: unknown;
  to: unknown;
}

export interface TripHistoryEntry {
  id: string;
  subject: string;
  action: 'created' | 'updated' | 'deleted' | 'restored';
  at: string;
  user: string | null;
  ip_address: string | null;
  url: string | null;
  changes: TripHistoryChange[];
}

export interface TripCostLine {
  key: string;
  kind: string;
  description: string | null;
  amount_cents: number;
  source: 'trip' | 'delivery_log' | 'expense';
  record_id: string | null;
  paid_with: string | null;
}

export interface TripCosts {
  trip_id: string;
  hauled_by: 'company' | 'trucker';
  income_label: string;
  income_cents: number;
  lines: TripCostLine[];
  costs_cents: number;
  net_cents: number;
  final: boolean;
  currency: string;
}

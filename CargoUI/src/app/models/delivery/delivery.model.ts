import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

export interface DeliveryLog extends Timestamped {
  id: string;
  trip_id: string;
  reference: string | null;
  customer: string | null;
  destination: string | null;
  driver_name: string | null;
  helper_names: string[];
  delivered_at: string | null;
  pod_ref: string | null;
  receiver_name: string | null;
  fuel_litres: number | null;
  fuel_cost_cents: number | null;
  payment_source_id: string | null;
  payment_source_label: string | null;
  currency: string | null;
  status: StatusValue;
}

export interface DeliveryFuelPayload {
  fuel_litres: number | null;
  fuel_cost_cents: number | null;
  payment_source_id?: string | null;
}

export interface DeliveryReport {
  pending: number;
  active: number;
  complete: number;
  cancelled: number;
}

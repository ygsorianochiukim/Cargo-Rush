import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/** Delivery Logs — the closed-out record, including proof of delivery. */
export interface DeliveryLog extends Timestamped {
  id: string;
  trip_id: string;
  reference: string | null;
  customer: string | null;
  destination: string | null;
  driver_name: string | null;
  helper_name: string | null;
  delivered_at: string | null;
  pod_ref: string | null;
  receiver_name: string | null;
  status: StatusValue;
}

/** The pending / active / complete report from DESIGN.md section 5.1. */
export interface DeliveryReport {
  pending: number;
  active: number;
  complete: number;
  cancelled: number;
}

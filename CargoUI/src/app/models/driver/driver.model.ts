import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/**
 * Drivers Management — DESIGN.md section 5.1.
 *
 * Helpers live in this same shape: a helper is a driver record without the
 * keys, so there is no second type for them.
 */
export interface Driver extends Timestamped {
  id: string;
  name: string;
  licence_no: string;
  licence_expiry: string;
  /** Derived by the API — inside the expiry warning window. */
  licence_expiring: boolean;
  /** LTMS violations on record. */
  violations: number;
  status: StatusValue;
  trips_completed: number;
  on_time_rate: number;
  user_id: number | null;
}

export interface DriverPayload {
  name: string;
  licence_no: string;
  licence_expiry: string;
  violations?: number;
  status?: StatusValue;
  user_id?: number | null;
}

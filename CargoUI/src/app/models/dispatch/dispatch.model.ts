import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/** Dispatch Monitoring — when and where a unit left, and when it arrived. */
export interface DispatchRecord extends Timestamped {
  id: string;
  trip_id: string;
  reference: string | null;
  vehicle_id: string | null;
  vehicle_plate: string | null;
  dispatched_at: string;
  location: string;
  arrived_at: string | null;
  status: StatusValue;
}

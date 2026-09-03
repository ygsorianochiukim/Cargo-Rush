import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/** Incident Management — records with a time and a place. */
export interface Incident extends Timestamped {
  id: string;
  reference: string;
  kind: string;
  place: string;
  occurred_at: string;
  driver_id: string | null;
  driver_name: string | null;
  vehicle_id: string | null;
  vehicle_plate: string | null;
  trip_id: string | null;
  trip_reference: string | null;
  notes: string | null;
  status: StatusValue;
}

export interface IncidentPayload {
  kind: string;
  place: string;
  occurred_at: string;
  driver_id?: string | null;
  vehicle_id?: string | null;
  trip_id?: string | null;
  notes?: string | null;
  status?: StatusValue;
}

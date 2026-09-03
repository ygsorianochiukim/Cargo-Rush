import { StatusValue } from '@/constants/status';

/** One line of the pre-trip checklist. */
export interface InspectionItem {
  key: string;
  label: string;
  hint: string;
}

/** A submitted check. `good_to_go` is the API's call, not this app's. */
export interface Inspection {
  id: string;
  trip_id: string | null;
  trip_reference: string | null;
  vehicle_id: string | null;
  vehicle_plate: string | null;
  driver_id: string | null;
  driver_name: string | null;
  results: Record<string, boolean>;
  good_to_go: boolean;
  /** Which checklist keys came back failed. */
  failures: string[];
  notes: string | null;
  inspected_at: string;
}

/**
 * What gets posted. No `good_to_go`: a driver in a hurry must not be able to
 * send a pass over a failed brake check, so the API decides.
 */
export interface InspectionPayload {
  trip_id?: string | null;
  vehicle_id: string;
  driver_id?: string | null;
  results: Record<string, boolean>;
  notes?: string | null;
  inspected_at?: string;
}

/** Unit Maintenance and Inspection — an assigned job. */
export interface MaintenanceJob {
  id: string;
  vehicle_id: string;
  vehicle_plate: string | null;
  kind: string;
  due_at: string;
  odometer_km: number;
  next_service_km: number;
  status: StatusValue;
}

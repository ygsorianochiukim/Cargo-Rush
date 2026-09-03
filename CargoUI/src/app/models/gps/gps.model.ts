import { StatusValue } from '../shared/status.model';

/**
 * GPS Dashboard — one live unit, trip and latest ping flattened into the row
 * the map and the table both read.
 */
export interface GpsUnit {
  id: string;
  reference: string;
  vehicle_plate: string | null;
  driver_name: string | null;
  location: string;
  speed_kph: number;
  heading: string;
  progress_pct: number;
  eta: string | null;
  status: StatusValue;
  /** ISO. The client renders "40 sec ago"; the API never formats. */
  updated_at: string;
}

/** GPS Tracking (mobile) — A to B, progress, and average speed. */
export interface TrackingState {
  reference: string;
  point_a: string;
  point_b: string;
  current_location: string;
  progress_pct: number;
  speed_kph: number;
  /** Distance over time elapsed, not the mean of reported speeds. */
  average_speed_kph: number;
  distance_done_m: number;
  distance_total_m: number;
  eta: string | null;
}

/** What the handset posts while it is moving. */
export interface GpsPingPayload {
  trip_id: string;
  location: string;
  speed_kph: number;
  heading?: string;
  progress_pct: number;
  distance_done_m?: number;
  recorded_at: string;
}

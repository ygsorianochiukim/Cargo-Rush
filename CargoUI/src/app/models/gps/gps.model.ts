import { StatusValue } from '../shared/status.model';

export interface LatLng {
  lat: number;
  lng: number;
}

export interface GpsUnit {
  id: string;
  reference: string;
  vehicle_plate: string | null;
  driver_name: string | null;
  location: string;
  lat: number | null;
  lng: number | null;
  has_fix: boolean;
  speed_kph: number;
  heading: string;
  progress_pct: number;
  eta: string | null;
  status: StatusValue;
  updated_at: string;
}

export interface TrackingState {
  reference: string;
  point_a: string;
  point_b: string;
  current_location: string;
  current: LatLng | null;
  path: (LatLng & { speed_kph: number; recorded_at: string })[];
  endpoints: { origin: LatLng | null; destination: LatLng | null };
  progress_pct: number;
  speed_kph: number;
  average_speed_kph: number;
  distance_done_m: number;
  distance_total_m: number;
  eta: string | null;
}

export interface GpsPingPayload {
  trip_id: string;
  location: string;
  lat?: number;
  lng?: number;
  speed_kph: number;
  heading?: string;
  progress_pct: number;
  distance_done_m?: number;
  recorded_at: string;
}

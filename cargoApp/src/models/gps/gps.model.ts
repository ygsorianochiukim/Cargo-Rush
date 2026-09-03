/** GPS Tracking — A to B, how far along, and how fast on average. */
export interface TrackingState {
  reference: string;
  point_a: string;
  point_b: string;
  current_location: string;
  progress_pct: number;
  speed_kph: number;
  /** Distance over time elapsed, not the mean of the reported speeds. */
  average_speed_kph: number;
  distance_done_m: number;
  distance_total_m: number;
  eta: string | null;
}

/**
 * What this app posts while it is moving.
 *
 * The handset is the position source that feeds the web GPS Dashboard
 * (DESIGN.md section 5.4) — this is the write that makes that true.
 */
export interface GpsPingPayload {
  trip_id: string;
  location: string;
  speed_kph: number;
  heading?: string;
  progress_pct: number;
  distance_done_m?: number;
  /** Stamped by the handset: it may have been offline when the reading was taken. */
  recorded_at: string;
}

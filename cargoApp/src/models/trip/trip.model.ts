import { StatusValue } from '@/constants/status';

/**
 * A trip, exactly as the back office sees it — one contract, no mobile-only
 * shape (DESIGN.md section 5.3).
 *
 * The Dashboard reads the current, pending and upcoming lists from this;
 * Cargo Details reads the cargo half of the same record.
 */
export interface Trip {
  id: string;
  reference: string;
  origin: string;
  destination: string;
  cargo: string;
  weight_kg: number;
  pieces: number;
  handling: string | null;

  /**
   * What the haul is charged, in centavos.
   *
   * Quoted from the tariff when the trip is booked, so a customer is told the
   * price at the moment they ask rather than when the invoice turns up.
   */
  price_cents: number;
  currency: string;

  customer_id: string | null;
  customer: string | null;
  driver_id: string | null;
  driver_name: string | null;
  helper_id: string | null;
  helper_name: string | null;
  vehicle_id: string | null;
  vehicle_plate: string | null;

  status: StatusValue;
  pickup_place: string | null;
  dropoff_place: string | null;
  scheduled_at: string;
  eta: string | null;
  distance_total_m: number;
  /**
   * When delivering put this run on the books — the day's income and the
   * customer's invoice. Null on anything not yet delivered, which is the right
   * answer rather than a missing one.
   */
  billed_at: string | null;
}

/**
 * Cargo Details — DESIGN.md section 5.2.
 *
 * The trip joined to its dispatch and delivery records, flattened by the API
 * (`GET /api/v1/trips/cargo`) into the one object that screen shows.
 */
export interface CargoDetail {
  id: string;
  reference: string;
  description: string;
  weight_kg: number;
  pieces: number;
  handling: string | null;
  customer: string | null;

  pickup_place: string;
  pickup_at: string;
  dropoff_place: string;
  dropoff_at: string | null;

  dispatched_at: string | null;
  arrived_at: string | null;
  eta: string | null;
  status: StatusValue;
}

/**
 * The run the driver is on right now — `GET /api/v1/trips/current`.
 *
 * A trip plus its latest reported position, which is what the Dashboard's
 * progress bar and "current location" line are made of.
 */
export interface CurrentTrip {
  id: string;
  reference: string;
  origin: string;
  destination: string;
  cargo: string;
  weight_kg: number;
  customer: string | null;
  /** Matches the ledger sheet for this unit. Survives a plate correction. */
  vehicle_id: string | null;
  vehicle_plate: string | null;
  helper_name: string | null;
  status: StatusValue;
  scheduled_at: string;
  eta: string | null;

  progress_pct: number;
  current_location: string;
  /** When that position was reported. Null before the first ping. */
  reported_at: string | null;

  /**
   * Both ends of the run.
   *
   * Carried so the handset can work out progress locally — at a reading a
   * minute for ten hours, asking the server each time is not an option.
   * Null when nobody pinned the trip on a map.
   */
  origin_lat: number | null;
  origin_lng: number | null;
  destination_lat: number | null;
  destination_lng: number | null;
  distance_total_m: number;
  mapped: boolean;
}

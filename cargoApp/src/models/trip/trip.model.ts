import { StatusValue } from '@/constants/status';

/**
 * One line of the pre-trip check as it was answered.
 *
 * The label comes from the API rather than this app, so a check read back
 * months later says what it said on the day — and `critical` is why a failed
 * coolant is advisory while a failed brake holds the unit.
 */
export interface TripCheckItem {
  key: string;
  label: string;
  hint: string;
  /** Null for an item that was not on the checklist when this was answered. */
  passed: boolean | null;
  critical: boolean;
}

/**
 * Where a run's pre-trip check stands.
 *
 * On every trip the API returns, because all three clients need it: this app
 * decides whether tapping Start opens the checklist or leaves on the run, the
 * office board shows that a unit was looked over before it rolled, and the
 * customer can see that somebody checked the truck their load is on.
 *
 * A run cannot start without a pass, so anything in transit or delivered has
 * one — it is the record of the truck being checked at the gate.
 */
export interface TripInspection {
  /** True while the run is confirmed and has not left: the check is still due. */
  required: boolean;
  passed: boolean;
  inspected_at: string | null;
  checked_by: string | null;
  notes: string | null;
  /**
   * The itemised result. Empty until there is a check to show — and, on the
   * customer's own endpoints, until it has passed: which brake failed is
   * between a fleet and its mechanic.
   */
  items: TripCheckItem[];
  failures: string[];
  total_items: number;
  passed_items: number;
}

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

  /**
   * The haulier carrying it.
   *
   * Present on the customer's own endpoints and nowhere else: a dispatcher is
   * looking at one company's board and already knows whose it is, while a
   * shipper may be using three firms at once and a delivery that does not say
   * which is a delivery they cannot ring anybody about.
   */
  carrier_id?: string | null;
  carrier?: string | null;

  driver_id: string | null;
  driver_name: string | null;
  /**
   * Everyone riding along, in the order the desk named them — any number,
   * including none. The ids for a form to send back, the pairs to print.
   */
  helper_ids: string[];
  helpers: { id: string; name: string }[];
  vehicle_id: string | null;
  vehicle_plate: string | null;

  /**
   * The pre-trip check on this run.
   *
   * What the Start button reads: a run whose check has not passed opens the
   * checklist instead of leaving, because the API refuses the departure
   * anyway and "run the check" is an instruction where a 422 is a complaint.
   */
  inspection: TripInspection;

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
  /**
   * Whether the hand-off photograph arrived. Only on lists that load the
   * delivery log — a partner's Finished runs — and absent everywhere else.
   */
  has_pod_photo?: boolean;
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
  /** Who is riding along today, by name. Empty when the driver is alone. */
  helper_names: string[];
  status: StatusValue;
  scheduled_at: string;
  eta: string | null;

  /**
   * The check that cleared this unit to leave.
   *
   * A run in transit has one by definition — nothing rolls without it — so
   * here it is the record of the truck being looked over, with the time and
   * who did it. Worth showing back: a driver stopped at a checkpoint has the
   * answer on the phone in their hand.
   */
  inspection: TripInspection;

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

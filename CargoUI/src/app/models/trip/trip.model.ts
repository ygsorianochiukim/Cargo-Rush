import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/**
 * Trip Management — DESIGN.md section 5.1.
 *
 * The related records arrive as names as well as ids: a table column prints
 * `driver_name`, and the id is there for the edit form.
 */
/**
 * One line of the pre-trip check as the driver answered it.
 *
 * The label comes from the API rather than this app, so a check read back
 * months later says what it said on the day — and `critical` is why a failed
 * coolant is advisory while a failed brake holds the unit in the yard.
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
 * Captured on the handset and read here: the office never records one
 * (DESIGN.md section 5.4). A run cannot start without a pass, so anything in
 * transit or delivered carries the record of the truck being looked over at the
 * gate — which is what makes this worth showing on the board rather than only
 * in the inspections log.
 */
export interface TripInspection {
  /** True while the run is confirmed and has not left: the check is still due. */
  required: boolean;
  passed: boolean;
  inspected_at: string | null;
  checked_by: string | null;
  notes: string | null;
  items: TripCheckItem[];
  failures: string[];
  total_items: number;
  passed_items: number;
}

export interface Trip extends Timestamped {
  id: string;
  reference: string;
  origin: string;
  destination: string;
  /**
   * Where the two ends are, when somebody has pinned them.
   *
   * Null is a real answer: a trip booked over the phone has a place name
   * long before it has a point on a map.
   */
  origin_lat: number | null;
  origin_lng: number | null;
  destination_lat: number | null;
  destination_lng: number | null;
  /** Derived by the API — both ends pinned. */
  mapped: boolean;
  cargo: string;
  weight_kg: number;
  pieces: number;
  handling: string | null;

  /**
   * What the haul is charged, in centavos.
   *
   * Quoted from the tariff when the trip is booked, so it is on the record
   * before anybody delivers anything — which is what lets a customer be told
   * a price at the moment they ask for the pickup.
   */
  price_cents: number;
  currency: string;

  customer_id: string | null;
  customer: string | null;
  driver_id: string | null;
  driver_name: string | null;
  /**
   * Everyone riding along, in the order the desk named them — any number,
   * including none. The ids for the form to send back, the pairs to print.
   */
  helper_ids: string[];
  helpers: { id: string; name: string }[];
  vehicle_id: string | null;
  vehicle_plate: string | null;

  /**
   * Who is actually moving this load.
   *
   * `company` is the fleet's own crew in the fleet's own truck; `trucker` is a
   * partner in theirs. Derived by the API rather than inferred here from a null
   * driver, and that distinction matters on the board: a partner's run has no
   * driver, no helper and no vehicle — because none of those are the fleet's —
   * so from the crew columns alone it is indistinguishable from a run nobody
   * has been assigned to yet.
   */
  hauled_by: 'company' | 'trucker';

  trucker_id: string | null;
  trucker_name: string | null;
  trucker_phone: string | null;
  trucker_vehicle_id: string | null;
  trucker_plate: string | null;

  /**
   * How the work reached whoever is hauling it — the audit column.
   *
   * `cargo_rush`: the desk brokered it, so the fleet quotes, invoices and
   * collects. `direct`: a partner took it off the open board and bills the
   * customer themselves. The same percentage applies either way; what differs
   * is the direction it moves in the partner's wallet.
   */
  booking_source: 'cargo_rush' | 'direct';
  booking_source_label: string;

  /** What was actually taken, frozen at delivery. Null until then. */
  commission_bp: number | null;
  commission_cents: number | null;

  /**
   * The pre-trip check on this run.
   *
   * Read-only here, like everything the handset captures. A confirmed run with
   * `passed: false` is a unit that has not been cleared to leave — which is the
   * one thing on the board that explains a driver sitting in a yard.
   */
  inspection: TripInspection;

  status: StatusValue;
  pickup_place: string | null;
  dropoff_place: string | null;
  scheduled_at: string;
  eta: string | null;
  distance_total_m: number;
  /**
   * Where the distance came from, which is what picked the zone: `road`
   * (measured on the road network), `estimate` (the straight line times a
   * detour factor, because routing could not answer), `manual` (typed by the
   * desk). Null on trips from before this was recorded, or with no distance.
   */
  distance_source: 'road' | 'estimate' | 'manual' | null;
  /**
   * When delivering put this run on the books — the day's income and the
   * customer's invoice. Null on anything not yet delivered, which is the
   * right answer rather than a missing one.
   */
  billed_at: string | null;
}

/**
 * What a create or edit sends.
 *
 * No `reference`: the API assigns it, so a client cannot choose one.
 */
export interface TripPayload {
  customer_id?: string | null;
  origin: string;
  origin_lat?: number | null;
  origin_lng?: number | null;
  destination: string;
  destination_lat?: number | null;
  destination_lng?: number | null;
  cargo: string;
  weight_kg: number;
  pieces?: number;
  handling?: string | null;
  driver_id?: string | null;
  /** Replaces the crew when sent; an empty list clears it. */
  helper_ids?: string[];
  vehicle_id?: string | null;
  status?: StatusValue;
  pickup_place?: string | null;
  dropoff_place?: string | null;
  scheduled_at: string;
  eta?: string | null;
  /**
   * Almost never sent: the API quotes the haul from the tariff. It is here for
   * the one case deriving cannot cover — a rate somebody negotiated.
   */
  price_cents?: number;
}

/**
 * What the desk sends to confirm a customer's request.
 *
 * The four fields only the office knows. `assigned` is not among them: it is
 * what follows from naming a driver, a unit and a time, not a value set beside
 * them — so the API decides it and this payload cannot.
 */
export interface TripConfirmPayload {
  driver_id: string;
  helper_ids?: string[];
  vehicle_id: string;
  scheduled_at: string;
  eta?: string | null;
  /** Correcting what the customer estimated re-quotes the haul. */
  weight_kg?: number;
  /** A rate the desk negotiated. Sending it stops the tariff overruling them. */
  price_cents?: number;
}

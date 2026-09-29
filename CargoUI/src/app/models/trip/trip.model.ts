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
  /** The kind of truck the load asks for. Null when any will do. */
  truck_category_id?: string | null;
  pieces: number;
  handling: string | null;

  /**
   * What the haul is charged, in centavos — or null when it is not priced yet.
   *
   * Quoted off the zone card when the trip is booked, so it is on the record
   * before anybody delivers anything. Null means no zone line covers the run
   * and nobody has typed a price: never print it as ₱0 — `needs_zone` and
   * `pricing_note` say what to show instead.
   */
  price_cents: number | null;
  currency: string;
  /** No zone line covers this run, so it has no price and cannot go out. */
  needs_zone: boolean;
  /** Why it needs a zone — "No zone covers 712 km for a 10-wheeler." */
  pricing_note: string | null;
  /** `zone`, `manual` or `unzoned`; null on rows older than the column. */
  pricing_source: 'zone' | 'manual' | 'unzoned' | null;
  /** Typed by somebody who manages the Pricing card, and never re-quoted. */
  manually_priced: boolean;

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
  /** The trucking service, when the trucker gave one at sign-up. */
  trucker_business_name?: string | null;
  trucker_phone: string | null;
  trucker_vehicle_id: string | null;
  trucker_plate: string | null;
  /**
   * Which of the trucker's own drivers is on it. Null while the owner drives
   * it themselves, and on every Cargo Rush run — never a Cargo Rush driver.
   */
  trucker_driver_id?: string | null;
  trucker_driver_name?: string | null;

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
  /** A past trip entered as Delivered: when it was delivered (default: scheduled). */
  delivered_at?: string | null;
  /** A past trip entered as Delivered: who took the load. */
  receiver_name?: string | null;
  /**
   * Almost never sent: the API quotes the haul off the zone card. It is here
   * for a rate somebody negotiated, or a run no zone line covers — and only for
   * an account with `pricing.manage`. Null hands the run back to the card.
   */
  price_cents?: number | null;
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
  /** A negotiated rate (`pricing.manage` only). Sending it stops the card overruling it. */
  price_cents?: number | null;
}

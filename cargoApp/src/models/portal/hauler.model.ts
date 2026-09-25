/**
 * Who could carry this load — `GET /api/v1/portal/haulers`.
 *
 * Two kinds of answer on one list, and they are not alternatives of the same
 * shape:
 *
 *   **The fleet** is always on it and is never filtered by distance. It has a
 *   yard, a roster and units it can send, so it answers for a load across town
 *   and for one two provinces away alike. A customer who saw an empty list
 *   because nobody happened to be nearby would conclude the app cannot help
 *   them, when the business plainly can.
 *
 *   **A trucker** is one person with one truck. They are only on the list if
 *   they are vetted, online, holding a truck that is not in the shop, and close
 *   enough to actually turn up — so the absence of any is an ordinary Tuesday
 *   rather than a fault.
 *
 * Picking a trucker makes the request an **offer** held for them alone: it is
 * on nobody else's board and is not a job until they accept it. Picking the
 * fleet leaves it on the open board for the desk to crew or for any trucker to
 * take.
 */
export interface Hauler {
  kind: 'company' | 'trucker';
  id: string;
  name: string;

  /**
   * Straight-line kilometres from the pickup, or null when the app had no
   * position to measure from.
   *
   * A sort order rather than an ETA, and the screen says "away" rather than
   * giving a time — a straight line under-reads a mountain road.
   */
  distance_km: number | null;

  /** The most this hauler could take. The fleet's is its largest free unit. */
  capacity_kg: number;

  /** Trucker only. */
  vehicle?: string | null;
  plate?: string | null;
  truck_category?: string | null;
  /**
   * The only reputation signal there is, and deliberately a small one: a
   * rating nobody has collected would be a number invented to look like one.
   */
  trips_completed: number | null;

  /** Company only. */
  vehicles_ready?: number;
  address?: string | null;
  contact_phone?: string | null;
}

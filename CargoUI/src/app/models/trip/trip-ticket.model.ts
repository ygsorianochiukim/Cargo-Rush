/**
 * What the trip ticket and the dispatch checklist print —
 * `GET /api/v1/trips/{id}/ticket`.
 *
 * Everything the system knows about the run, for the two paper forms a truck
 * leaves the yard with. What is only known on the road — times, odometer,
 * fuel receipts, the ticks and the signatures — is not here: those lines are
 * printed blank for the crew and the office to fill in by hand.
 */
export interface TripTicket {
  issuer: {
    name: string | null;
    address: string | null;
    contact_phone: string | null;
    contact_email: string | null;
    logo_url: string | null;
  };
  trip: {
    id: string;
    reference: string;
    status: string;
    dispatch_date: string | null;
    origin: string | null;
    destination: string | null;
    cargo: string | null;
    weight_kg: number | null;
    pieces: number | null;
    distance_km: number | null;
  };
  crew: {
    driver: string | null;
    licence_no: string | null;
    helper_1: string | null;
    helper_2: string | null;
    /** Beyond two — the paper has no box for them, so they are listed under it. */
    more_helpers: string[];
    /** The partner's name on a trucker's run; null on the fleet's own. */
    partner: string | null;
  };
  vehicle: { plate: string | null; model: string | null; type: string | null };
  customer: { name: string | null; contact: string | null };
  /** Allowance already put against the trip on the daily sheet, or null. */
  allowance_cents: number | null;
  /** Each line with the driver's answer from the app, or null if unanswered. */
  checklist: {
    section: string;
    items: { key: string; label: string; answer: 'yes' | 'no' | 'na' | null }[];
  }[];
  /** Who answered the checklist on the phone, when, and their remarks. */
  checked: { at: string | null; by: string | null; remarks: string | null };
}

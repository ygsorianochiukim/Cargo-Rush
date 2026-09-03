import { StatusValue } from '@/constants/status';

/**
 * The customer's own view — `GET /api/v1/portal/*`.
 *
 * The exact counterpart of the driver-scoped trip endpoints: nothing here
 * carries an id the handset could change into another firm's, because the API
 * resolves the customer from the token. A customer asking "my deliveries" is a
 * different question from the office asking "all deliveries", and it is
 * answered by a different endpoint rather than by a filter somebody might
 * forget to apply.
 */

/** What the customer's home screen leads with. */
export interface PortalSummary {
  customer: { id: string; name: string };
  /** Requests the desk has not decided on. What they are waiting for. */
  awaiting_confirmation: number;
  scheduled: number;
  in_transit: number;
  delivered: number;
  /** Invoiced and unsettled, in centavos. */
  pending_payment_cents: number;
  /** Settled. Money the business has actually received from them. */
  successful_payment_cents: number;
  currency: string;
}

/**
 * What the customer fills in to ask for a pickup.
 *
 * Deliberately the smallest form in the app: everything the office decides —
 * driver, helper, unit, schedule, status — is absent, because a customer has
 * no basis to fill it in and a request that arrived pre-assigned would skip
 * the confirmation it exists to ask for.
 *
 * `preferred_at` is a wish, not a booking. The desk can move it when
 * confirming, which is the honest arrangement: the customer asks for Tuesday
 * morning, the fleet says whether Tuesday morning is possible.
 */
export interface DeliveryRequestPayload {
  origin: string;
  destination: string;
  /**
   * Where the two ends actually are, when the customer pinned them.
   *
   * Optional in the same way the office form has them optional: a place name
   * is enough to book against, and half a coordinate is not a location — so
   * each end travels as a pair or not at all, which is what the API enforces.
   *
   * Worth sending for more than the map: the quote is worked out from the
   * distance between the two pins, so a pinned request is priced on the run it
   * actually is rather than on the tariff's base and weight alone.
   */
  origin_lat?: number | null;
  origin_lng?: number | null;
  destination_lat?: number | null;
  destination_lng?: number | null;
  pickup_place?: string | null;
  dropoff_place?: string | null;
  cargo: string;
  weight_kg: number;
  pieces?: number;
  handling?: string | null;
  preferred_at: string;
}

/** A receivable, as the customer reads it. */
export interface PortalInvoice {
  id: string;
  number: string;
  trip_reference: string | null;
  issued_at: string;
  due_at: string;
  amount_cents: number;
  currency: string;
  status: StatusValue;
  /** When it was settled. Null while it is still owed. */
  paid_at: string | null;
}

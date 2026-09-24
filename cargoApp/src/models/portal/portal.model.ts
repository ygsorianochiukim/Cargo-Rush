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
  /**
   * The same figures again, per haulier.
   *
   * One entry for a customer the office added — they have one carrier and will
   * only ever have one. Several for a shipper who signed themselves up and has
   * sent with more than one firm, which is why the totals above are worth
   * having: "is anything of mine on the road" is one question, and "who do I
   * owe" is a different one that needs a name attached.
   */
  carriers: PortalCarrierFigures[];
}

/** One haulier's slice of the home screen. */
export interface PortalCarrierFigures {
  /** The company id — what a request's `carrier_id` would be. */
  id: string;
  name: string;
  /** This shipper's `customers` row in that company's books. */
  customer_id: string;
  awaiting_confirmation: number;
  scheduled: number;
  in_transit: number;
  delivered: number;
  pending_payment_cents: number;
  successful_payment_cents: number;
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
  /**
   * Which haulier is being asked, from the carrier list.
   *
   * Absent means "my usual one", which is what a customer the office put on the
   * books always means and what every screen sent before the list existed. An
   * account that cannot choose is refused outright if it sends somebody else's
   * id, rather than having the load quietly filed with their own carrier.
   */
  carrier_id?: string;

  /**
   * A partner trucker the customer picked off the hauler list.
   *
   * Absent — the ordinary case — means "whoever the fleet sends", which is what
   * picking Cargo Rush on that screen does: the request goes on the open board
   * for the desk to crew or for any nearby trucker to take.
   *
   * Naming somebody makes it an **offer** held for them alone. It is on nobody
   * else's board and is not a job until they accept, so a customer who picks a
   * name gets that person or nobody — never a silent substitution.
   */
  trucker_id?: string;

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
/** One payment against an invoice, as much of it as landed there. */
export interface PortalInvoicePayment {
  paid_on: string | null;
  method: string | null;
  /** `bank_transfer` as somebody would say it. */
  method_label: string | null;
  reference: string | null;
  /** The allocated share — one transfer can settle three invoices. */
  amount_cents: number;
}

export interface PortalInvoice {
  id: string;
  number: string;
  issued_at: string;
  due_at: string;
  currency: string;
  status: StatusValue;
  /** When it was settled. Null while it is still owed. */
  paid_at: string | null;

  /**
   * The liquidation — how the total is arrived at.
   *
   *     hauling charge + VAT = invoice total
   *     invoice total − withholding = amount payable
   *     amount payable − received = balance
   *
   * All of it, because a customer reading a figure on a phone has nothing to
   * check it against otherwise. The rates are the ones frozen on the document
   * when it was issued, not today's.
   */
  net_amount_cents: number;
  vat_cents: number;
  amount_cents: number;
  withholding_cents: number;
  due_cents: number;
  paid_cents: number;
  balance_cents: number;
  vat_rate_bp: number;
  withholding_rate_bp: number;

  /** Every payment received against it, oldest first. */
  payments: PortalInvoicePayment[];

  /**
   * The haul it is for, and the day they asked for it.
   *
   * `requested_at` is what the list is ordered by: a customer looks for "the
   * Silway run" by when they sent it, not by the day an office raised the
   * paperwork.
   */
  trip_reference: string | null;
  trip_origin: string | null;
  trip_destination: string | null;
  trip_cargo: string | null;
  trip_weight_kg: number | null;
  trip_status: StatusValue | null;
  requested_at: string;
  /**
   * Who is owed.
   *
   * A shipper using two hauliers has two sets of receivables, and "INV-2026-0440
   * is overdue" is not actionable until you know which office to pay.
   */
  carrier_id: string | null;
  carrier: string | null;
}

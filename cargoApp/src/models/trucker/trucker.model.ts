import { StatusValue } from '@/constants/status';

/**
 * The partner's own record — `GET /api/v1/partner/me`.
 *
 * `cargoApp` is one app holding three products now, and this is the third: the
 * driver's cab screens, the customer's portal, and an owner-operator choosing
 * which work to take. A trucker is not a driver with extra fields — there is no
 * payslip and no pre-trip check, and in their place there is a job board and a
 * wallet.
 *
 * **Two flags, and they are not the same question.** `status` is what the fleet
 * decided about them, and `is_online` is what they decided this morning. The
 * app has to be able to tell somebody waiting on an approval from somebody who
 * is simply off duty, because those are two completely different screens — one
 * says "we are checking your licence" and the other has a switch on it. A
 * client that collapsed them into one "available" would show the wrong one.
 */
export interface Trucker {
  id: string;
  name: string;
  phone: string;
  licence_no: string;
  licence_expiry: string | null;

  /**
   * The fleet's decision.
   *
   * `pending` — registered, nobody has read the licence yet. The job board is
   * empty and the app says why rather than showing an empty screen.
   * `active` — approved; may take work.
   * `inactive` — put on hold by the office.
   */
  status: StatusValue;

  /** Their own switch. Meaningless until `status` is `active`. */
  is_online: boolean;

  /**
   * Both of the above, plus a truck that is not in the shop.
   *
   * Derived by the API so the app does not re-implement a three-part rule that
   * the accept endpoint is the real judge of — a screen that offered a button
   * the server is about to refuse is worse than one that explains.
   */
  can_take_work: boolean;

  /**
   * What the fleet keeps, in basis points. 1200 is 12%.
   *
   * One standing rate for every partner the fleet hauls with, set by the office
   * on its own settings screen. There was a per-partner override and it is
   * gone — a commission is a commercial term stated once, not re-typed per
   * person.
   */
  commission_bp: number;

  latitude: number | null;
  longitude: number | null;
  located_at: string | null;
  /** Is that pin recent enough for the board to sort by? */
  position_fresh: boolean;

  trips_completed: number;

  vehicles?: TruckerVehicle[];
}

/**
 * A partner's own truck.
 *
 * Not one of the fleet's vehicles, and the app never confuses the two: there is
 * no odometer, no service interval and no fuel budget here, because none of
 * that is the haulier's business. What matters is what it can carry, which is
 * what decides which loads appear on the board.
 */
export interface TruckerVehicle {
  id: string;
  plate: string;
  model: string;
  capacity_kg: number;
  truck_category_id: string | null;
  truck_category?: string | null;
  status: StatusValue;
}

/**
 * A load on the board — `GET /api/v1/partner/jobs`.
 *
 * Deliberately not a `Trip`. This is read by somebody deciding whether a run is
 * worth their afternoon, so it carries the route, the load, how far the pickup
 * is, and — the part that matters — what they would actually clear on it.
 *
 * Every job here is an **offer**: a customer picked this trucker by name and is
 * waiting on them to accept. There is no open board — a request nobody has
 * directed anywhere stays with the office until a human places it, so a trucker
 * never sees work that was not meant for them.
 *
 * They all pay the same way: take it, bill the customer, and the fleet's cut is
 * charged to the wallet. Work the *fleet* assigned never appears here — it is
 * already theirs, on My Trips, and it pays the other way round.
 */
export interface Job {
  id: string;
  reference: string;

  origin: string;
  destination: string;
  pickup_place: string | null;
  dropoff_place: string | null;
  origin_lat: number | null;
  origin_lng: number | null;
  destination_lat: number | null;
  destination_lng: number | null;

  cargo: string;
  weight_kg: number;
  pieces: number;
  handling: string | null;
  truck_category?: string | null;

  distance_total_m: number;
  /**
   * Straight-line metres from the partner to the **pickup**, or null when
   * neither the handset nor their last pin could say.
   *
   * A sort order, not an ETA, and the screen says "away" rather than giving a
   * time — a straight line under-reads a mountain road and promising minutes
   * from it would be a promise nobody can keep.
   */
  distance_from_m: number | null;

  scheduled_at: string | null;
  status: StatusValue;

  /** What the run bills the customer. */
  price_cents: number;
  /** What the fleet keeps of it, in basis points. */
  commission_bp: number;
  /**
   * What lands with the partner.
   *
   * The figure the card leads with. Showing the gross would be quoting a number
   * nobody receives, and a percentage somebody discovers afterwards is how a
   * platform loses the people it depends on.
   */
  your_take_cents: number;
  currency: string;

  customer_name?: string | null;

  created_at: string;
}

/** Where a wallet row came from. Null on a payout or a correction. */
export type WalletSource = 'cargo_rush' | 'direct';

export type WalletEntryKind = 'earning' | 'commission' | 'payout' | 'remittance' | 'adjustment';

/**
 * One line of the statement.
 *
 * `amount_cents` is signed, and the sign is the whole design: positive is money
 * coming towards the partner, negative is money going the other way. The screen
 * renders the sign; it does not decide it.
 */
export interface WalletEntry {
  id: string;
  kind: WalletEntryKind;
  kind_label: string;
  /** The sentence, built by the API so the phone and the office cannot word it differently. */
  description: string;

  amount_cents: number;

  source: WalletSource | null;
  source_label: string | null;

  /** What the run billed, and what was taken off it — frozen when the row was written. */
  gross_cents: number | null;
  rate_bp: number | null;

  trip_id: string | null;
  trip_reference: string | null;

  reference: string | null;
  note: string | null;
  occurred_on: string | null;

  /**
   * Is this a row that can be paid for at all?
   *
   * True for a run. False for a payout, which *is* the payment, and for an
   * adjustment, which corrects the balance rather than recording work — so
   * neither carries a paid state and neither shows one.
   */
  settleable: boolean;

  /** Has a payment been recorded against this run? */
  settled: boolean;
  settled_at: string | null;
  /** The cheque or transfer it was paid on, so it can be checked against. */
  settlement_reference?: string | null;

  /**
   * Where this run's payment has got to — three states, not two.
   *
   * `unpaid` nobody has started; `processing` the fleet has sent it and it
   * has not landed; `paid` it arrived. Null on a payment row or an
   * adjustment, neither of which is a run awaiting payment.
   */
  payment_state: 'unpaid' | 'processing' | 'paid' | null;

  /** On a payment row: `pending` while in flight, `paid` once it landed. */
  status: string;
  /** cash · cheque · bank_transfer · online. */
  method: string | null;
}

/**
 * The wallet screen, in one call — `GET /api/v1/partner/wallet`.
 *
 * The figures and the statement together, because they are one screen and three
 * round trips on a handset in a dead spot is three chances to show half of it.
 */
export interface Wallet {
  /**
   * Signed centavos.
   *
   * Positive: the fleet owes the partner. Negative: the partner owes the fleet.
   * One account rather than a payable and a receivable that never meet — a
   * partner who did the fleet's work and some of their own nets the two.
   */
  balance_cents: number;

  /**
   * Which way the account points, in a word.
   *
   * Sent rather than derived from the sign so no screen has to decide what a
   * negative balance means — the two are not symmetric to read, one is money
   * coming and the other is a bill.
   */
  standing: 'owed_to_trucker' | 'owed_to_company' | 'settled';

  earned_cents: number;
  /** A positive magnitude: "commission charged: ₱1,200" is what a person reads. */
  commission_cents: number;
  paid_out_cents: number;
  remitted_cents: number;
  adjustments_cents: number;
  trips_settled: number;

  /**
   * What is still unpaid, run by run.
   *
   * Not the same as the balance: the balance includes adjustments, which are
   * corrections rather than runs anybody is owed for. The wallet screen leads
   * with this figure because "what am I still waiting to be paid for" is the
   * question a partner actually opens the app with.
   */
  unpaid_earnings_cents: number;
  unpaid_earnings_count: number;
  unremitted_commission_cents: number;
  unremitted_commission_count: number;

  /**
   * Money the fleet has sent that has not landed yet.
   *
   * Shown beside the balance rather than taken off it. A transfer takes a day
   * and can bounce, so until it clears the money is still owed — and a wallet
   * that dropped to nought the moment the office typed it would be telling
   * somebody they had been paid when their account says otherwise.
   */
  in_flight_cents: number;

  entries: WalletEntry[];
}

/**
 * What `POST /api/v1/register/trucker` takes.
 *
 * More than the customer's sign-up asks for, and every extra field earns its
 * place. The **fleet** is asked because a partner's relationship is a standing
 * one — a rate, a vetting and a running balance — and none of those can exist
 * with nobody; a customer genuinely can pick per load, and does. The **licence**
 * is what a human at the fleet reads before approving. The **truck** is what
 * decides which loads can be offered at all.
 */
export interface TruckerRegistration {
  name: string;
  contact_phone: string;
  email: string;
  password: string;
  password_confirmation: string;

  /**
   * Which fleet — and the app never sends it.
   *
   * A trucker registers with Cargo Rush wherever in the country they are, so
   * the form does not ask and the API resolves it. Kept on the type because an
   * install running more than one fleet needs some way to say which.
   */
  company_id?: string;

  licence_no: string;
  licence_expiry?: string;

  plate: string;
  model: string;
  capacity_kg: number;
  truck_category_id?: string;

  /** Always sent from here: the handset wants a bearer token, not a cookie. */
  device_name: string;
}

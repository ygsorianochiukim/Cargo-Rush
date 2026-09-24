import { StatusValue } from '../shared/status.model';

/**
 * A partner trucker — an owner-operator hauling for this fleet.
 *
 * Not a driver, and the office screens never treat them as one. A driver is on
 * the payroll: a contract, a payslip, a salary column on the day's ledger
 * sheet. A trucker is a business the fleet deals with, paid a share of what
 * each run billed, with a wallet that carries a balance from week to week.
 *
 * **Two flags that look alike and are not.** `status` is the office's decision
 * — has anybody read this licence and said yes. `is_online` is the partner's
 * own switch, this morning. A suspension is not undone by somebody flipping
 * their switch, and an approval does not put them to work; the roster shows
 * both because the desk needs to know which of the two is why somebody is not
 * hauling.
 */
export interface Trucker {
  id: string;
  name: string;
  phone: string;
  licence_no: string;
  licence_expiry: string | null;

  /** `pending` until vetted, `active` once approved, `inactive` on hold. */
  status: StatusValue;
  is_online: boolean;
  /** Vetted, online, and holding a truck that is not in the shop. */
  can_take_work: boolean;

  /**
   * What the fleet keeps of their runs, in basis points. 1200 is 12%.
   *
   * One standing rate for every partner the firm hauls with, set by the office
   * on Access Control. There was a per-partner override and it is gone — a
   * commission is a commercial term stated once, not re-typed per person.
   */
  commission_bp: number;

  latitude: number | null;
  longitude: number | null;
  located_at: string | null;
  /** Whether that pin is recent enough to act on. */
  position_fresh: boolean;

  trips_completed: number;
  user_id: number | null;

  vehicles?: TruckerVehicle[];

  created_at: string;
  updated_at: string;
}

/**
 * A partner's own truck.
 *
 * Deliberately not a `vehicles` row and never shown in Vehicle Management: the
 * fleet neither owns nor maintains it, so it carries no odometer, no service
 * interval and no fuel budget, and it is kept out of every fleet-utilisation
 * figure. What the desk needs is what it can carry.
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

export type WalletEntryKind = 'earning' | 'commission' | 'payout' | 'remittance' | 'adjustment';
export type WalletSource = 'cargo_rush' | 'direct';

/**
 * One line of a partner's running account.
 *
 * `amount_cents` is signed: positive moves the balance towards the partner,
 * negative towards the fleet. `gross_cents`, `rate_bp` and `source` are frozen
 * at the moment the row was written, so a figure from March still explains
 * itself in November whatever has been renegotiated since.
 */
export interface WalletEntry {
  id: string;
  kind: WalletEntryKind;
  kind_label: string;
  description: string;

  amount_cents: number;

  source: WalletSource | null;
  source_label: string | null;

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
   * True for a run — an earning or a commission. False for a payout, which
   * *is* the payment, and for an adjustment, which corrects the balance rather
   * than recording work. Only settleable rows appear in the settle form.
   */
  settleable: boolean;

  /** Has a settlement been recorded against this run? */
  settled: boolean;
  settled_at: string | null;
  /** The cheque or transfer number it was paid on. */
  settlement_reference?: string | null;

  /**
   * Where a run's payment has got to — three states, not two.
   *
   * `unpaid` nobody has started; `processing` the money is on its way;
   * `paid` it landed. Null on a payment row or an adjustment, neither of
   * which is a run awaiting payment.
   *
   * Derived by the API because it spans two rows — this one's settlement
   * link and the settlement's own status — and three clients combining them
   * would be three chances to tell a partner they have been paid while the
   * transfer is still in the air.
   */
  payment_state: 'unpaid' | 'processing' | 'paid' | null;

  /** On a payment row itself: `pending` while in flight, `paid` once landed. */
  status: string;
  /** cash · cheque · bank_transfer · online. Null on rows that are not payments. */
  method: string | null;
}

/**
 * One partner's account, as the office tab reads it.
 *
 * The same shape the partner's own handset gets, deliberately: the point of
 * being open about a percentage is that both sides can check it against the
 * same rows, and two differently-shaped payloads would eventually disagree.
 */
export interface Wallet {
  /**
   * Signed centavos. Positive: the fleet owes them. Negative: they owe the
   * fleet.
   */
  balance_cents: number;
  /** The direction in a word, so no screen has to decide what a minus means. */
  standing: 'owed_to_trucker' | 'owed_to_company' | 'settled';

  earned_cents: number;
  /** A positive magnitude — "charged ₱1,200" is what a person reads. */
  commission_cents: number;
  paid_out_cents: number;
  remitted_cents: number;
  adjustments_cents: number;
  trips_settled: number;

  /**
   * What is still unpaid, run by run — what the settle form is driven by.
   *
   * Not the same figure as the balance, and the difference matters at the
   * desk: the balance includes adjustments, which are corrections rather than
   * runs anybody can be paid for. A partner with ₱26,400 of unpaid runs and a
   * −₱5,000 adjustment has a balance of ₱21,400 and ₱26,400 of payable work,
   * and both are true.
   */
  unpaid_earnings_cents: number;
  unpaid_earnings_count: number;
  unremitted_commission_cents: number;
  unremitted_commission_count: number;

  /**
   * Money already sent that has not landed yet.
   *
   * Beside the balance rather than inside it. "You are owed ₱26,400, and
   * ₱26,400 of it is on its way" is the honest sentence; folding the two
   * together would claim the transfer had arrived the moment it was typed.
   */
  in_flight_cents: number;

  entries: WalletEntry[];
}

/**
 * Settling up — a payout, a remittance, or a correction.
 *
 * `earning` and `commission` are absent on purpose: those are written by a
 * delivery and by nothing else. A desk that could hand-write an earning could
 * credit a run that never happened.
 */
export interface WalletEntryPayload {
  kind: 'payout' | 'remittance' | 'adjustment';

  /**
   * The runs being settled. Payout and remittance only.
   *
   * Omitted or empty means **every** outstanding run, which is the ordinary
   * case and the one button the desk presses. Naming a subset is the part
   * payment.
   */
  entry_ids?: string[];

  /**
   * Signed centavos, and **only** for an adjustment.
   *
   * A settlement carries no figure of its own — it is the sum of the runs it
   * covers, so the money handed over and the runs it paid for cannot
   * disagree. Sending one with a payout is refused rather than ignored.
   */
  amount_cents?: number;

  /** cash · cheque · bank_transfer · online. Defaults to a transfer. */
  method?: string;
  /**
   * Has it landed already?
   *
   * False unless said otherwise, because a transfer takes a day. Cash across
   * a desk sets it true. Until it is true the balance does not move.
   */
  cleared?: boolean;

  reference?: string | null;
  note?: string | null;
  occurred_on?: string | null;
}

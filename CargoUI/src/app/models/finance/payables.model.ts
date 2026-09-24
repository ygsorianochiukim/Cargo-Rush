/**
 * Everything the fleet owes, in one list.
 *
 * A roll-up over four modules that never met: a partner's wallet, a hired
 * truck's rent, a supplier's bill, and whatever else was filed as spend. Each
 * screen was right about its own corner, and nobody could answer "what do we
 * owe this week" without opening all four and adding up.
 *
 * Read-only by design. Every line names the screen that settles it, and is
 * settled there under that module's own permission — so somebody can be
 * trusted to see what the week costs without being able to move any of it.
 */
export interface Payables {
  /** What the fleet owes, across every group. */
  total_cents: number;

  /**
   * How much of that is already out of the door.
   *
   * Only partner payments have this state — a supplier bill is paid or it is
   * not. Reported at the top because the question is about the week rather
   * than about one partner.
   */
  in_flight_cents: number;

  groups: PayableGroup[];
}

export interface PayableGroup {
  key: 'truckers' | 'rented_trucks' | 'supplier_bills' | 'other';
  label: string;
  icon: string;
  count: number;
  total_cents: number;
  in_flight_cents: number;
  lines: PayableLine[];
}

export interface PayableLine {
  /**
   * The record itself — a trucker, an invoice, an expense.
   *
   * Not just a key for `@for`: it rides along on `settle_at` as `?settle=`,
   * and the page at the far end opens that record rather than its list.
   */
  id: string;
  /** Who is owed — a partner, a plate, a supplier. */
  name: string;
  /**
   * What kind of arrangement this is.
   *
   * For a partner it says which of the three they are — "Rented 10-wheeler
   * owner · TEN-1", "Sub-contractor · ABC-123", "Partner trucker" — because
   * "trucker" covers all three and somebody writing a cheque is choosing
   * between them.
   */
  detail: string;
  /** What is left to pay, not what was originally billed. */
  amount_cents: number;
  /** Of that, how much has been sent and not landed. */
  in_flight_cents: number;
  due_on: string | null;
  /**
   * The route that settles this line, followed with `?settle={id}`.
   *
   * The page links rather than settles — but it links at the record, not at
   * the module. A roll-up that says "it is somewhere on the Truckers page" is
   * only half an answer.
   */
  settle_at: string;
}

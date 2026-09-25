import { StatusValue } from '../shared/status.model';

/**
 * Somebody the fleet buys from — `GET /api/v1/suppliers`.
 *
 * The mirror of `Customer`, and a separate record for the reason the two
 * directions of an invoice are not: a customer has trips, a portal login, a VAT
 * treatment and a rating, and a supplier has none of those. What they share is
 * a name and a phone number, which is not enough to make them one thing.
 */
export interface Supplier {
  id: string;
  name: string;
  contact: string | null;
  address: string | null;
  /** What they sell, in the office's own words. A sentence, not a category. */
  supplies: string | null;
  note: string | null;
  status: StatusValue;

  /**
   * The three places a supplier's money shows up, and their total.
   *
   * Kept apart rather than merged, because what *kind* of spend it was is what
   * tells a garage from a chandler: a garage's total is servicing, a chandler's
   * is consumables, and one figure would hide that.
   *
   * Null means "not counted on this read" rather than zero — a supplier fetched
   * outside the list has no aggregates loaded, and reporting a real supplier as
   * having cost nothing would be worse than saying nothing.
   */
  expense_spend_cents: number | null;
  service_spend_cents: number | null;
  billed_cents: number | null;
  spend_cents: number | null;

  expenses_count?: number;
}

export interface SupplierPayload {
  name: string;
  contact?: string | null;
  address?: string | null;
  supplies?: string | null;
  note?: string | null;
  status?: string;
}

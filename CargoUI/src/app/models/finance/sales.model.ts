/**
 * Sales — the same roll-up over three bucket sizes.
 *
 * Read off the ledger rather than off trips, because trip income reaches the
 * ledger from two places (a delivered run credits it, and the office keys in
 * the days it books by hand) and the ledger is the only source with all of the
 * business's takings in it. Money is integer centavos.
 */
export type Granularity = 'daily' | 'weekly' | 'monthly';

export interface SalesBucket {
  /** Sortable and stable: `2026-08-20`, `2026-W34`, `2026-08`. */
  key: string;
  /** "20 Aug 2026" / "Week of 17 Aug 2026" / "August 2026". */
  label: string;
  from: string;
  to: string;
  sales_cents: number;
  expenses_cents: number;
  net_cents: number;
  entry_count: number;
}

export interface SalesTotals {
  sales_cents: number;
  expenses_cents: number;
  net_cents: number;
  /** net / sales, or null when there were no sales to divide by. */
  margin: number | null;
  /** Averaged over the buckets that traded, not over the calendar. */
  average_cents: number;
  /** Null when nothing was earned — "best day: ₱0" would read as a fault. */
  best: SalesBucket | null;
}

export interface SalesReport {
  granularity: Granularity;
  range: { from: string; to: string };
  /** Oldest first. Empty for a quiet window, which is not an error. */
  series: SalesBucket[];
  totals: SalesTotals;
  currency: string;
}

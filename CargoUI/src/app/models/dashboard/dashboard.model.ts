import { StatusTone, StatusValue } from '../shared/status.model';

/** A dashboard tile. `delta` is null when there is no prior period. */
export interface Kpi {
  key: string;
  label: string;
  value: string;
  delta: number | null;
  higher_is_better: boolean;
}

/** Where the fleet is. Every bucket is sent, even at zero. */
export interface FleetBreakdown {
  status: StatusValue;
  label: string;
  count: number;
}

/** Deliveries closed out per day. Quiet days come back as zero, not absent. */
export interface DeliveryPoint {
  day: string;
  date: string;
  delivered: number;
}

/** The activity feed, merged from deliveries, incidents and trips. */
export interface ActivityEntry {
  id: string;
  icon: string;
  title: string;
  detail: string;
  /** ISO. */
  at: string;
  tone: StatusTone;
}

/**
 * Money owed against money collected — `GET dashboard/receivables`.
 *
 * Three figures answering three different questions. `income_cents` is what
 * the fleet earned on the road over the window and is deliberately not the
 * same as either payment figure: a run delivered on the last day of the month
 * is income now and cash in thirty days, and conflating the two would call a
 * good month a cash-flow problem.
 *
 * `overdue_cents` is a subset of `pending_payment_cents`, not a fourth bucket
 * — it is the part of what is owed that needs chasing.
 */
export interface Receivables {
  pending_payment_cents: number;
  successful_payment_cents: number;
  overdue_cents: number;
  income_cents: number;
  expenses_cents: number;
  net_income_cents: number;
  pending_count: number;
  paid_count: number;
  window_days: number;
  currency: string;
}

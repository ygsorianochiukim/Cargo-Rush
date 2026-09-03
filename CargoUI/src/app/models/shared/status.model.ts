/**
 * The shared status vocabulary — DESIGN.md section 1.
 *
 * The API returns the string; this file is the only place a client turns one
 * into a colour. A hex value never crosses the wire.
 */
export type StatusValue =
  | 'active'
  | 'delivered'
  | 'available'
  | 'in_transit'
  | 'assigned'
  | 'scheduled'
  | 'pending'
  | 'maintenance'
  | 'cancelled'
  | 'overdue'
  | 'inactive'
  /** Settled — money in, as against a haul that was merely delivered. */
  | 'paid';

export type StatusTone = 'success' | 'info' | 'warning' | 'danger';

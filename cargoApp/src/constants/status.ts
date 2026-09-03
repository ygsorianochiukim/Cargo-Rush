import { Brand } from './theme';

/** DESIGN.md section 1 — the shared status vocabulary, identical to the web client. */
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

const TONES: Record<StatusValue, StatusTone> = {
  active: 'success',
  delivered: 'success',
  available: 'info',
  in_transit: 'info',
  assigned: 'info',
  scheduled: 'info',
  pending: 'warning',
  maintenance: 'warning',
  cancelled: 'danger',
  overdue: 'danger',
  inactive: 'danger',
  // A paid invoice is a closed, healthy thing — the same tone as a delivered
  // haul, which is the word it used to borrow.
  paid: 'success',
};

export function toneFor(status: StatusValue): StatusTone {
  return TONES[status] ?? 'info';
}

/** `in_transit` -> `In transit`. Display only. */
export function statusLabel(status: StatusValue): string {
  const s = status.replace(/_/g, ' ');
  return s.charAt(0).toUpperCase() + s.slice(1);
}

export const TONE_COLORS: Record<StatusTone, { fg: string; bg: string }> = {
  success: { fg: Brand.success, bg: Brand.successBg },
  info: { fg: Brand.blue, bg: Brand.tint },
  warning: { fg: Brand.warning, bg: Brand.warningBg },
  danger: { fg: Brand.red, bg: Brand.redBg },
};

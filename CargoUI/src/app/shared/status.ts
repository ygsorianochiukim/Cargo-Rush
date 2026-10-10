import { StatusTone, StatusValue } from '../models/shared/status.model';

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
  paid: 'success',
};

export function toneFor(status: StatusValue): StatusTone {
  return TONES[status] ?? 'info';
}

export function statusLabel(status: StatusValue): string {
  const s = status.replace(/_/g, ' ');
  return s.charAt(0).toUpperCase() + s.slice(1);
}

export const TONE_CLASS: Record<StatusTone, string> = {
  success: 'bg-cr-success-bg text-cr-success',
  info: 'bg-cr-tint text-cr-blue',
  warning: 'bg-cr-warning-bg text-cr-warning',
  danger: 'bg-cr-red-bg text-cr-red',
};

export const TONE_DOT: Record<StatusTone, string> = {
  success: 'bg-cr-success',
  info: 'bg-cr-blue',
  warning: 'bg-cr-warning',
  danger: 'bg-cr-red',
};

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
  | 'paid';

export type StatusTone = 'success' | 'info' | 'warning' | 'danger';

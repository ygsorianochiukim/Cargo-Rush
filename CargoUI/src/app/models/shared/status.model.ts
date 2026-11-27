export type StatusValue =
  | 'active'
  | 'delivered'
  | 'available'
  | 'in_transit'
  | 'assigned'
  | 'scheduled'
  | 'pending'
  | 'billed'
  | 'maintenance'
  | 'cancelled'
  | 'overdue'
  | 'inactive'
  | 'partial'
  | 'paid';

export type StatusTone = 'success' | 'info' | 'warning' | 'danger';

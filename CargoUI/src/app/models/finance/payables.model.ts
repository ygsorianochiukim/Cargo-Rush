export interface Payables {
  total_cents: number;

  in_flight_cents: number;

  groups: PayableGroup[];
}

export interface PayableGroup {
  key: 'truckers' | 'rented_trucks' | 'supplier_bills' | 'payroll' | 'other';
  label: string;
  icon: string;
  count: number;
  total_cents: number;
  in_flight_cents: number;
  overdue_count: number;
  lines: PayableLine[];
}

export interface PayableLine {
  id: string;
  name: string;
  detail: string;
  amount_cents: number;
  in_flight_cents: number;
  due_on: string | null;
  overdue: boolean;
  settle_at: string;
}

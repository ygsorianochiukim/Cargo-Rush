import { StatusTone, StatusValue } from '../shared/status.model';

export interface Kpi {
  key: string;
  label: string;
  value: string;
  delta: number | null;
  higher_is_better: boolean;
}

export interface FleetBreakdown {
  status: StatusValue;
  label: string;
  count: number;
}

export interface DeliveryPoint {
  day: string;
  date: string;
  delivered: number;
}

export interface ActivityEntry {
  id: string;
  icon: string;
  title: string;
  detail: string;
  at: string;
  tone: StatusTone;
}

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
  collection: {
    billed_cents: number;
    collected_cents: number;
    rate_pct: number | null;
  };
}

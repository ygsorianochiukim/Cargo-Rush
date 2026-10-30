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

export interface AssetSummary {
  count: number;
  cost_cents: number;
  assigned: number;
  available: number;
  maintenance: number;
  inactive: number;
  currency: string;
}

export type DashboardPeriod = 'month' | 'quarter' | 'year';

export interface MoneyKpi {
  key: 'income' | 'expenses' | 'net' | 'margin';
  label: string;
  value_cents: number | null;
  previous_cents: number | null;
  ratio?: number | null;
  previous_ratio?: number | null;
  change: number | null;
  higher_is_better: boolean;
}

export interface AgingBucket {
  key: 'current' | '1_30' | '31_60' | '61_90' | 'over_90';
  label: string;
  cents: number;
}

export interface BusinessDashboard {
  period: DashboardPeriod;
  label: string;
  range: { from: string; to: string };
  previous_range: { from: string; to: string };
  kpis: MoneyKpi[];
  income: {
    trip_income_cents: number;
    trucker_commission_cents: number;
    other_income_cents: number;
    vat_collected_cents: number;
  };
  receivables: {
    outstanding_cents: number;
    overdue_cents: number;
    collected_cents: number;
    billed_cents: number;
    collection_rate: number | null;
    aging: AgingBucket[];
    top_debtors: { name: string; total_cents: number; overdue_cents: number }[];
  };
  payables: {
    outstanding_cents: number;
    in_flight_cents: number;
    overdue_count: number;
    paid_cents: number;
    groups: {
      key: string;
      label: string;
      icon: string;
      count: number;
      total_cents: number;
      overdue_count: number;
    }[];
    bill_aging: AgingBucket[];
  };
  trend: {
    key: string;
    label: string;
    year: number;
    sales_cents: number;
    expenses_cents: number;
  }[];
  expense_mix: { kind: string; amount_cents: number }[];
  top_customers: { name: string; billed_cents: number; invoices: number }[];
  paid_from: { name: string; kind: string; amount_cents: number }[];
  currency: string;
}

export interface TruckDashboardRow {
  truck: { id: string; label: string; plate: string | null };
  status: string | null;
  trips: number;
  income_cents: number;
  expenses_cents: number;
  net_income_cents: number;
  margin: number | null;
  top_costs: { kind: string; amount_cents: number; count: number }[];
}

export interface TruckDashboard {
  period: 'day' | 'cutoff' | 'month';
  label: string;
  range: { from: string; to: string };
  previous: string;
  next: string;
  trucks: TruckDashboardRow[];
  totals: {
    income_cents: number;
    truck_expenses_cents: number;
    truck_net_cents: number;
    unassigned_cents: number;
    expenses_cents: number;
    net_income_cents: number;
    trips: number;
  };
  currency: string;
}

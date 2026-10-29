export interface Payables {
  total_cents: number;

  in_flight_cents: number;

  groups: PayableGroup[];
}

export interface PayableGroup {
  key: 'truckers' | 'truck_owners' | 'rented_trucks' | 'supplier_bills' | 'payroll' | 'other';
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

  trucker_id?: string;
  period_start?: string;
  period_end?: string;
  period_label?: string;
}

export type OwnerCutoffState = 'unpaid' | 'processing' | 'paid' | 'open' | 'nothing';

export interface OwnerRun {
  id: string;
  trip_id: string;
  reference: string | null;
  on: string | null;
  origin: string | null;
  destination: string | null;
  customer: string | null;
  plate: string | null;
  price_cents: number;
  vat_treatment_label: string | null;
  vat_in_price_cents: number;
  gross_cents: number;
  fleet_keeps_bp: number;
  commission_cents: number;
  share_cents: number;
  state: 'unpaid' | 'processing' | 'paid';
  counted: boolean;
  paid_elsewhere: string | null;
}

export type OwnerCostKind = 'fuel' | 'allowance' | 'maintenance';

export interface OwnerCost {
  kind: OwnerCostKind;
  source: 'fill' | 'run' | 'job' | 'ledger';
  on: string | null;
  plate: string | null;
  label: string;
  detail: string | null;
  litres: number | null;
  paid_with: string | null;
  cents: number;
}

export interface OwnerSettlement {
  id: string;
  kind: 'payout' | 'adjustment';
  amount_cents: number;
  reference: string | null;
  method: string | null;
  occurred_on: string | null;
  status: string;
}

export interface OwnerCutoff {
  start: string;
  end: string;
  label: string;
  short: string;
  due_on: string;
  state: OwnerCutoffState;
  runs: OwnerRun[];
  runs_count: number;
  costs: OwnerCost[];
  gross_cents: number;
  commission_cents: number;
  share_cents: number;
  paid_elsewhere_cents: number;
  fuel_cents: number;
  allowance_cents: number;
  maintenance_cents: number;
  costs_cents: number;
  net_cents: number;
  settlement: OwnerSettlement | null;
}

export interface OwnerStatementTruck {
  id: string;
  plate: string;
  arrangement: string;
  arrangement_label: string;
  fleet_keeps_bp: number;
  owner_keeps_bp: number;
}

export interface OwnerStatementOwner {
  id: string;
  name: string;
  business_name: string | null;
  plates: string[];
  trucks: OwnerStatementTruck[];
  cutoffs: OwnerCutoff[];
  totals: {
    runs: number;
    gross_cents: number;
    commission_cents: number;
    share_cents: number;
    fuel_cents: number;
    allowance_cents: number;
    maintenance_cents: number;
    costs_cents: number;
    net_cents: number;
    unpaid_cents: number;
  };
}

export interface OwnerTransaction {
  id: string;
  kind: 'payout' | 'remittance' | 'adjustment';
  kind_label: string;
  description: string;
  amount_cents: number;
  status: string;
  state: 'paid' | 'processing' | 'charged' | 'credited';
  method: string | null;
  reference: string | null;
  note: string | null;
  occurred_on: string | null;
  period_start: string | null;
  period_end: string | null;
  period_label: string | null;
  runs_count: number;
}

export interface OwnerStatement {
  from: string;
  to: string;
  calendar: string;
  owners: OwnerStatementOwner[];
}

export interface OwnerCutoffSettlePayload {
  start: string;
  end: string;
  method?: string;
  cleared?: boolean;
  reference?: string | null;
  note?: string | null;
  occurred_on?: string | null;
}

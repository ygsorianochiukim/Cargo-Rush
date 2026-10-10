import { Timestamped } from '../shared/envelope.model';

export interface Truck {
  id: string;
  label: string;
  plate: string | null;
  vehicle_id: string | null;
  position: number;
}

export interface LedgerEntry extends Timestamped {
  id: string;
  truck_id: string;
  truck_label: string | null;
  truck_plate: string | null;
  date: string;
  trip_income_cents: number;
  fuel_cents: number;
  driver_salary_cents: number;
  helper_salary_cents: number;
  maintenance_cents: number;
  allowance_cents: number;
  owner_share_cents: number;
  total_expenses_cents: number;
  net_income_cents: number;
  currency: string;
  route: string | null;
  remarks: string | null;
  trip_id: string | null;
  trip_reference: string | null;
  customer_id: string | null;
  customer: string | null;

  driver_id: string | null;
  driver_name: string | null;

  helpers: LedgerHelperLine[];
}

export interface LedgerHelperLine {
  driver_id: string | null;
  name: string | null;
  salary_cents: number;
}

export interface LedgerEntryPayload {
  truck_id: string;
  date: string;
  trip_income_cents: number;
  fuel_cents: number;
  driver_salary_cents: number;
  helper_salary_cents: number;
  maintenance_cents: number;
  allowance_cents: number;
  driver_id?: string | null;
  helpers?: { driver_id: string | null; salary_cents: number }[];
  customer_id?: string | null;
  route?: string | null;
  remarks?: string | null;
}

export interface TruckPnl {
  truck: Pick<Truck, 'id' | 'label' | 'plate'>;
  trip_income_cents: number;
  fuel_cents: number;
  fuel_log_cents: number;
  driver_salary_cents: number;
  helper_salary_cents: number;
  maintenance_cents: number;
  allowance_cents: number;
  owner_share_cents: number;
  other_expenses_cents: number;
  total_expenses_cents: number;
  net_income_cents: number;
  net_share: number;
  entry_count: number;
}

export interface PeriodTotals {
  trip_income_cents: number;
  fuel_cents: number;
  fuel_log_cents: number;
  driver_salary_cents: number;
  helper_salary_cents: number;
  maintenance_cents: number;
  allowance_cents: number;
  owner_share_cents: number;
  other_expenses_cents: number;
  payroll_cents: number;
  overhead_cents: number;
  supplier_bills_cents: number;

  trucker_payouts_cents: number;

  trucker_commission_cents: number;
  other_income_cents: number;
  total_income_cents: number;
  vat_collected_cents: number;
  held_for_partners_cents: number;
  payables_already_costed_cents: number;
  total_expenses_cents: number;
  net_income_cents: number;

  payables_cents: number;

  actual_income_cents: number;

  receivables_cents: number;

  margin: number | null;
}

export interface DateRange {
  from: string;
  to: string;
}

export type QuarterKey = 'q1' | 'q2' | 'q3' | 'q4';

export interface Quarter {
  key: QuarterKey;
  label: string;
  from: string;
  to: string;
}

export interface PeriodRollup {
  range: DateRange;
  trucks: TruckPnl[];
  totals: PeriodTotals;
  average_profit_per_truck: { cents: number; trucks: number };
  best_performer: TruckPnl | null;
  currency: string;
}

export type ExpenseLineSource =
  | 'sheet'
  | 'expense'
  | 'fuel_log'
  | 'supplier_bill'
  | 'payroll'
  | 'trucker_payout';

export interface ExpenseLine {
  key: string;
  source: ExpenseLineSource;
  date: string;
  kind: string;
  description: string | null;
  truck: string | null;
  amount_cents: number;
  record_id: string | null;
}

export interface ExpenseLines {
  range: DateRange;
  lines: ExpenseLine[];
  total_cents: number;
  currency: string;
}

export interface ReceivableLine {
  key: string;
  source: 'invoice' | 'trucker';
  reference: string | null;
  counterparty: string;
  issued_at: string | null;
  due_at: string | null;
  overdue: boolean;
  amount_cents: number;
  record_id: string;
}

export interface ReceivableLines {
  as_of: string;
  lines: ReceivableLine[];
  total_cents: number;
  currency: string;
}

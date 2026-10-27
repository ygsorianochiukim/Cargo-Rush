import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

export type PaySchedule = 'monthly' | 'cutoff' | 'on_receipt';

export const PAY_SCHEDULES: { value: PaySchedule; label: string }[] = [
  { value: 'on_receipt', label: 'Cash on receipt' },
  { value: 'cutoff', label: 'Every cutoff' },
  { value: 'monthly', label: 'Monthly' },
];

export interface ExpenseCategory extends Timestamped {
  id: string;
  key: string;
  name: string;
  description: string | null;
  icon: string | null;
  position: number;
  status: StatusValue;
}

export interface Expense extends Timestamped {
  id: string;
  category_id: string;
  category_name: string | null;
  category_key: string | null;
  category_icon: string | null;
  truck_id: string | null;
  truck_label: string | null;
  trip_id: string | null;
  trip_reference: string | null;
  asset_id: string | null;
  asset_tag: string | null;
  asset_name: string | null;
  vehicle_id: string | null;
  driver_id: string | null;
  supplier_id: string | null;
  supplier_name?: string | null;
  payment_source_id: string | null;
  payment_source_label: string | null;
  payment_source_kind: string | null;
  pay_schedule: PaySchedule;
  pay_schedule_label: string;
  driver_name: string | null;
  ledger_entry_id: string | null;
  date: string;
  amount_cents: number;
  currency: string;
  is_vatable: boolean;
  vat_cents: number;
  payee: string | null;
  reference: string | null;
  note: string | null;
  status: StatusValue;
}

export interface ExpensePayload {
  category_id: string;
  trip_id?: string | null;
  supplier_id?: string | null;
  payment_source_id?: string | null;
  pay_schedule?: PaySchedule;
  date: string;
  amount_cents: number;
  currency?: string;
  is_vatable?: boolean;
  payee?: string | null;
  reference?: string | null;
  note?: string | null;
  status?: StatusValue;
}

export interface CategoryTotal {
  category: { id: string; key: string; name: string; icon: string | null };
  amount_cents: number;
  entry_count: number;
}

export interface ExpenseReport {
  range: { from: string; to: string };
  categories: CategoryTotal[];
  total_cents: number;
  overhead_cents: number;
  attributed_cents: number;
  vatable_cents: number;
  non_vatable_cents: number;
  input_vat_cents: number;
  vat_rate_bp: number;
  by_schedule: { schedule: PaySchedule; label: string; amount_cents: number }[];
  entry_count: number;
  currency: string;
}

export interface ExpenseCategoryPayload {
  name: string;
  description?: string | null;
  icon?: string | null;
  status?: StatusValue;
}

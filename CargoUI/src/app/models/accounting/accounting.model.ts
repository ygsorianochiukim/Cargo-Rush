import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

export type AccountType = 'asset' | 'liability' | 'equity' | 'income' | 'expense';

export type BalanceSide = 'debit' | 'credit';

export type JournalStatus = 'draft' | 'posted' | 'void';

export interface Account extends Timestamped {
  id: string;
  code: string;
  name: string;
  label: string;
  type: AccountType;
  type_label: string;
  normal_balance: BalanceSide;
  permanent: boolean;
  group: string | null;
  description: string | null;
  is_system: boolean;
  position: number;
  status: StatusValue;
}

export interface AccountPayload {
  code: string;
  name: string;
  type: AccountType;
  group?: string | null;
  description?: string | null;
  position?: number;
  status?: StatusValue;
}

export interface AccountTypeOption {
  value: AccountType;
  label: string;
  normal_balance: BalanceSide;
  permanent: boolean;
  position: number;
}

export interface JournalLine {
  id: string;
  line_no: number;
  account_id: string;
  account_code: string | null;
  account_name: string | null;
  account_type: AccountType | null;
  side: BalanceSide;
  debit_cents: number;
  credit_cents: number;
  amount_cents: number;
  memo: string | null;
  truck_id: string | null;
  trip_id: string | null;
  customer_id: string | null;
}

export interface JournalEntry extends Timestamped {
  id: string;
  reference: string;
  entry_date: string;

  category: string;
  category_label: string;
  category_icon: string;

  memo: string;

  status: JournalStatus;

  debit_cents: number;
  credit_cents: number;
  balanced: boolean;
  currency: string;

  source: string;
  source_type: string | null;
  source_id: string | null;
  automatic: boolean;
  source_label: string | null;

  posted_at: string | null;
  posted_by_name: string | null;
  voided_at: string | null;
  void_reason: string | null;

  can_edit: boolean;
  can_post: boolean;
  can_void: boolean;

  lines: JournalLine[];
}

export interface JournalLinePayload {
  account_id: string;
  side: BalanceSide;
  amount_cents: number;
  memo?: string | null;
  truck_id?: string | null;
  trip_id?: string | null;
  customer_id?: string | null;
}

export interface JournalEntryPayload {
  entry_date: string;
  category: string;
  memo: string;
  lines: JournalLinePayload[];
  status?: 'draft' | 'posted';
}

export interface JournalCategoryOption {
  value: string;
  label: string;
  icon: string;
}

export interface LedgerLine {
  line_id: string;
  entry_id: string;
  reference: string | null;
  entry_date: string | null;
  category: string | null;
  memo: string | null;
  debit_cents: number;
  credit_cents: number;
  balance_cents: number;
  truck_id: string | null;
  trip_id: string | null;
  customer_id: string | null;
}

export interface LedgerPage {
  account: {
    id: string;
    code: string;
    name: string;
    type: AccountType;
    type_label: string;
    group: string | null;
    normal_balance: BalanceSide;
  };
  range: { from: string | null; to: string | null };
  opening_balance_cents: number;
  debit_cents: number;
  credit_cents: number;
  movement_cents: number;
  closing_balance_cents: number;
  entry_count: number;
  lines: LedgerLine[];
  currency: string;
}

export interface LedgerSummaryAccount {
  id: string;
  code: string;
  name: string;
  group: string | null;
  status: StatusValue;
  debit_cents: number;
  credit_cents: number;
  balance_cents: number;
}

export interface LedgerSummaryType {
  type: AccountType;
  label: string;
  normal_balance: BalanceSide;
  accounts: LedgerSummaryAccount[];
  debit_cents: number;
  credit_cents: number;
  balance_cents: number;
}

export interface LedgerSummary {
  range: { from: string | null; to: string | null };
  types: LedgerSummaryType[];
  currency: string;
}

export interface TrialBalanceRow {
  account_id: string;
  code: string;
  name: string;
  type: AccountType;
  type_label: string;
  group: string | null;
  debit_cents: number;
  credit_cents: number;
  balance_cents: number;
}

export interface TrialBalance {
  as_of: string;
  rows: TrialBalanceRow[];
  debit_total_cents: number;
  credit_total_cents: number;
  balanced: boolean;
  difference_cents: number;
  currency: string;
}

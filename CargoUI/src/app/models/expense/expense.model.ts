import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/**
 * Other Expenses — the categorised spend the workbook's five columns had no
 * place for. Money is integer centavos (DESIGN.md section 7.1).
 *
 * Note what "additive" means here: a line and a ledger column are not
 * reconciled against each other. Logging a fill as a Fuel line *and* keying it
 * into the day's `fuel_cents` counts it twice, because nothing can tell that
 * apart from a second fill on the same day.
 */
export interface ExpenseCategory extends Timestamped {
  id: string;
  /** The stable handle. Renaming the category keeps this, so its rows survive. */
  key: string;
  name: string;
  description: string | null;
  /** An icon name from the shared set, never a colour. */
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
  /** Null is fleet overhead — real spend belonging to no single unit. */
  truck_id: string | null;
  truck_label: string | null;
  trip_id: string | null;
  vehicle_id: string | null;
  driver_id: string | null;
  driver_name: string | null;
  /** The day sheet this attached itself to, where it names a truck. */
  ledger_entry_id: string | null;
  /** ISO date, no time — it rolls into a daily ledger. */
  date: string;
  amount_cents: number;
  currency: string;
  payee: string | null;
  reference: string | null;
  note: string | null;
  /** Only `active` counts as spend; pending is unapproved, cancelled refused. */
  status: StatusValue;
}

export interface ExpensePayload {
  category_id: string;
  truck_id?: string | null;
  trip_id?: string | null;
  driver_id?: string | null;
  date: string;
  amount_cents: number;
  currency?: string;
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

/** Where the money went over a window. */
export interface ExpenseReport {
  range: { from: string; to: string };
  /** Biggest first, and categories with no spend in the window are omitted. */
  categories: CategoryTotal[];
  total_cents: number;
  /** Spend belonging to no truck. Counts against the period, against no unit. */
  overhead_cents: number;
  attributed_cents: number;
  entry_count: number;
  currency: string;
}

export interface ExpenseCategoryPayload {
  name: string;
  description?: string | null;
  icon?: string | null;
  status?: StatusValue;
}

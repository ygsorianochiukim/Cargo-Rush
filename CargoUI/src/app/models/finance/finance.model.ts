import { Timestamped } from '../shared/envelope.model';

/**
 * The money side — the shapes behind "v3 Cargorush Master Dashboard 2026.xlsx".
 *
 * The workbook keeps one sheet per truck with a row per date and derives
 * everything else from those rows:
 *
 *   total_expenses = fuel + driver_salary + helper_salary + maintenance + allowance
 *   net_income     = trip_income - total_expenses
 *
 * Money is integer centavos (DESIGN.md section 7.1) — the workbook's
 * PHP 30,721.00 is 3_072_100. Formatting to pesos happens in the view only.
 */

export interface Truck {
  id: string;
  /** "Truck 1" — the workbook's TRUCK NO. */
  label: string;
  /** "MAR1390" — the UNIT NO. Null for a unit with no plate yet. */
  plate: string | null;
  vehicle_id: string | null;
  position: number;
}

/** One day of trip income and expenses for one truck. */
export interface LedgerEntry extends Timestamped {
  id: string;
  truck_id: string;
  truck_label: string | null;
  truck_plate: string | null;
  /** ISO date, no time — the ledger is daily. */
  date: string;
  trip_income_cents: number;
  fuel_cents: number;
  driver_salary_cents: number;
  helper_salary_cents: number;
  maintenance_cents: number;
  allowance_cents: number;
  /**
   * What a hired truck's owner took out of the day's runs. Posted by a
   * delivery on a revenue-share unit, never typed; zero on the fleet's own.
   */
  owner_share_cents: number;
  /** Both derived by the API from the expense columns above, owner share included. */
  total_expenses_cents: number;
  net_income_cents: number;
  currency: string;
  route: string | null;
  remarks: string | null;
  /**
   * The trip whose delivery opened this row, and its reference — the only
   * trip identity a human reads. Both null for a row the office entered by
   * hand. A day can cover several runs; this names the one that opened it.
   */
  trip_id: string | null;
  trip_reference: string | null;
  /**
   * Whose work the day was, when it was one customer's — this is what puts
   * the money on their history. Null is ordinary: a row covers a truck for a
   * day, which can be several customers' work or the company's own freight.
   */
  customer_id: string | null;
  customer: string | null;

  /**
   * Who the day's driver and helper salary belonged to.
   *
   * The sheet has recorded *what* the crew was paid since the workbook was
   * first modelled and never *whose*, which was survivable while the figure
   * only fed Profitability — and stops being so the moment somebody is paid
   * from it. Payroll sums these rows for anybody on a per-trip or daily basis.
   *
   * Null is ordinary and means nobody said. An unattributed row is counted
   * toward nobody's payslip, which is the safe direction: the failure is a
   * figure somebody notices missing rather than one quietly paid to the wrong
   * person.
   */
  driver_id: string | null;
  driver_name: string | null;

  /**
   * The day's helpers, each with their own pay — any number of them.
   *
   * `helper_salary_cents` above is the sum of these salaries, kept in step by
   * the API. A line with no `driver_id` is pay entered without saying whose,
   * counted toward nobody's payslip.
   */
  helpers: LedgerHelperLine[];
}

/** One helper on a day's sheet, and what they were paid. */
export interface LedgerHelperLine {
  driver_id: string | null;
  name: string | null;
  salary_cents: number;
}

/** What an entry form sends. Neither derived figure is accepted. */
export interface LedgerEntryPayload {
  truck_id: string;
  date: string;
  trip_income_cents: number;
  fuel_cents: number;
  driver_salary_cents: number;
  /**
   * The sum of `helpers` below. The API goes by the lines whenever they are
   * sent and derives this itself; the form sends it anyway so the live preview
   * and the payload are one shape for `totalExpenses()` to read.
   */
  helper_salary_cents: number;
  maintenance_cents: number;
  allowance_cents: number;
  /** Whose the driver salary above is. See `LedgerEntry`. */
  driver_id?: string | null;
  /** Each helper and their pay. Replaces the day's lines when sent. */
  helpers?: { driver_id: string | null; salary_cents: number }[];
  customer_id?: string | null;
  route?: string | null;
  remarks?: string | null;
}

/** A truck's rolled-up figures for a period. */
export interface TruckPnl {
  truck: Pick<Truck, 'id' | 'label' | 'plate'>;
  trip_income_cents: number;
  /**
   * The unit's whole fuel: the sheet's column, which holds every fill posted
   * from Fuel monitoring, plus any fill no row carries yet.
   */
  fuel_cents: number;
  /** How much of that came from Fuel monitoring. Already inside `fuel_cents`. */
  fuel_log_cents: number;
  driver_salary_cents: number;
  helper_salary_cents: number;
  maintenance_cents: number;
  allowance_cents: number;
  /** A hired truck's owner's cut — a cost of running somebody else's unit. */
  owner_share_cents: number;
  /** Categorised expense lines, which sit beside the sheet's columns. */
  other_expenses_cents: number;
  /**
   * fuel + driver + helper + maintenance + allowance + owner share + other
   * expenses. The row's columns add up to this exactly.
   */
  total_expenses_cents: number;
  net_income_cents: number;
  /** Share of the period's total net income. Negative for a loss-maker. */
  net_share: number;
  entry_count: number;
}

export interface PeriodTotals {
  trip_income_cents: number;
  fuel_cents: number;
  /**
   * Fills logged in Fuel monitoring against a truck. Inside `fuel_cents`; a
   * fill on a vehicle no truck points at is in `overhead_cents` instead.
   */
  fuel_log_cents: number;
  driver_salary_cents: number;
  helper_salary_cents: number;
  maintenance_cents: number;
  allowance_cents: number;
  owner_share_cents: number;
  other_expenses_cents: number;
  /**
   * Pay runs paid in the period, beyond the crew pay the sheet's salary
   * columns already hold — so no peso of a driver's pay is counted twice.
   * Inside `total_expenses_cents` and in no truck row.
   */
  payroll_cents: number;
  /**
   * Counted spend belonging to the period but to no truck — office rent, an
   * annual permit, and fuel fills for a vehicle no truck points at. It is
   * inside `total_expenses_cents` and inside no truck row, which is why the
   * truck rows do not add up to the total on their own.
   */
  overhead_cents: number;
  /**
   * Bills from suppliers, at what was actually paid out over the period.
   *
   * Counted on the day the payment was made rather than the day the bill was
   * raised, and a bill nobody has paid counts nothing — so this is the money
   * that really left the bank, and it is why a period's net income is lower
   * than the trucks alone would suggest. Inside `total_expenses_cents` and
   * inside no truck row, like the overhead above.
   */
  supplier_bills_cents: number;

  /**
   * Partners' shares handed over and landed over the period.
   *
   * **Not an expense.** On a ₱5,000 partner run the fleet keeps its ₱600 and
   * the ₱4,400 was the partner's, passing through — so the fleet's income is
   * only its commission (`trucker_commission_cents`), and handing the rest on
   * costs it nothing. Shown so the money that moved is visible.
   */
  trucker_payouts_cents: number;

  /**
   * The fleet's commission on partners' runs delivered in the period — its
   * whole income from them. Inside `total_income_cents` and in no truck row.
   */
  trucker_commission_cents: number;
  /**
   * Receivable invoices raised by hand — no trip behind them — at their net.
   * Inside `total_income_cents` and in no truck row.
   */
  other_income_cents: number;
  /** Company trucks' takings, the commission above and the other income. */
  total_income_cents: number;
  /**
   * VAT charged to customers on the period's invoices. The government's, not
   * income — shown beside it, never inside it.
   */
  vat_collected_cents: number;
  /**
   * Of `payables_cents`, partners' shares the fleet is holding for them. Owed,
   * but never the fleet's, so `actual_income_cents` does not take it off.
   */
  held_for_partners_cents: number;
  /**
   * Of `payables_cents`, what the expenses already carry — a hired truck
   * owner's cut, payroll withholdings, a serviced truck's unpaid garage bill.
   * Owed, but charged once already, so not taken off again.
   */
  payables_already_costed_cents: number;
  total_expenses_cents: number;
  net_income_cents: number;

  /**
   * Everything still owed as the period closed — partner wallets, unsettled
   * spend, unpaid supplier bills. The Payables screen's question, asked about
   * the end of this window rather than about today.
   *
   * **Not** inside `total_expenses_cents` and not inside `net_income_cents`: an
   * unpaid bill has cost the period nothing, and counting it as an expense
   * would charge the quarter for money that has not moved and charge it again
   * the day it does.
   */
  payables_cents: number;

  /**
   * `net_income_cents - (payables_cents - held_for_partners_cents -
   * payables_already_costed_cents)` — what is left once what is owed is
   * settled, and the figure to look at before deciding anything can be drawn
   * out. The API does the subtraction so no screen can do it differently.
   */
  actual_income_cents: number;

  /**
   * What customers — and partners whose wallet is in the red — still owed the
   * fleet at the close. It moves no other figure. The fleet's part of it is
   * already income; a partner's share inside a brokered invoice and the VAT
   * never were the fleet's, so collecting them adds nothing to income.
   */
  receivables_cents: number;

  /** net / income, or null when there was no income to divide by. */
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

/** What `GET /api/v1/finance/profitability` and `/summary` both return. */
export interface PeriodRollup {
  range: DateRange;
  trucks: TruckPnl[];
  totals: PeriodTotals;
  average_profit_per_truck: { cents: number; trucks: number };
  /** Null when nobody is in profit, which really does happen. */
  best_performer: TruckPnl | null;
  currency: string;
}

/** Where a row behind Total expenses came from — and so which screen opens it. */
export type ExpenseLineSource =
  | 'sheet'
  | 'expense'
  | 'fuel_log'
  | 'supplier_bill'
  | 'payroll'
  | 'trucker_payout';

/** One transaction inside a period's Total expenses. */
export interface ExpenseLine {
  key: string;
  source: ExpenseLineSource;
  date: string;
  /** "Fuel", a category name, "Supplier bill", "Trucker payout". */
  kind: string;
  description: string | null;
  /** Plate or label. Null for overhead, bills and payouts, which are no truck's. */
  truck: string | null;
  amount_cents: number;
  /** The sheet day, the expense, the bill, or the partner. */
  record_id: string | null;
}

/** What `GET /api/v1/finance/expense-lines` returns. Sums to the tile's total. */
export interface ExpenseLines {
  range: DateRange;
  lines: ExpenseLine[];
  total_cents: number;
  currency: string;
}

/** One debt owed to the fleet at a date. */
export interface ReceivableLine {
  key: string;
  /** A customer's invoice, or a partner whose wallet had gone into the red. */
  source: 'invoice' | 'trucker';
  reference: string | null;
  counterparty: string;
  issued_at: string | null;
  due_at: string | null;
  /** Past its due date as at the date asked about, not as at today. */
  overdue: boolean;
  /** What was still due then: VAT on, withholding off, payments by then off. */
  amount_cents: number;
  /** The invoice, or the partner. */
  record_id: string;
}

/** What `GET /api/v1/finance/receivable-lines` returns. Sums to the tile. */
export interface ReceivableLines {
  as_of: string;
  lines: ReceivableLine[];
  total_cents: number;
  currency: string;
}

import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/**
 * The books: a chart of accounts, a general journal, a general ledger.
 *
 * Money is integer centavos throughout (DESIGN.md section 7.1) — a peso figure
 * never crosses the wire as a formatted string, and no arithmetic in this app
 * happens in floats.
 *
 * The one thing worth knowing before reading these types: **a journal entry
 * has no amount.** It has lines, each on one side, and the two sides are equal.
 * `debit_cents` and `credit_cents` on an entry are the totals of its lines,
 * computed by the API, and they are always the same figure on anything that has
 * been posted.
 */

/**
 * The five kinds of account.
 *
 * Not decoration: the type decides which side increases the account, which
 * statement it appears on, and which column of a trial balance it lands in. The
 * API sends `normal_balance` alongside so this app never keeps its own copy of
 * that rule.
 */
export type AccountType = 'asset' | 'liability' | 'equity' | 'income' | 'expense';

/** Debit or credit. Every line is on exactly one. */
export type BalanceSide = 'debit' | 'credit';

/** draft → posted → (void). See `JournalEntry`. */
export type JournalStatus = 'draft' | 'posted' | 'void';

export interface Account extends Timestamped {
  id: string;
  /** The number the office quotes — 1010, 4000, 5300. */
  code: string;
  name: string;
  /** `1010 · Cash on hand`, ready for a picker. */
  label: string;
  type: AccountType;
  type_label: string;
  /**
   * Which side increases this account.
   *
   * Sent rather than derived here, for the same reason a status colour is: the
   * client should not be the second place that knows an expense grows on the
   * debit side.
   */
  normal_balance: BalanceSide;
  /** True for the balance-sheet three; false for income and expense. */
  permanent: boolean;
  /** A sub-heading within the type — "Cost of services". The office's to set. */
  group: string | null;
  description: string | null;
  /**
   * Seeded with the install, and not deletable.
   *
   * The page greys the delete action rather than offering one that comes back
   * with a sentence about why not.
   */
  is_system: boolean;
  position: number;
  /** `active`, or `inactive` for one retired — it keeps its history either way. */
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

/** One of the five types, as the form offers it. */
export interface AccountTypeOption {
  value: AccountType;
  label: string;
  normal_balance: BalanceSide;
  permanent: boolean;
  position: number;
}

/** One side of one transaction. */
export interface JournalLine {
  id: string;
  line_no: number;
  account_id: string;
  account_code: string | null;
  account_name: string | null;
  account_type: AccountType | null;
  side: BalanceSide;
  /** One of these two is always zero — a line is on one side. */
  debit_cents: number;
  credit_cents: number;
  /** Whichever side it is on, as a positive figure. */
  amount_cents: number;
  memo: string | null;
  truck_id: string | null;
  trip_id: string | null;
  customer_id: string | null;
}

export interface JournalEntry extends Timestamped {
  id: string;
  /** `JV-0004`, from the API's own series. Never chosen by a client. */
  reference: string;
  /** A date with no time: the day the transaction belongs to. */
  entry_date: string;

  /**
   * The accounting category — what kind of transaction this is.
   *
   * Not a summary of the accounts it touches: one ordinary entry touches two
   * types at once. This is the answer to "why was this written", which is the
   * question somebody scrolling a month of entries is actually asking, and it
   * is what the journal filters and subtotals by.
   */
  category: string;
  category_label: string;
  /** An icon name from the shared set, never a colour. */
  category_icon: string;

  memo: string;

  /**
   * `draft` is somebody's work in progress and counts towards nothing.
   * `posted` is in the books and cannot be changed. `void` is a posted entry
   * withdrawn — kept, explained, and excluded from every balance.
   */
  status: JournalStatus;

  /** The two totals of its lines, and whether they agree. */
  debit_cents: number;
  credit_cents: number;
  balanced: boolean;
  currency: string;

  /** `manual`, or the document that posted itself. */
  source: string;
  source_type: string | null;
  source_id: string | null;
  /**
   * Posted by a record — a day on the sheet, an invoice, a payment — and kept
   * true by it. Correct the record, not the entry: a change to it voids this
   * and posts the replacement.
   */
  automatic: boolean;
  /** What kind of record posted it ("Daily sheet", "Invoice"), or null for a manual one. */
  source_label: string | null;

  posted_at: string | null;
  posted_by_name: string | null;
  voided_at: string | null;
  void_reason: string | null;

  /**
   * What this page may offer.
   *
   * From the API rather than worked out from `status` here: the rules are the
   * API's, and a button that only ever returns a 422 is worse than no button.
   */
  can_edit: boolean;
  can_post: boolean;
  can_void: boolean;

  lines: JournalLine[];
}

/** One side, as the form sends it: a side and an amount, never two columns. */
export interface JournalLinePayload {
  account_id: string;
  side: BalanceSide;
  /** Positive centavos. The direction is `side`, never a minus sign. */
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
  /**
   * At least two, and they must balance — the API refuses anything else, and
   * says by how much it is out.
   */
  lines: JournalLinePayload[];
  /** Omitted means a draft. `posted` puts it straight in the books. */
  status?: 'draft' | 'posted';
}

/** One of the categories a form offers, from the API's own list. */
export interface JournalCategoryOption {
  value: string;
  label: string;
  icon: string;
}

/** One posting on an account's ledger page, with the balance after it. */
export interface LedgerLine {
  line_id: string;
  entry_id: string;
  reference: string | null;
  entry_date: string | null;
  category: string | null;
  memo: string | null;
  debit_cents: number;
  credit_cents: number;
  /** The running balance *after* this line, in the account's own direction. */
  balance_cents: number;
  truck_id: string | null;
  trip_id: string | null;
  customer_id: string | null;
}

/**
 * One account's ledger for a period.
 *
 * `opening_balance_cents` is where the account stood before the range — always
 * zero for income and expense, which start again each period.
 */
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
  /** In the account's own direction: positive is more of what it is. */
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

/** The chart with balances against it — where the ledger page opens. */
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

/**
 * Debits against credits. The check that the books are whole.
 *
 * `balanced` is the whole report: every entry balances, so the two columns
 * must. A false here means something wrote to the postings without going
 * through the journal, and the difference says how much.
 */
export interface TrialBalance {
  as_of: string;
  rows: TrialBalanceRow[];
  debit_total_cents: number;
  credit_total_cents: number;
  balanced: boolean;
  difference_cents: number;
  currency: string;
}

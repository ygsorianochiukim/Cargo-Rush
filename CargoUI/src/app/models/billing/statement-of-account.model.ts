import { InvoiceDirection } from './billing.model';

/**
 * A statement of account: what one firm owed, what moved, and what is left.
 *
 * Not a list of invoices — the billing page is already that. A **running
 * account**: an opening balance, every document and every payment in date
 * order, and a closing balance that follows from them line by line.
 *
 * The running balance is the point. An aging report says ₱48,000 is thirty days
 * late; a statement says *how it got there*. When a customer disputes a figure
 * the disagreement is always about one line, and this is the only view that
 * puts both sides' lines in the same order so the argument can be about a fact
 * rather than a total.
 */

/**
 * One line: a document raised, money that moved against one, or the tax the
 * customer withheld from it.
 *
 * `withholding` is a credit dated with its invoice: the customer pays the due
 * (gross less withholding) and remits the rest to the BIR on the fleet's
 * behalf, so without this line the account would never close to zero.
 */
export interface StatementLine {
  date: string | null;
  kind: 'invoice' | 'payment' | 'withholding';
  /** The invoice number, or the payment's reference. */
  reference: string;
  detail: string;
  invoice_id: string | null;
  due_at: string | null;
  /** One of these two is always zero. */
  charge_cents: number;
  credit_cents: number;
  /** The account's balance after this line. */
  balance_cents: number;
}

/**
 * The closing balance, split by how late each document is.
 *
 * Measured per document from its own due date, because "60 days" means sixty
 * days past due and not sixty days since it was raised.
 */
export interface StatementAging {
  current: number;
  days_1_30: number;
  days_31_60: number;
  days_61_90: number;
  over_90: number;
  total_cents: number;
}

export interface StatementOfAccount {
  /** Who is sending it — a statement that leaves the building needs this. */
  issuer: {
    name: string | null;
    tin: string | null;
    address: string | null;
    contact_phone: string | null;
    contact_email: string | null;
    logo_url: string | null;
  };

  account: {
    id: string;
    name: string;
    contact: string | null;
    address: string | null;
    tin: string | null;
  };

  direction: InvoiceDirection;
  /**
   * What to print at the top, and which way the balance reads.
   *
   * Sent rather than decided here: a receivable statement is money we are
   * owed and a payable one is money we owe, and the figure at the bottom is
   * the same number with the opposite meaning. A supplier statement that said
   * "amount due from" would be read the wrong way round by whoever pays it.
   */
  title: string;
  balance_label: string;

  range: { from: string | null; to: string };

  /**
   * Everything before the range, netted.
   *
   * Without it a statement for March would open at zero and close short by
   * whatever February left owing — the error that makes a statement worth less
   * than the invoices it was built from.
   */
  opening_balance_cents: number;
  charges_cents: number;
  credits_cents: number;
  closing_balance_cents: number;
  /** Spelled out, as a cheque is. */
  closing_in_words: string;

  lines: StatementLine[];
  aging: StatementAging;

  currency: string;
}

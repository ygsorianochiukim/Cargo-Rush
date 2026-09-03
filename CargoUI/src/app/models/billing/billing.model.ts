import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/** `receivable` is money in, `payable` is money out. */
export type InvoiceDirection = 'receivable' | 'payable';

/** Billing & Invoice — DESIGN.md section 5.1. */
export interface Invoice extends Timestamped {
  id: string;
  number: string;
  /** Whoever the document is addressed to, whichever way it points. */
  customer: string;
  customer_id: string | null;
  payee: string | null;
  issued_at: string;
  due_at: string;
  amount_cents: number;
  currency: string;
  direction: InvoiceDirection;
  status: StatusValue;
  /** The haul this document is for, when a delivery raised it. */
  trip_id: string | null;
  trip_reference: string | null;
  /** When the money arrived. Null until it is settled. */
  paid_at: string | null;
}

export interface InvoicePayload {
  number: string;
  customer_id?: string | null;
  payee?: string | null;
  issued_at: string;
  due_at: string;
  amount_cents: number;
  currency?: string;
  direction: InvoiceDirection;
  status?: StatusValue;
}

/** Receivables against payables — the two numbers the page leads with. */
export interface BillingTotals {
  receivable_cents: number;
  payable_cents: number;
  /** Positive means the business is owed more than it owes. */
  net_position_cents: number;
  /** Money in, as against money merely billed. */
  collected_cents: number;
  currency: string;
}

import { InvoiceDirection } from './billing.model';

export interface StatementLine {
  date: string | null;
  kind: 'invoice' | 'payment' | 'withholding';
  reference: string;
  detail: string;
  invoice_id: string | null;
  due_at: string | null;
  charge_cents: number;
  credit_cents: number;
  balance_cents: number;
}

export interface StatementAging {
  current: number;
  days_1_30: number;
  days_31_60: number;
  days_61_90: number;
  over_90: number;
  total_cents: number;
}

export interface StatementOfAccount {
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
  title: string;
  balance_label: string;

  range: { from: string | null; to: string };

  opening_balance_cents: number;
  charges_cents: number;
  credits_cents: number;
  closing_balance_cents: number;
  closing_in_words: string;

  lines: StatementLine[];
  aging: StatementAging;

  currency: string;
}

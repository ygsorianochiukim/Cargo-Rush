export interface InvoiceIssuer {
  name: string | null;
  code: string | null;
  tin: string | null;
  vat_registered: boolean;
  address: string | null;
  contact_name: string | null;
  contact_phone: string | null;
  contact_email: string | null;
  logo_url: string | null;
}

export interface InvoiceBillTo {
  name: string;
  customer_id: string | null;
  address: string | null;
  contact: string | null;
  tin: string | null;
  vat_treatment: string | null;
  vat_treatment_label: string | null;
  withholds_tax: boolean;
}

export interface InvoiceDocumentHead {
  id: string;
  number: string;
  title: string;
  direction: string;
  issued_at: string | null;
  due_at: string | null;
  terms_days: number | null;
  status: string;
  overdue: boolean;
  currency: string;
}

export interface InvoiceDocumentLine {
  description: string;
  reference: string | null;
  origin: string | null;
  destination: string | null;
  cargo: string | null;
  weight_kg: number | null;
  delivered_at: string | null;
  driver: string | null;
  plate: string | null;
  amount_cents: number;
}

export interface InvoiceDocumentTotals {
  net_amount_cents: number;
  vat_cents: number;
  amount_cents: number;
  withholding_cents: number;
  due_cents: number;
  paid_cents: number;
  balance_cents: number;
  vat_rate_bp: number | null;
  withholding_rate_bp: number | null;
  vat_treatment: string | null;
  vat_label: string | null;
  amount_in_words: string;
}

export interface InvoiceDocumentPayment {
  id: string | null;
  paid_on: string | null;
  method: string | null;
  method_label: string | null;
  reference: string | null;
  notes: string | null;
  recorded_by: string | null;
  amount_cents: number;
}

export interface InvoiceDocument {
  issuer: InvoiceIssuer;
  bill_to: InvoiceBillTo;
  document: InvoiceDocumentHead;
  lines: InvoiceDocumentLine[];
  totals: InvoiceDocumentTotals;
  payments: InvoiceDocumentPayment[];
}

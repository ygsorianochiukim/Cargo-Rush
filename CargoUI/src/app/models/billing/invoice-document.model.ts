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
  title_key: InvoiceTitleKey;
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
  template: InvoiceTemplate;
  issuer: InvoiceIssuer;
  bill_to: InvoiceBillTo;
  document: InvoiceDocumentHead;
  lines: InvoiceDocumentLine[];
  totals: InvoiceDocumentTotals;
  payments: InvoiceDocumentPayment[];
}

export type InvoiceTitleKey = 'sales' | 'delivery' | 'bill';

export type InvoiceLabelKey =
  | 'billed_to'
  | 'issued'
  | 'due'
  | 'terms'
  | 'currency'
  | 'lines_heading'
  | 'col_description'
  | 'col_reference'
  | 'col_delivered'
  | 'col_weight'
  | 'col_amount'
  | 'amount_in_words'
  | 'net_of_vat'
  | 'vat'
  | 'invoice_total'
  | 'amount_payable'
  | 'received'
  | 'balance_due'
  | 'payments_heading'
  | 'issued_by'
  | 'received_by'
  | 'received_by_hint';

export type InvoiceShowKey =
  | 'logo'
  | 'address'
  | 'contact'
  | 'tin'
  | 'status'
  | 'terms'
  | 'currency'
  | 'reference'
  | 'delivered'
  | 'weight'
  | 'unit_driver'
  | 'amount_in_words'
  | 'payments'
  | 'signatures'
  | 'footer';

export interface InvoiceTemplate {
  accent: string;
  titles: Record<InvoiceTitleKey, string>;
  labels: Record<InvoiceLabelKey, string>;
  show: Record<InvoiceShowKey, boolean>;
  fields: { label: string; value: string }[];
  blocks: { title: string; body: string }[];
  footer: string | null;
}

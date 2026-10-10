import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

export type InvoiceDirection = 'receivable' | 'payable';

export interface Invoice extends Timestamped {
  id: string;
  number: string;
  customer: string;
  customer_id: string | null;
  payee: string | null;
  issued_at: string;
  due_at: string;

  net_amount_cents: number;
  vat_cents: number;
  amount_cents: number;
  withholding_cents: number;
  due_cents: number;

  taxable_base_cents: number;

  paid_cents: number;
  balance_cents: number;

  vat_rate_bp: number;
  withholding_rate_bp: number;
  vat_treatment: VatTreatment | null;
  vat_label: string | null;

  currency: string;
  direction: InvoiceDirection;
  status: StatusValue;
  trip_id: string | null;
  trip_reference: string | null;
  paid_at: string | null;
}

export type VatTreatment = 'vatable' | 'zero_rated' | 'exempt';

export interface Payment extends Timestamped {
  id: string;
  customer_id: string | null;
  customer_name: string | null;
  direction: InvoiceDirection;
  amount_cents: number;
  currency: string;
  paid_on: string;
  method: string;
  reference: string | null;
  notes: string | null;
  allocated_cents: number;
  unallocated_cents: number;
  allocations?: { invoice_id: string; invoice_number: string | null; amount_cents: number }[];
  recorded_by: string | null;
}

export interface PaymentPayload {
  customer_id?: string | null;
  direction?: InvoiceDirection;
  amount_cents: number;
  paid_on: string;
  method?: string;
  reference?: string | null;
  notes?: string | null;
  allocations?: { invoice_id: string; amount_cents: number }[];
}

export interface AgingBuckets {
  current: number;
  '1_30': number;
  '31_60': number;
  '61_90': number;
  over_90: number;
}

export interface Aging {
  buckets: AgingBuckets;
  total_cents: number;
  by_counterparty: (AgingBuckets & { counterparty: string; total_cents: number })[];
  currency: string;
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

export interface BillingTotals {
  receivable_cents: number;
  payable_cents: number;
  net_position_cents: number;
  collected_cents: number;
  currency: string;
}

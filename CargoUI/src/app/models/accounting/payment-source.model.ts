import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

export type PaymentSourceKind = 'cash' | 'credit_card' | 'fuel_card' | 'bank' | 'e_wallet';

export interface PaymentSource extends Timestamped {
  id: string;
  name: string;
  label: string;
  kind: PaymentSourceKind;
  kind_label: string;
  provider: string | null;
  last_four: string | null;
  note: string | null;
  status: StatusValue;
  expense_spend_cents: number | null;
  fuel_spend_cents: number | null;
  spend_cents: number | null;
}

export interface PaymentSourcePayload {
  name: string;
  kind: PaymentSourceKind;
  provider?: string | null;
  last_four?: string | null;
  note?: string | null;
  status?: StatusValue;
}

export const PAYMENT_SOURCE_KINDS: { value: PaymentSourceKind; label: string }[] = [
  { value: 'cash', label: 'Cash' },
  { value: 'credit_card', label: 'Credit card' },
  { value: 'fuel_card', label: 'Fuel card' },
  { value: 'bank', label: 'Bank account' },
  { value: 'e_wallet', label: 'E-wallet' },
];

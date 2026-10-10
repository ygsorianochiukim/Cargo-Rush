import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';
import { VatTreatment } from '../billing/billing.model';

export interface Customer extends Timestamped {
  id: string;
  name: string;
  contact: string;
  address: string | null;
  latitude: number | null;
  longitude: number | null;
  trips_total: number;
  outstanding_cents: number;
  currency: string;
  rating: number;
  status: StatusValue;
  login_email: string | null;
  default_password: string | null;

  tin: string | null;
  vat_treatment: VatTreatment;
  vat_label: string;
  withholds_tax: boolean;
  withholding_rate_bp: number | null;
}

export interface CustomerPayload {
  name: string;
  contact: string;
  address?: string | null;
  rating?: number;
  status?: StatusValue;
  tin?: string | null;
  vat_treatment?: VatTreatment;
  withholds_tax?: boolean;
  email?: string;
}

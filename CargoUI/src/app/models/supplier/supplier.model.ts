import { StatusValue } from '../shared/status.model';

export interface SupplierCategory {
  id: string;
  name: string;
  status: StatusValue;
  suppliers_count?: number;
}

export interface Supplier {
  id: string;
  name: string;
  category_id: string | null;
  category_name: string | null;
  contact: string | null;
  address: string | null;
  supplies: string | null;
  note: string | null;
  status: StatusValue;

  expense_spend_cents: number | null;
  service_spend_cents: number | null;
  billed_cents: number | null;
  spend_cents: number | null;

  expenses_count?: number;
}

export interface SupplierPayload {
  name: string;
  category_id?: string | null;
  contact?: string | null;
  address?: string | null;
  supplies?: string | null;
  note?: string | null;
  status?: string;
}

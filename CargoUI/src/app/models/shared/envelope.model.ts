export interface Envelope<T> {
  data: T;
  meta?: Record<string, unknown>;
}

export interface ListMeta {
  page: number;
  per_page: number;
  total: number;
}

export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
}

export interface ListQuery {
  page?: number;
  per_page?: number;
  status?: string | string[];
  search?: string;
  from?: string;
  to?: string;
  driver_id?: string;
  vehicle_id?: string;
  customer_id?: string;
  truck_id?: string;
  direction?: string;
  days?: number;
  category_id?: string;
  trip_id?: string;
  supplier_id?: string;
  costed?: boolean | number;
  position?: string;
  department?: string;
  employment_type?: string;
  has_account?: boolean | number;
  stage?: string | string[];
  open?: boolean | number;
  active?: boolean | number;
  granularity?: 'daily' | 'weekly' | 'monthly';
  category?: string;
  account_id?: string;
  as_of?: string;
  month?: string;
}

export interface Timestamped {
  created_at?: string | null;
  updated_at?: string | null;
}

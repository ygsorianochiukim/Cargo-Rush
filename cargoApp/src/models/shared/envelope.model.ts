/**
 * The API envelope — DESIGN.md section 7.1.
 *
 * Identical to the web client's, on purpose: one contract, no mobile-only
 * response shapes (section 5.3).
 */
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

/** Query parameters every list endpoint understands. */
export interface ListQuery {
  page?: number;
  per_page?: number;
  status?: string | string[];
  search?: string;
  from?: string;
  to?: string;
  driver_id?: string;
  vehicle_id?: string;
}

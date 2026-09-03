/**
 * The API envelope — DESIGN.md section 7.1.
 *
 * Every response has this shape, so no service branches on an endpoint-specific
 * one, and no page has to know whether its data came back paged.
 */
export interface Envelope<T> {
  data: T;
  meta?: Record<string, unknown>;
}

/** The `meta` a list carries. */
export interface ListMeta {
  page: number;
  per_page: number;
  total: number;
}

/** The error shape. HTTP status carries the outcome; this carries the words. */
export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
}

/**
 * Query parameters every list endpoint understands. A module ignores the keys
 * it has no column for, so one type serves them all.
 */
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
  /**
   * How many days a computed endpoint should look back over.
   *
   * Not a filter on a column like the rest — the dashboard's receivables
   * roll-up is a window rather than a page, and it is the one read that takes
   * one. It lives here rather than in a second query type because a module
   * ignores the keys it has no use for, which is the whole reason one type
   * serves them all.
   */
  days?: number;
  /** Expense list: narrow to one category, or one trip's spend. */
  category_id?: string;
  trip_id?: string;
  /** Employee roster: the shape of the list rather than a page of it. */
  position?: string;
  department?: string;
  employment_type?: string;
  /** "Who still has no login" — the question the office asks on that screen. */
  has_account?: boolean | number;
  /** Applicants: where in the pipeline, and whether to show only live ones. */
  stage?: string | string[];
  open?: boolean | number;
  /** Expense categories: drop the retired ones a form should not offer. */
  active?: boolean | number;
  /** Sales: which bucket size the roll-up should use. */
  granularity?: 'daily' | 'weekly' | 'monthly';
}

/** Every resource carries these; a module's model extends it. */
export interface Timestamped {
  created_at?: string | null;
  updated_at?: string | null;
}

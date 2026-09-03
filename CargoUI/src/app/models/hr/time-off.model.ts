import { Timestamped } from '../shared/envelope.model';

/**
 * Leave and undertime.
 *
 * Two shapes rather than one with a `kind`, because what they measure differs:
 * leave is days against an entitlement, undertime is hours out of a shift.
 */
export type RequestStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';

export type LeaveTypeValue =
  'vacation' | 'sick' | 'emergency' | 'unpaid' | 'maternity' | 'paternity' | 'bereavement';

/** The fields both requests share, including who decided and when. */
interface Decided extends Timestamped {
  id: string;
  employee_id: string;
  employee_name: string | null;
  employee_no: string | null;
  employee_position: string | null;
  employee_photo_url: string | null;
  reason: string;
  status: RequestStatus;
  status_label: string;
  tone: 'success' | 'info' | 'warning' | 'danger';
  /** Still on somebody's desk. */
  open: boolean;
  decided_by: number | null;
  decided_by_name: string | null;
  decided_at: string | null;
  decision_note: string | null;
}

export interface LeaveRequest extends Decided {
  type: LeaveTypeValue;
  type_label: string;
  paid: boolean;
  starts_on: string;
  ends_on: string;
  /** Derived from the dates by the API, never typed. */
  days: number;
  active_today: boolean;
}

export interface UndertimeRequest extends Decided {
  date: string;
  /** `H:i`, no seconds. */
  from_time: string;
  to_time: string;
  /** Derived from the two times by the API. */
  hours: number;
}

export interface LeavePayload {
  employee_id: string;
  type: LeaveTypeValue;
  starts_on: string;
  ends_on: string;
  reason: string;
}

export interface UndertimePayload {
  employee_id: string;
  date: string;
  from_time: string;
  to_time: string;
  reason: string;
}

export interface TimeOffOverview {
  awaiting_decision: number;
  away_today: number;
  away_employee_ids: string[];
  leave_types: { value: LeaveTypeValue; label: string; paid: boolean }[];
}

/* ------------------------------------------------------------- Performance */

/**
 * Recomputed from the operational record on every call, never stored.
 *
 * `drives` is what stops an office clerk being rendered as the worst driver on
 * the fleet — with no driver record the road figures mean nothing, and the
 * clients show that case differently rather than as a row of zeroes.
 *
 * The rates are null rather than zero when there is nothing to divide by: a
 * green 100% over a month with no deliveries would be a lie.
 */
export interface CrewPerformance {
  employee: {
    id: string;
    employee_no: string;
    name: string;
    position: string;
    photo_url: string | null;
  };
  range: { from: string; to: string };
  drives: boolean;
  trips_assigned: number;
  trips_completed: number;
  trips_cancelled: number;
  trips_on_time: number;
  on_time_rate: number | null;
  completion_rate: number | null;
  distance_km: number;
  revenue_cents: number;
  incidents: number;
  leave_days: number;
  leave_requests: number;
  undertime_hours: number;
  undertime_requests: number;
}

export interface PerformanceTotals {
  crew: number;
  trips_completed: number;
  trips_on_time: number;
  on_time_rate: number | null;
  incidents: number;
  distance_km: number;
  revenue_cents: number;
  leave_days: number;
  undertime_hours: number;
  currency: string;
}

export interface PerformanceReport {
  range: { from: string; to: string };
  crew: CrewPerformance[];
  totals: PerformanceTotals;
}

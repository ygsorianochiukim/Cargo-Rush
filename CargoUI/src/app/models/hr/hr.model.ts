import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/**
 * People — the roster and the hiring pipeline.
 *
 * An employee is the HR record. It is not the login (`users`) and not the
 * operational history every trip points at (`drivers`); it links to both and
 * replaces neither, which is why `driver_id` and `user_id` are nullable and
 * plenty of employees have neither.
 */
export type EmploymentType = 'regular' | 'probationary' | 'contractual' | 'part_time';

export interface Employee extends Timestamped {
  id: string;
  /** Their payroll number, allocated by the API when the office has none. */
  employee_no: string;
  first_name: string;
  last_name: string;
  middle_name: string | null;
  /** Composed by the API, so both clients put a name together the same way. */
  full_name: string;
  /** The label — copied from the chosen position, or typed in. */
  position: string;
  /** Set when the title came from the managed list. */
  position_id: string | null;
  /** The role this job normally gets, so the account form can pre-select it. */
  suggested_role: string | null;
  suggested_role_name: string | null;
  department: string | null;
  employment_type: EmploymentType;
  employment_type_label: string;
  status: StatusValue;
  hired_on: string;
  birth_date: string | null;
  contact: string;
  email: string | null;
  address: string | null;
  emergency_contact: string | null;
  emergency_phone: string | null;
  base_salary_cents: number;
  /** Resolved on read, never stored — moving the install must not orphan it. */
  photo_url: string | null;
  driver_id: string | null;
  driver_name: string | null;
  user_id: number | null;
  account_email: string | null;
  /** Whether they can sign in. The chip the roster leads with. */
  has_account: boolean;
  role: string | null;
  role_label: string | null;
  notes: string | null;
}

export interface RosterOverview {
  headcount: number;
  active: number;
  inactive: number;
  by_position: { position: string; count: number }[];
  without_account: number;
}

/** What an account creation hands back, once and never again. */
export interface StaffCredentials {
  email: string;
  password: string;
}

export interface ModuleOption {
  key: string;
  label: string;
  group: string | null;
  icon: string | null;
}

/**
 * What an account sees, and what it could.
 *
 * `available` is everything the role permits. Assignment picks from inside
 * that and can never widen it — a nav row whose endpoint the role cannot open
 * would be a menu item that 403s on click, which is the appearance of access
 * without any.
 */
export interface ModuleState {
  role: string;
  role_label: string;
  available: ModuleOption[];
  assigned: string[];
  /** False means the default: everything the role allows. */
  customised: boolean;
}

/* -------------------------------------------------------------- Applicants */

export type ApplicantStage =
  'applied' | 'screening' | 'interview' | 'offered' | 'hired' | 'rejected';

export interface Applicant extends Timestamped {
  id: string;
  first_name: string;
  last_name: string;
  full_name: string;
  position_applied: string;
  contact: string;
  email: string | null;
  address: string | null;
  source: string | null;
  applied_on: string;
  stage: ApplicantStage;
  stage_label: string;
  /** The tone to render the pill in — never a colour on the wire. */
  tone: 'success' | 'info' | 'warning' | 'danger';
  /** Still waiting on somebody. Hired and rejected are not. */
  open: boolean;
  photo_url: string | null;
  resume_url: string | null;
  rating: number | null;
  notes: string | null;
  /** Set once the application became a hire. */
  employee_id: string | null;
  employee_no: string | null;
  decided_at: string | null;
}

export interface PipelineStage {
  stage: ApplicantStage;
  label: string;
  tone: 'success' | 'info' | 'warning' | 'danger';
  open: boolean;
  count: number;
}

export interface Pipeline {
  /** Every stage, empty ones included — a gap reads as a broken screen. */
  stages: PipelineStage[];
  open: number;
  total: number;
}

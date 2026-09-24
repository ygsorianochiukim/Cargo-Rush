import { PayBasis } from '../identity/access.model';
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
export type EmploymentType = 'trainee' | 'probationary' | 'regular' | 'contractual' | 'part_time';

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
  /**
   * What this person is on **today**, read off the contract in force.
   *
   * Pay is a contract — its own record, with the day it starts on — rather than
   * a column here, because a column is one figure that gets overwritten, and
   * the record of what somebody was on before a rise was the rise destroying
   * it. The whole history is at `employees/{id}/contracts`; these are the
   * current figures, flattened on because almost every screen wants only those.
   *
   * Null and zero where nobody has written a contract yet. That is a real
   * state — a record created before anybody said what it pays — and it keeps
   * the person off pay runs rather than putting a ₱0.00 payslip on one.
   *
   * `amount_cents` means a month, a day or a haul, and `pay_basis` is which.
   * `daily` multiplies it by the days the truck sheet names them on; `per_trip`
   * by the hauls they delivered.
   */
  pay_basis: PayBasis | null;
  pay_basis_label: string | null;
  pay_basis_detail: string | null;
  amount_cents: number;
  /** `₱15,000 a month` — the figure and what it buys, composed by the API. */
  pay_summary: string | null;
  contract_id: string | null;
  contract_effective_from: string | null;
  has_contract: boolean;

  /**
   * Is this person's pay multiplied by work done in the period?
   *
   * True on a daily or per-trip basis, and then the basis is the whole
   * instruction: they are on every run, and each cutoff counts what they
   * actually did. Nothing else can veto it.
   *
   * A firm that hands drivers their trip money in cash against the truck sheet
   * should leave them without a contract, which keeps them off a run.
   */
  paid_per_unit_worked: boolean;

  /**
   * Which agencies this person is registered with.
   *
   * All three true by default, which is what the system assumed before they
   * existed. Off is for the cases a fleet actually has: somebody not yet
   * registered, a casual hand taken on for the season, a person already
   * contributing through another employer. Switching one off stops that
   * contribution being withheld — it does not change what the agency is owed.
   *
   * There is deliberately no switch for withholding tax: whether somebody is
   * taxed is not the firm's to choose, and the API answers it from the BIR's
   * exemption threshold.
   */
  sss_enrolled: boolean;
  philhealth_enrolled: boolean;
  pagibig_enrolled: boolean;
  /** True when any of the three is off — for flagging a roster at a glance. */
  has_statutory_exemption: boolean;

  /**
   * The most one payslip may take off the store tab.
   *
   * Zero — the default — means the whole outstanding balance, which is what a
   * mini-mart tab settled each cutoff actually does. A figure spreads a larger
   * one over several payslips without anybody having to remember to stop.
   */
  store_deduction_cap_cents: number;

  /** Resolved on read, never stored — moving the install must not orphan it. */
  photo_url: string | null;
  /**
   * Whether the job this person holds drives, and their licence if it does.
   *
   * The licence is read off the `drivers` row rather than copied onto the
   * employee, so a renewal recorded in Drivers Management shows here without
   * two columns having to be kept in step. Null for everybody who does not
   * drive, which is most of the office.
   */
  position_drives: boolean;
  licence_no: string | null;
  licence_expiry: string | null;
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

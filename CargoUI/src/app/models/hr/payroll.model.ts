import { Timestamped } from '../shared/envelope.model';

export type PayRunStatus = 'draft' | 'approved' | 'paid';

export type PayrollScope = 'office' | 'crew';

export interface PayslipHiredOut {
  plate: string | null;
  arrangement: string;
  arrangement_label: string;
  from: string;
  to: string;
}

export interface CrewPayRosterRow {
  employee_id: string;
  driver_id: string;
  employee_no: string | null;
  name: string;
  role: 'driver' | 'helper';
  role_label: string;
  status: string | null;
  pay_basis: 'monthly' | 'daily' | 'per_trip';
  pay_basis_label: string;
  rate_cents: number;
  holding_plate: string | null;
  holding_arrangement_label: string | null;
  holding_since: string | null;
  last_cutoff: { label: string; trips: number; days: number };
}

export interface CrewPayTripRow {
  id: string;
  reference: string;
  on: string;
  origin: string;
  destination: string;
  plate: string | null;
  seat: 'driver' | 'helper';
  driver_allowance_cents: number;
  helper_allowance_cents: number;
  counted: boolean;
  withheld_reason: string | null;
  paid_on_run: string | null;
  paid_on_run_status: PayRunStatus | null;
}

export interface CrewPaySheetDay {
  on: string;
  truck: string | null;
  salary_cents: number;
  seat: 'driver' | 'helper';
  counted: boolean;
}

export interface CrewPayCutoff {
  label: string;
  short: string;
  start: string;
  end: string;
  pay_basis: 'monthly' | 'daily' | 'per_trip';
  pay_basis_label: string;
  rate_cents: number;
  trips: CrewPayTripRow[];
  sheet_days: CrewPaySheetDay[];
  counted_trips: number;
  withheld_trips: number;
  counted_days: number;
  expected_cents: number;
  held_whole_cutoff: boolean;
  hired_out: PayslipHiredOut[];
  run: {
    id: string;
    reference: string;
    status: PayRunStatus;
    on_run: boolean;
    basic_cents: number | null;
    gross_cents: number | null;
    net_cents: number | null;
    trips: number | null;
    days_worked: number | null;
    agrees: boolean | null;
  } | null;
}

export interface CrewPayReport {
  employee: {
    employee_id: string;
    driver_id: string | null;
    employee_no: string | null;
    name: string;
    role_label: string;
    pay_basis: 'monthly' | 'daily' | 'per_trip';
    pay_basis_label: string;
    rate_cents: number;
  };
  from: string;
  to: string;
  cutoffs: CrewPayCutoff[];
  totals: { trips: number; withheld_trips: number; days: number; expected_cents: number };
}

export interface PayslipTrip {
  id: string;
  reference: string;
  origin: string;
  destination: string;
  plate: string | null;
  seat: 'driver' | 'helper';
  on: string | null;
}

export interface PayRunStatutory {
  sss: number;
  philhealth: number;
  pagibig: number;
  withholding_tax: number;
  other: number;
}

export interface PayRunEmployerContributions {
  sss: number;
  ec: number;
  philhealth: number;
  pagibig: number;
  total: number;
}

export interface PayRunLine {
  id: string;
  employee_id: string;
  employee_no: string | null;
  name: string;
  position: string | null;

  crew: boolean;

  pay_basis: 'monthly' | 'daily' | 'per_trip';
  pay_basis_label: string;
  days_worked: number;
  sheet_days: number;
  trips: number;
  trip_details: PayslipTrip[] | null;
  hired_out: PayslipHiredOut[];

  basic_cents: number;
  allowance_cents: number;
  overtime_cents: number;
  adjustments_cents: number;
  adjustment_note: string | null;

  component_earnings_cents: number;
  component_deductions_cents: number;
  components: PayslipComponent[];

  sss_cents: number;
  philhealth_cents: number;
  pagibig_cents: number;

  sss_enrolled: boolean;
  philhealth_enrolled: boolean;
  pagibig_enrolled: boolean;

  withholding_tax_cents: number;

  employer_sss_cents: number;
  employer_ec_cents: number;
  employer_philhealth_cents: number;
  employer_pagibig_cents: number;
  employer_contributions_cents: number;
  other_deductions_cents: number;
  store_deduction_cents: number;
  deduction_note: string | null;

  zero_explanation: string | null;

  gross_cents: number;
  deductions_cents: number;
  net_cents: number;
}

export interface StoreCredit {
  id: string;
  employee_id: string;
  kind: 'charge' | 'payment';
  kind_label: string;
  sign: number;
  amount_cents: number;
  signed_cents: number;
  description: string | null;
  outlet: string | null;
  charged_on: string;
  pay_run_line_id: string | null;
  from_payroll: boolean;
  recorded_by: string | null;
  notes: string | null;
}

export interface StoreCreditState {
  rows: StoreCredit[];
  balance_cents: number;
  next_deduction_cents: number;
}

export interface StoreCreditPayload {
  kind?: 'charge' | 'payment';
  amount_cents: number;
  description?: string | null;
  outlet?: string | null;
  charged_on?: string;
  notes?: string | null;
}

export interface PayslipComponent {
  id: string;
  pay_component_id: string | null;
  name: string;
  kind: PayComponentKind;
  sign: string;
  taxable: boolean;
  amount_cents: number;
  added_by_hand: boolean;
}

export interface PayRun extends Timestamped {
  id: string;
  reference: string;
  scope: PayrollScope;
  scope_label: string;

  period_start: string;
  period_end: string;
  period_label: string;
  pay_date: string;

  status: PayRunStatus;
  approved_at: string | null;
  approved_by_name: string | null;
  paid_at: string | null;

  owed_to_agencies_cents: number;
  remittance_due_on: string;
  remitted_on: string | null;
  remittance_no: string | null;
  remittance_reference: string | null;

  journal_entry_id: string | null;
  journal_reference: string | null;

  is_first_cutoff: boolean;
  deduct_on: 'split' | 'first' | 'second';
  deduct_on_label: string;
  deduct_on_detail: string;
  carries_contributions: boolean;

  staff_count: number;
  gross_cents: number;
  deductions_cents: number;
  net_cents: number;
  statutory: PayRunStatutory;
  employer_contributions: PayRunEmployerContributions;
  currency: string;

  can_edit: boolean;
  can_approve: boolean;
  can_pay: boolean;

  notes: string | null;

  lines: PayRunLine[];
}

export interface PayPeriodOption {
  half: 'first' | 'second' | 'month';
  index: number;
  start: string;
  end: string;
  label: string;
  release: string;
  short: string;
  cutoff: string;
  days: number;
  suggested: boolean;
}

export interface PayRunPayload {
  scope?: PayrollScope;
  period_start: string;
  period_end: string;
  pay_date: string;
}

export interface PayRunLinePayload {
  allowance_cents?: number;
  overtime_cents?: number;
  adjustments_cents?: number;
  adjustment_note?: string | null;
  sss_cents?: number;
  philhealth_cents?: number;
  pagibig_cents?: number;
  withholding_tax_cents?: number;
  other_deductions_cents?: number;
  deduction_note?: string | null;
}

export interface PayrollCalendar {
  cutoff_days: number[];
  runs_per_month: number;
  description: string;
  day_labels: string[];
  release_lag_days: number;
  example_periods: (Omit<PayPeriodOption, 'suggested'> & {
    release_on: string;
  })[];
}

export type PayComponentKind = 'earning' | 'deduction';

export type PayComponentBasis = 'fixed' | 'percent_of_basic';

export type PayComponentSchedule = 'each_run' | 'monthly_split' | 'first_cutoff' | 'second_cutoff';

export interface PayComponent extends Timestamped {
  id: string;
  name: string;

  kind: PayComponentKind;
  kind_label: string;
  sign: string;

  basis: PayComponentBasis;
  basis_label: string;
  amount_cents: number;
  rate_bp: number;

  schedule: PayComponentSchedule;
  schedule_label: string;
  schedule_detail: string;
  is_monthly: boolean;

  taxable: boolean;

  status: string;
  position: number;
  notes: string | null;
  currency: string;

  assignment_count?: number;
}

export interface EmployeePayComponent extends Timestamped {
  id: string;
  employee_id: string;
  pay_component_id: string;
  component: PayComponent | null;

  amount_cents: number | null;
  rate_bp: number | null;

  effective_amount_cents: number | null;
  effective_rate_bp: number | null;
  overrides_amount: boolean;

  effective_from: string;
  effective_to: string | null;

  status: string;
  notes: string | null;
  currency: string;
}

export interface PayComponentPayload {
  name?: string;
  kind?: PayComponentKind;
  basis?: PayComponentBasis;
  amount_cents?: number;
  rate_bp?: number;
  schedule?: PayComponentSchedule;
  taxable?: boolean;
  status?: string;
  position?: number;
  notes?: string | null;
}

export interface EmployeePayComponentPayload {
  employee_id?: string;
  pay_component_id?: string;
  amount_cents?: number | null;
  rate_bp?: number | null;
  effective_from?: string;
  effective_to?: string | null;
  status?: string;
  notes?: string | null;
}

export type CutoffRequestStatus = 'pending' | 'approved' | 'declined' | 'withdrawn';

export interface PayrollCutoffRequest extends Timestamped {
  id: string;

  cutoff_days: number[];
  calendar: PayrollCalendar;
  summary: string;

  payroll_deduct_on: 'split' | 'first' | 'second' | null;
  payroll_deduct_on_label: string | null;

  reason: string;

  status: CutoffRequestStatus;
  requested_by_name: string | null;
  decided_by_name: string | null;
  decided_at: string | null;
  decision_note: string | null;

  previous_cutoff_days: number[] | null;

  current_calendar?: PayrollCalendar;
  already_in_force?: boolean;
  blocked_reason?: string | null;
}

export interface PayrollCutoffRequestPayload {
  cutoff_days: number[];
  payroll_deduct_on?: 'split' | 'first' | 'second' | null;
  reason: string;
}

import { PayBasis } from '../identity/access.model';
import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

export type EmploymentType = 'trainee' | 'probationary' | 'regular' | 'contractual' | 'part_time';

export interface Employee extends Timestamped {
  id: string;
  employee_no: string;
  first_name: string;
  last_name: string;
  middle_name: string | null;
  full_name: string;
  position: string;
  position_id: string | null;
  department: string | null;
  employment_type: EmploymentType;
  employment_type_label: string;
  status: StatusValue;
  hired_on: string;
  birth_date: string | null;
  contact: string;
  blood_type: string | null;
  religion: string | null;
  email: string | null;
  address: string | null;
  emergency_contact: string | null;
  emergency_phone: string | null;
  pay_basis: PayBasis | null;
  pay_basis_label: string | null;
  pay_basis_detail: string | null;
  amount_cents: number;
  pay_summary: string | null;
  contract_id: string | null;
  contract_effective_from: string | null;
  has_contract: boolean;

  paid_per_unit_worked: boolean;

  sss_enrolled: boolean;
  philhealth_enrolled: boolean;
  pagibig_enrolled: boolean;
  has_statutory_exemption: boolean;

  photo_url: string | null;
  position_drives: boolean;
  licence_no: string | null;
  licence_expiry: string | null;
  driver_id: string | null;
  driver_name: string | null;
  user_id: number | null;
  account_email: string | null;
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

export interface ModuleState {
  role: string;
  role_label: string;
  available: ModuleOption[];
  assigned: string[];
  customised: boolean;
}


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
  tone: 'success' | 'info' | 'warning' | 'danger';
  open: boolean;
  photo_url: string | null;
  resume_url: string | null;
  rating: number | null;
  notes: string | null;
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
  stages: PipelineStage[];
  open: number;
  total: number;
}

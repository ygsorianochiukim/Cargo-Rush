import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

export type CrewRole = 'driver' | 'helper';

export type CrewEmployment = 'company' | 'subcontractor';

export interface Driver extends Timestamped {
  id: string;
  role: CrewRole;
  employment: CrewEmployment;
  employment_label: string;
  on_payroll: boolean;
  name: string;
  contact: string | null;
  licence_no: string | null;
  licence_expiry: string | null;
  licence_expiring: boolean;
  violations: number;
  status: StatusValue;
  trips_completed: number;
  on_time_rate: number;
  user_id: number | null;
  employee_id: string | null;
  employee_no: string | null;
  employer?: DriverEmployer;
}

export interface DriverEmployer {
  kind: 'fleet' | 'trucker' | 'subcontractor';
  label: string;
}

export interface DriverPayload {
  role?: CrewRole;
  employment?: CrewEmployment;
  name: string;
  contact?: string | null;
  licence_no?: string | null;
  licence_expiry?: string | null;
  violations?: number;
  status?: StatusValue;
  user_id?: number | null;
}

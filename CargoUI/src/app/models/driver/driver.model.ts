import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

export interface Driver extends Timestamped {
  id: string;
  name: string;
  licence_no: string;
  licence_expiry: string;
  licence_expiring: boolean;
  violations: number;
  status: StatusValue;
  trips_completed: number;
  on_time_rate: number;
  user_id: number | null;
  employer?: DriverEmployer;
}

export interface DriverEmployer {
  kind: 'fleet' | 'trucker';
  label: string;
}

export interface DriverPayload {
  name: string;
  licence_no: string;
  licence_expiry: string;
  violations?: number;
  status?: StatusValue;
  user_id?: number | null;
}

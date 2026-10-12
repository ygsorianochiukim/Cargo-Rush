import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

export interface Vehicle extends Timestamped {
  id: string;
  plate: string;
  model: string;
  registration_no: string;
  capacity_kg: number;
  status: StatusValue;
  driver_id: string | null;
  driver_name: string | null;
  odometer_km: number;
  next_service_km: number;
  km_to_service: number;

  arrangement: VehicleArrangement;
  arrangement_label: string;
  hired: boolean;
  wheels: number | null;
  truck_category_id: string | null;

  owner_name: string | null;
  owner_contact: string | null;
  rent_cents: number | null;

  share_bp: number | null;
  shares_revenue: boolean;
  owner_trucker_id: string | null;
  owner_trucker_name?: string | null;
}

export type VehicleArrangement = 'owned' | 'rented' | 'rented_share' | 'subcontracted';

export interface VehiclePayload {
  plate: string;
  model: string;
  registration_no: string;
  capacity_kg: number;
  status?: StatusValue;
  driver_id?: string | null;
  odometer_km?: number;
  next_service_km?: number;

  arrangement?: VehicleArrangement;
  wheels?: number | null;
  truck_category_id?: string | null;
  owner_name?: string | null;
  owner_contact?: string | null;
  rent_cents?: number | null;
  share_bp?: number | null;
  owner_trucker_id?: string | null;
}

export interface MaintenanceJob extends Timestamped {
  id: string;
  vehicle_id: string;
  vehicle_plate: string | null;
  kind: string;
  due_at: string;
  odometer_km: number;
  next_service_km: number;
  status: StatusValue;

  cost_cents: number | null;
  completed_on: string | null;
  supplier_id: string | null;
  supplier_name?: string | null;
  invoice_id: string | null;
  reference: string | null;
  note: string | null;
  on_the_sheet: boolean;
}

export interface MaintenanceJobPayload {
  vehicle_id?: string;
  kind: string;
  due_at: string;
  next_service_km?: number;
  status?: StatusValue;
  cost_cents?: number | null;
  completed_on?: string | null;
  supplier_id?: string | null;
  invoice_id?: string | null;
  reference?: string | null;
  note?: string | null;
}

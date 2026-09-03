import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/** Vehicle Management — registration, capacity, status, maintenance. */
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
  /** Negative means the service interval has already passed. */
  km_to_service: number;
}

export interface VehiclePayload {
  plate: string;
  model: string;
  registration_no: string;
  capacity_kg: number;
  status?: StatusValue;
  driver_id?: string | null;
  odometer_km?: number;
  next_service_km?: number;
}

/** An assigned service job. */
export interface MaintenanceJob extends Timestamped {
  id: string;
  vehicle_id: string;
  vehicle_plate: string | null;
  kind: string;
  due_at: string;
  odometer_km: number;
  next_service_km: number;
  status: StatusValue;
}

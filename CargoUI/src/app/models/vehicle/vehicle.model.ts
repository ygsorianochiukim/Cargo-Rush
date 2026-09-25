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

  /**
   * On what terms this truck runs for the fleet.
   *
   * Every unit dispatches identically whoever owns the wheels — same board,
   * same driver, same invoice. This decides only where the money goes when a
   * run closes:
   *
   *   `owned`          every peso is the fleet's
   *   `rented`         a flat monthly fee, and every peso is still the fleet's
   *   `rented_share`   no rent; the fleet keeps its cut, the owner takes the rest
   *   `subcontracted`  the same, to somebody holding the handset
   */
  arrangement: VehicleArrangement;
  arrangement_label: string;
  /** Anything that is not the fleet's own. */
  hired: boolean;
  /** What the desk means by "a ten-wheeler". Informational. */
  wheels: number | null;

  owner_name: string | null;
  owner_contact: string | null;
  /** The monthly fee, in centavos. `rented` only. */
  rent_cents: number | null;

  /** The fleet's cut, in basis points. Null unless it is a share arrangement. */
  share_bp: number | null;
  /**
   * Is the truck actually set up to pay somebody?
   *
   * False for a unit marked as a share arrangement with nobody named to
   * receive it — a half-configured truck that would haul all month and credit
   * nothing. The fleet list flags it rather than letting it surface as an
   * owner asking where their money went.
   */
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

  /** The terms. Absent means `owned`, which is what the API assumes too. */
  arrangement?: VehicleArrangement;
  wheels?: number | null;
  owner_name?: string | null;
  owner_contact?: string | null;
  rent_cents?: number | null;
  share_bp?: number | null;
  owner_trucker_id?: string | null;
}

/**
 * A service on a unit — booked, done, and what it came to.
 *
 * One record seen at two moments rather than two records: a job is booked with
 * a kind and a date, and costed later when the garage's invoice comes back.
 * Splitting them would turn the ordinary correction — a figure mistyped — into
 * a decision about which form to open.
 *
 * Read from two screens. The unit's own shows what is booked on that truck;
 * Truck Maintenance shows the same rows across the fleet, as spend.
 */
export interface MaintenanceJob extends Timestamped {
  id: string;
  vehicle_id: string;
  vehicle_plate: string | null;
  kind: string;
  due_at: string;
  odometer_km: number;
  next_service_km: number;
  status: StatusValue;

  /**
   * What it cost, in centavos. **Null is not zero.**
   *
   * Null is "nobody has told us yet" — the state a job spends most of its life
   * in — and zero is a warranty replacement nobody was charged for. A screen
   * printing both as ₱0 hides the difference between free and unknown.
   */
  cost_cents: number | null;
  /** The day the work was done, which is the day it is charged to. */
  completed_on: string | null;
  supplier_id: string | null;
  supplier_name?: string | null;
  reference: string | null;
  note: string | null;
  /**
   * Whether the cost has reached the unit's daily sheet.
   *
   * False on a costed job with no completed date: there is no day to charge it
   * to, so the money is recorded and not yet counted. The screen says so rather
   * than leaving somebody wondering why Profitability has not moved.
   */
  on_the_sheet: boolean;
}

export interface MaintenanceJobPayload {
  /** Required from Truck Maintenance; the unit's own screen has it in the path. */
  vehicle_id?: string;
  kind: string;
  due_at: string;
  next_service_km?: number;
  status?: StatusValue;
  cost_cents?: number | null;
  completed_on?: string | null;
  supplier_id?: string | null;
  reference?: string | null;
  note?: string | null;
}

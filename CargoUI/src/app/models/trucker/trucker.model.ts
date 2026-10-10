import { DriverEmployer } from '../driver/driver.model';
import { StatusValue } from '../shared/status.model';

export interface Trucker {
  id: string;
  name: string;
  business_name: string | null;
  phone: string;
  licence_no: string | null;
  licence_expiry: string | null;

  status: StatusValue;
  is_online: boolean;
  can_take_work: boolean;

  commission_bp: number;

  latitude: number | null;
  longitude: number | null;
  located_at: string | null;
  position_fresh: boolean;

  trips_completed: number;
  user_id: number | null;

  vehicles?: TruckerVehicle[];
  drivers?: TruckerDriver[];

  created_at: string;
  updated_at: string;
}

export interface TruckerDriver {
  id: string;
  trucker_id: string;
  name: string;
  phone: string | null;
  licence_no: string;
  licence_expiry: string | null;
  status: StatusValue;
  email?: string | null;
  employer: DriverEmployer;
}

export interface TruckerVehicle {
  id: string;
  plate: string;
  model: string;
  capacity_kg: number;
  truck_category_id: string | null;
  truck_category?: string | null;
  status: StatusValue;

  verification: TruckVerification;
  verified_at: string | null;
  rejection_reason: string | null;
  photos: Record<TruckPhotoSlot, string | null>;
  created_at?: string;
}

export type TruckVerification = 'pending' | 'verified' | 'rejected';

export type TruckPhotoSlot = 'front' | 'left' | 'right' | 'back' | 'plate' | 'engine';

export const TRUCK_PHOTO_SLOTS: { slot: TruckPhotoSlot; label: string }[] = [
  { slot: 'front', label: 'Front' },
  { slot: 'left', label: 'Left side' },
  { slot: 'right', label: 'Right side' },
  { slot: 'back', label: 'Back' },
  { slot: 'plate', label: 'Plate number' },
  { slot: 'engine', label: 'Engine' },
];

export type WalletEntryKind = 'earning' | 'commission' | 'payout' | 'remittance' | 'adjustment';
export type WalletSource = 'cargo_rush' | 'direct';

export interface WalletEntry {
  id: string;
  kind: WalletEntryKind;
  kind_label: string;
  description: string;

  amount_cents: number;

  source: WalletSource | null;
  source_label: string | null;

  gross_cents: number | null;
  rate_bp: number | null;

  trip_id: string | null;
  trip_reference: string | null;

  reference: string | null;
  note: string | null;
  occurred_on: string | null;

  settleable: boolean;

  settled: boolean;
  settled_at: string | null;
  settlement_reference?: string | null;

  payment_state: 'unpaid' | 'processing' | 'paid' | null;

  status: string;
  method: string | null;
}

export interface Wallet {
  balance_cents: number;
  standing: 'owed_to_trucker' | 'owed_to_company' | 'settled';

  earned_cents: number;
  commission_cents: number;
  paid_out_cents: number;
  remitted_cents: number;
  adjustments_cents: number;
  trips_settled: number;

  unpaid_earnings_cents: number;
  unpaid_earnings_count: number;
  unremitted_commission_cents: number;
  unremitted_commission_count: number;

  in_flight_cents: number;

  entries: WalletEntry[];
}

export interface WalletEntryPayload {
  kind: 'payout' | 'remittance' | 'adjustment';

  entry_ids?: string[];

  amount_cents?: number;

  method?: string;
  cleared?: boolean;

  reference?: string | null;
  note?: string | null;
  occurred_on?: string | null;
}

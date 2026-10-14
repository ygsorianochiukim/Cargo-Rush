import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';


export interface PricingBracket extends Timestamped {
  zone_id: string | null;
  id: string;
  truck_category_id: string | null;
  truck_category?: TruckCategory | null;
  label: string;
  min_km: number | null;
  max_km: number | null;
  range: string;
  base_cents: number;
  per_km_cents: number;
  per_kg_cents: number;
  minimum_cents: number;
  diesel_step_cents: number;
  position: number;
}

export interface PricingZone extends Timestamped {
  id: string;
  name: string;
  code: string;
  min_km: number;
  max_km: number | null;
  band: string;
  diesel_baseline_cents: number | null;
  position: number;
  status: StatusValue;
  notes: string | null;
  brackets: PricingBracket[];
  bracket_count: number;
}

export interface BracketPayload {
  id?: string | null;
  label: string;
  min_km?: number;
  max_km?: number | null;
  base_cents: number;
  per_km_cents?: number;
  per_kg_cents?: number;
  minimum_cents?: number;
  diesel_step_cents?: number;
  truck_category_id?: string | null;
}

export interface TruckCategory extends Timestamped {
  id: string;
  key: string;
  name: string;
  description: string | null;
  cbm: number | null;
  position: number;
  status: string;
  bracket_count?: number;
  vehicle_count?: number;
}

export interface TruckCategoryPayload {
  name: string;
  description?: string | null;
  cbm?: number | null;
  position?: number;
  status?: string;
}

export interface PricingZonePayload {
  name: string;
  code: string;
  min_km: number;
  max_km: number | null;
  diesel_baseline_cents?: number | null;
  status?: StatusValue;
  notes?: string | null;
  brackets?: BracketPayload[];
}

export interface DieselState {
  current: {
    effective_on: string;
    price_per_litre_cents: number;
    source: string | null;
  } | null;
  baseline_cents: number;
  band_floor_cents: number;
  sensitivity: number;
  cap_bp: number;
  adjustment_bp: number;
  capped: boolean;
  currency: string;
  card_cbm: number;
  history: DieselPrice[];
}

export interface DieselPrice extends Timestamped {
  id: string;
  effective_on: string;
  price_per_litre_cents: number;
  currency: string;
  source: string | null;
}

export interface DieselPricePayload {
  price_per_litre_cents: number;
  effective_on?: string;
  source?: string | null;
}

export interface ZoneAlternative {
  id: string;
  code: string;
  name: string;
  band: string;
}

export interface QuoteBreakdown {
  cents: number | null;
  card_cents: number | null;
  fuel_adjustment_bp: number;
  fuel_adjustment_cents: number;
  fuel_surcharge_cents: number;
  fuel_rule: 'step' | 'percentage' | 'none';
  km: number;
  weight_kg: number;
  currency: string;
  source: 'zone' | 'unzoned';
  needs_zone: boolean;
  reason: string | null;
  zone: { id: string; code: string; name: string; band: string } | null;
  zone_pinned: boolean;
  zone_alternatives: ZoneAlternative[];
  bracket: { id: string; label: string; range: string } | null;
  volume: { cbm: number; card_cbm: number } | null;
  diesel: {
    price_per_litre_cents: number | null;
    baseline_cents: number | null;
    step_cents: number;
    pesos_above_baseline: number;
  };
}

export interface QuoteRequest {
  distance_km?: number;
  weight_kg?: number;
  truck_category_id?: string | null;
  pricing_zone_id?: string | null;
}

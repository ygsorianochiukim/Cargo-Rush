import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/**
 * The rate card — Rate Card module.
 *
 * A zone is a service area matched from what a booking says its destination
 * is; the brackets inside it are the card: within this many kilometres, this
 * is the price. Money is integer centavos (DESIGN.md section 7.1).
 */

/** One line on a card: within this distance, this is the price. */
export interface PricingBracket extends Timestamped {
  id: string;
  zone_id: string;
  label: string;
  /** Inclusive. */
  min_km: number;
  /** Exclusive, and null for the open-ended top bracket. */
  max_km: number | null;
  /** "Within 20 km" / "20 – 50 km" / "80 km and beyond", composed by the API. */
  range: string;
  base_cents: number;
  per_km_cents: number;
  per_kg_cents: number;
  minimum_cents: number;
  position: number;
}

export interface PricingZone extends Timestamped {
  id: string;
  name: string;
  code: string;
  /** The other spellings this zone answers to, matched against a destination. */
  aliases: string[];
  /** The pump price this card was drawn at. Null uses the install-wide one. */
  diesel_baseline_cents: number | null;
  position: number;
  status: StatusValue;
  notes: string | null;
  brackets: PricingBracket[];
  bracket_count: number;
}

/** A bracket as the editor sends it. `id` is absent on a new row. */
export interface BracketPayload {
  id?: string | null;
  label: string;
  min_km: number;
  max_km: number | null;
  base_cents: number;
  per_km_cents: number;
  per_kg_cents: number;
  minimum_cents: number;
}

/**
 * The whole card in one payload.
 *
 * `brackets` omitted means "not part of this edit" — the API leaves the stored
 * rows alone, so renaming a zone does not wipe its rates.
 */
export interface PricingZonePayload {
  name: string;
  code: string;
  aliases?: string[];
  diesel_baseline_cents?: number | null;
  status?: StatusValue;
  notes?: string | null;
  brackets?: BracketPayload[];
}

/** What the pump costs, and what that is doing to every quote. */
export interface DieselState {
  current: {
    effective_on: string;
    price_per_litre_cents: number;
    source: string | null;
  } | null;
  /** What the cards assume diesel costs. */
  baseline_cents: number;
  /** The fuel share of a run — how much of a price move is passed through. */
  sensitivity: number;
  cap_bp: number;
  /** Signed basis points. 425 is +4.25%; negative is a discount. */
  adjustment_bp: number;
  /** True when the guard rail, not the pump, is deciding the figure. */
  capped: boolean;
  currency: string;
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

/** A quote, and the reasoning behind it. */
export interface QuoteBreakdown {
  cents: number;
  /** The card figure before the fuel adjustment. */
  card_cents: number;
  fuel_adjustment_bp: number;
  fuel_adjustment_cents: number;
  km: number;
  weight_kg: number;
  currency: string;
  /** `zone` when a card priced it; `tariff` when it fell back to config. */
  source: 'zone' | 'tariff';
  zone: { id: string; name: string } | null;
  bracket: { id: string; label: string; range: string } | null;
  diesel: {
    price_per_litre_cents: number | null;
    baseline_cents: number | null;
  };
}

export interface QuoteRequest {
  destination?: string | null;
  origin?: string | null;
  distance_km?: number;
  weight_kg?: number;
}

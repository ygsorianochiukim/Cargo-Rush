import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/**
 * The rate card — Rate Card module.
 *
 * A zone is a **band of kilometres**, the way the trade's own subsidy tables
 * write one: `A1` is 1–40 km, `O` is 561–600, and the money hangs off the band.
 * Under each band sits a rate line per class of truck.
 *
 * Beside that is the firm's plain distance card — lines belonging to no band,
 * carrying their own kilometres — which is the whole card for a firm with no
 * published table behind it, and the fallback past the last band for a firm
 * that has one.
 *
 * Money is integer centavos (DESIGN.md section 7.1).
 */

/** One line of money: a band's rate for a class of truck, or a plain line. */
export interface PricingBracket extends Timestamped {
  /** Null on the firm's plain distance card — the lines tied to no band. */
  zone_id: string | null;
  id: string;
  /** The class of unit this line prices. Null means the fleet as it stands. */
  truck_category_id: string | null;
  truck_category?: TruckCategory | null;
  label: string;
  /**
   * Inclusive, and **null on a line inside a band** — it borrows its zone's
   * kilometres. The band is written down once, so an editor should not offer a
   * distance field for these rows.
   */
  min_km: number | null;
  /** Exclusive; null for an open-ended line or a line that borrows its band. */
  max_km: number | null;
  /** "1 – 40 km" / "80 km and beyond", composed by the API. */
  range: string;
  base_cents: number;
  per_km_cents: number;
  per_kg_cents: number;
  minimum_cents: number;
  /**
   * Pesos added per ₱1/L of diesel above the baseline, in centavos.
   *
   * The rightmost column of a printed subsidy table — A1 adds ₱14 a peso, O
   * adds ₱210. Zero leaves this line on the percentage fuel model instead.
   */
  diesel_step_cents: number;
  position: number;
}

export interface PricingZone extends Timestamped {
  id: string;
  name: string;
  /** The cell of the printed table: `A1`, `E2`, `O`. */
  code: string;
  /** Inclusive. */
  min_km: number;
  /** Exclusive, and null for the open-ended top band. */
  max_km: number | null;
  /** "1 – 40 km", composed by the API with the inclusive bound a table prints. */
  band: string;
  /**
   * The pump price this band's printed figures already cover.
   *
   * On a subsidy card it is the **top** of the baseline band — the workbook's
   * holds from ₱30 to ₱43 a litre — so diesel below it adds nothing. Null uses
   * the install-wide figure.
   */
  diesel_baseline_cents: number | null;
  position: number;
  status: StatusValue;
  notes: string | null;
  brackets: PricingBracket[];
  bracket_count: number;
}

/** A line as an editor sends it. `id` is absent on a new row. */
export interface BracketPayload {
  id?: string | null;
  label: string;
  /** Only on the plain distance card. A band's lines take the band's. */
  min_km?: number;
  max_km?: number | null;
  base_cents: number;
  per_km_cents?: number;
  per_kg_cents?: number;
  minimum_cents?: number;
  /** Pesos per ₱1/L above the baseline, in centavos. Omitted means none. */
  diesel_step_cents?: number;
  /** The class of unit this line prices. Null or omitted means the fleet. */
  truck_category_id?: string | null;
}

/**
 * A class of unit the firm runs — Dry Goods, Freezer, Brand New Truck.
 *
 * The rate card's second dimension. A subsidy table prices the same band three
 * times over, once per condition of unit, and reefer work carries a premium
 * that has nothing to do with how far the run is — a card that could only
 * price distance forced the desk to quote both off-system.
 *
 * Per company: what a haulier calls its unit types, and what it charges for
 * them, is nobody else's business.
 */
export interface TruckCategory extends Timestamped {
  id: string;
  key: string;
  name: string;
  description: string | null;
  position: number;
  status: string;
  /** What depends on it — both matter before removing one. */
  bracket_count?: number;
  vehicle_count?: number;
}

export interface TruckCategoryPayload {
  name: string;
  description?: string | null;
  position?: number;
  status?: string;
}

/**
 * The whole band in one payload.
 *
 * `brackets` omitted means "not part of this edit" — the API leaves the stored
 * rows alone, so renaming a band does not wipe its rates.
 */
export interface PricingZonePayload {
  name: string;
  code: string;
  min_km: number;
  /**
   * The first kilometre the band no longer covers.
   *
   * A table row reading "1 to 40" is sent as 1 and 41. The API does not
   * convert, on purpose: a client sending 40 and an API reading it as
   * exclusive would be a card a kilometre short in every band with no error
   * anywhere to say so.
   */
  max_km: number | null;
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
  /** The top of the band the cards' printed figures cover. */
  baseline_cents: number;
  /** The bottom of that band — display only; nothing computes from it. */
  band_floor_cents: number;
  /** The fuel share of a run, for lines priced as a percentage of the fare. */
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

/** A band that also covers the quoted distance — A2 beside A1. */
export interface ZoneAlternative {
  id: string;
  code: string;
  name: string;
  band: string;
}

/** A quote, and the reasoning behind it. */
export interface QuoteBreakdown {
  cents: number;
  /** The card figure before diesel — a table's `Current Price`. */
  card_cents: number;
  /** Basis points, on a line priced as a percentage of the fare. */
  fuel_adjustment_bp: number;
  /** What diesel added, signed, whichever rule applied. */
  fuel_adjustment_cents: number;
  /** What a table's peso-per-peso step added. Zero on a percentage quote. */
  fuel_surcharge_cents: number;
  /**
   * Which rule priced the diesel.
   *
   *   `step`        — the table's own arithmetic, pesos per ₱1/L
   *   `percentage`  — the fuel-share model, for a line with no step
   *   `none`        — no pump price recorded
   */
  fuel_rule: 'step' | 'percentage' | 'none';
  km: number;
  weight_kg: number;
  currency: string;
  /**
   * Which card priced it.
   *
   *   `zone`    — a band of the rate table
   *   `card`    — the firm's plain distance card, with no band in it
   *   `tariff`  — nothing covered the run, so the configured fallback
   *
   * Three words rather than two, because 'which card was this?' is the first
   * question anybody asks of a price they disagree with.
   */
  source: 'zone' | 'card' | 'tariff';
  zone: { id: string; code: string; name: string; band: string } | null;
  /**
   * The other bands covering this distance.
   *
   * A subsidy table normally has two rows over one band — A1 beside A2 at
   * different money — and which applies is a call the desk makes rather than
   * one a distance can. A screen showing one figure as though it were the only
   * one on the card would hide half the table.
   */
  zone_alternatives: ZoneAlternative[];
  bracket: { id: string; label: string; range: string } | null;
  diesel: {
    price_per_litre_cents: number | null;
    baseline_cents: number | null;
    /** Pesos per ₱1/L above the baseline, in centavos. */
    step_cents: number;
    /** Whole pesos a litre the pump sits above the baseline. */
    pesos_above_baseline: number;
  };
}

export interface QuoteRequest {
  distance_km?: number;
  weight_kg?: number;
  /** The class of unit the run needs. Absent takes the band's fleet line. */
  truck_category_id?: string | null;
  /**
   * The band to price in.
   *
   * Absent lets the distance choose, which is right for every band the table
   * does not double up. It is here for the ones it does.
   */
  pricing_zone_id?: string | null;
}

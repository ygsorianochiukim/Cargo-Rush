/** A point on the earth, with the name a person reads it as. */
export interface GeoPoint {
  /** The place name. This is what the trip list and the driver's phone show. */
  place: string;
  lat: number;
  lng: number;
}

/** One result from a place search. */
export interface GeoResult extends GeoPoint {
  /** Stable id from the provider, for list tracking. */
  id: string;
  /** The full address, when the short name alone would be ambiguous. */
  detail: string;
}

/**
 * A location as a trip carries it.
 *
 * The name is required and the coordinates are not: a trip booked over the
 * phone has a place name long before anybody has pinned it on a map, and a
 * form that refuses to save until somebody has is a worse form.
 */
export interface TripLocation {
  place: string;
  lat: number | null;
  lng: number | null;
}

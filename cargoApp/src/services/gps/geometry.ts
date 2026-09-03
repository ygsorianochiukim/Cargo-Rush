/**
 * The two bits of spherical geometry the handset needs while it is moving.
 *
 * Kept out of the tracking service so they can be tested on their own — this
 * is arithmetic that runs unattended in a background task on somebody's phone
 * for ten hours, and getting it wrong shows up as a truck that appears to be
 * doing 4,000 km/h.
 */

const EARTH_RADIUS_M = 6_371_000;

export interface LatLng {
  lat: number;
  lng: number;
}

const toRadians = (degrees: number): number => (degrees * Math.PI) / 180;

/**
 * Great-circle distance between two points, in metres.
 *
 * Haversine rather than the flat-earth approximation: over a 300m step the
 * difference is nothing, but these are summed across a whole run and the
 * error would accumulate into the average speed.
 */
export function distanceM(from: LatLng, to: LatLng): number {
  const lat1 = toRadians(from.lat);
  const lat2 = toRadians(to.lat);
  const dLat = lat2 - lat1;
  const dLng = toRadians(to.lng - from.lng);

  const a =
    Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;

  return Math.round(EARTH_RADIUS_M * 2 * Math.asin(Math.min(1, Math.sqrt(a))));
}

/** The eight-point compass name for a bearing, which is what a person reads. */
const COMPASS = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'] as const;

/**
 * Which way the unit is pointing, from where it was to where it is.
 *
 * Derived from consecutive positions rather than taken from the device
 * heading, because a phone flat in a cradle reports the direction it is
 * facing, not the direction the truck is going.
 */
export function headingOf(from: LatLng, to: LatLng): string {
  const lat1 = toRadians(from.lat);
  const lat2 = toRadians(to.lat);
  const dLng = toRadians(to.lng - from.lng);

  const y = Math.sin(dLng) * Math.cos(lat2);
  const x = Math.cos(lat1) * Math.sin(lat2) - Math.sin(lat1) * Math.cos(lat2) * Math.cos(dLng);

  const bearing = (Math.atan2(y, x) * 180) / Math.PI;
  const normalised = (bearing + 360) % 360;

  return COMPASS[Math.round(normalised / 45) % 8];
}

/**
 * How far along the run is, 0–100.
 *
 * Measured as ground still to cover, not ground already covered: a detour
 * round a closed bridge adds to the distance travelled without getting the
 * load any closer, and a progress bar that jumps forward on a detour is
 * lying to the office watching it.
 *
 * Returns null when the trip has no destination pinned — no progress is a
 * better answer than a made-up one.
 */
export function progressPct(
  current: LatLng,
  origin: LatLng | null,
  destination: LatLng | null,
): number | null {
  if (origin === null || destination === null) return null;

  const total = distanceM(origin, destination);
  if (total === 0) return 100;

  const remaining = distanceM(current, destination);

  return Math.max(0, Math.min(100, Math.round(((total - remaining) / total) * 100)));
}

import { GeoPoint, GeoResult } from '@/models/geo/geo.model';

/** What Nominatim returns. Only the fields actually read are declared. */
interface NominatimPlace {
  place_id: number;
  lat: string;
  lon: string;
  name?: string;
  display_name: string;
}

const BASE = 'https://nominatim.openstreetmap.org';

/**
 * Results are biased to the Philippines, because that is where this fleet runs
 * and "Manila" should not first offer one in Arkansas.
 */
const COUNTRY_CODES = 'ph';

/**
 * Nominatim asks callers to identify themselves. A browser refuses to let a
 * page set this header and drops it silently; the handset does send it, which
 * is where the volume comes from anyway.
 */
const HEADERS = { Accept: 'application/json', 'User-Agent': 'CargoRush/1.0 (fleet app)' };

/**
 * Turning a place into a point, and a point back into a place.
 *
 * The handset's copy of `CargoUI/src/app/services/geo/geo.service.ts`, down to
 * the provider and the country bias, so a place pinned on a phone is named the
 * same way as the same place pinned at the desk. Backed by **OpenStreetMap
 * Nominatim**: no API key, no cost, and a usage policy of roughly a request a
 * second — the right call for getting this working and the wrong one to leave
 * in place at volume. A fleet doing hundreds of bookings a day should move to
 * a paid geocoder or a self-hosted Nominatim, and only this file would change.
 *
 * Every failure resolves to an empty result rather than throwing. Geocoding is
 * a convenience on top of a field somebody can always type into, and a search
 * that quietly finds nothing beats a sheet that will not close — which on a
 * phone, in a warehouse, on one bar of signal, is the common case.
 */
export const geoService = {
  async search(term: string, limit = 6): Promise<GeoResult[]> {
    const query = term.trim();
    if (query.length < 3) return [];

    const params = new URLSearchParams({
      q: query,
      format: 'jsonv2',
      addressdetails: '0',
      countrycodes: COUNTRY_CODES,
      limit: String(limit),
    });

    try {
      const response = await fetch(`${BASE}/search?${params}`, { headers: HEADERS });
      if (!response.ok) return [];

      const places = (await response.json()) as NominatimPlace[];

      return Array.isArray(places) ? places.map(toResult) : [];
    } catch {
      return [];
    }
  },

  /**
   * The place name for a pin somebody dropped — what turns a pair of numbers
   * into somewhere a driver can be told to go.
   *
   * Zoom 14 is a neighbourhood rather than a building, which is the honest
   * resolution: the pin says exactly where, and the name says roughly where,
   * and pretending the name is exact would put a house number on a field gate.
   */
  async reverse(lat: number, lng: number): Promise<GeoPoint | null> {
    const params = new URLSearchParams({
      lat: String(lat),
      lon: String(lng),
      format: 'jsonv2',
      zoom: '14',
    });

    try {
      const response = await fetch(`${BASE}/reverse?${params}`, { headers: HEADERS });
      if (!response.ok) return null;

      const place = (await response.json()) as NominatimPlace;

      return place?.display_name ? toResult(place) : null;
    } catch {
      return null;
    }
  },
};

function toResult(place: NominatimPlace): GeoResult {
  // `display_name` is the full chain — "Manila, Metro Manila, 1000,
  // Philippines". The first part is what a person calls the place, and the
  // rest is what tells two places of the same name apart.
  const parts = place.display_name.split(',').map((part) => part.trim());

  return {
    id: String(place.place_id),
    place: place.name?.trim() || parts[0] || place.display_name,
    detail: parts.slice(1).join(', '),
    lat: Number(place.lat),
    lng: Number(place.lon),
  };
}

import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of } from 'rxjs';

import { GeoPoint, GeoResult } from '../../models/geo/geo.model';

/** What Nominatim returns. Only the fields actually read are declared. */
interface NominatimPlace {
  place_id: number;
  lat: string;
  lon: string;
  name?: string;
  display_name: string;
}

/**
 * Turning a place into a point, and a point back into a place.
 *
 * Backed by **OpenStreetMap Nominatim**, which needs no API key and costs
 * nothing — the right call for getting this working, and the wrong one to
 * leave in place at volume. Its usage policy caps you at roughly one request
 * a second and asks for identification; a fleet doing hundreds of bookings a
 * day should move to a paid geocoder or a self-hosted Nominatim. Only this
 * file would change.
 *
 * Every failure resolves to an empty result rather than an error. Geocoding is
 * a convenience on top of a field somebody can always type into, and a search
 * that quietly finds nothing is better than a dialog that will not close.
 */
@Injectable({ providedIn: 'root' })
export class GeoService {
  private readonly http = inject(HttpClient);

  private readonly base = 'https://nominatim.openstreetmap.org';

  /**
   * Results are biased to the Philippines, because that is where this fleet
   * runs and "Manila" should not first offer one in Arkansas.
   */
  private readonly countryCodes = 'ph';

  search(term: string, limit = 6): Observable<GeoResult[]> {
    const query = term.trim();
    if (query.length < 3) return of([]);

    const params = new URLSearchParams({
      q: query,
      format: 'jsonv2',
      addressdetails: '0',
      countrycodes: this.countryCodes,
      limit: String(limit),
    });

    return this.http.get<NominatimPlace[]>(`${this.base}/search?${params}`).pipe(
      map((places) => places.map((place) => this.toResult(place))),
      catchError(() => of([])),
    );
  }

  /** The place name for a pin the user dropped. */
  reverse(lat: number, lng: number): Observable<GeoPoint | null> {
    const params = new URLSearchParams({
      lat: String(lat),
      lon: String(lng),
      format: 'jsonv2',
      zoom: '14',
    });

    return this.http.get<NominatimPlace>(`${this.base}/reverse?${params}`).pipe(
      map((place) => (place?.display_name ? this.toResult(place) : null)),
      catchError(() => of(null)),
    );
  }

  private toResult(place: NominatimPlace): GeoResult {
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
}

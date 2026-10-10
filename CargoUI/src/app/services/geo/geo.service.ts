import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of } from 'rxjs';

import { GeoPoint, GeoResult } from '../../models/geo/geo.model';

interface NominatimPlace {
  place_id: number;
  lat: string;
  lon: string;
  name?: string;
  display_name: string;
}

@Injectable({ providedIn: 'root' })
export class GeoService {
  private readonly http = inject(HttpClient);

  private readonly base = 'https://nominatim.openstreetmap.org';

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

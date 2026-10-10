export interface GeoPoint {
  place: string;
  lat: number;
  lng: number;
}

export interface GeoResult extends GeoPoint {
  id: string;
  detail: string;
}

export interface TripLocation {
  place: string;
  lat: number | null;
  lng: number | null;
}

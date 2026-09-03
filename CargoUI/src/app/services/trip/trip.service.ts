import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';
import { Trip, TripConfirmPayload, TripPayload } from '../../models/trip/trip.model';

/**
 * Trip Management — DESIGN.md section 5.1.
 *
 * Dispatch and complete are their own calls rather than status patches,
 * because each writes more than the trip row.
 */
@Injectable({ providedIn: 'root' })
export class TripService {
  private readonly api = inject(ApiService);

  /** The envelope, so a list view can read `meta.total` for its pager. */
  list(query?: ListQuery): Observable<Envelope<Trip[]>> {
    return this.api.envelope<Trip[]>('trips', query);
  }

  find(id: string): Observable<Trip> {
    return this.api.get<Trip>(`trips/${id}`);
  }

  /** The API assigns the id and the reference, so read the row back. */
  create(payload: TripPayload): Observable<Trip> {
    return this.api.post<Trip>('trips', payload);
  }

  update(id: string, payload: Partial<TripPayload>): Observable<Trip> {
    return this.api.patch<Trip>(`trips/${id}`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`trips/${id}`);
  }

  /**
   * Confirm a customer's request: name the crew, the unit and the time.
   *
   * A verb rather than a status patch, because `assigned` is what follows from
   * those fields being filled in. This is the desk's one action on a request.
   */
  confirm(id: string, payload: TripConfirmPayload): Observable<Trip> {
    return this.api.post<Trip>(`trips/${id}/confirm`, payload);
  }

  /** Sends the unit out and opens its dispatch record. */
  dispatch(id: string, location: string): Observable<Trip> {
    return this.api.post<Trip>(`trips/${id}/dispatch`, { location });
  }

  /**
   * Closes the trip, its dispatch record, its delivery log, the day's income
   * and the customer's invoice — together.
   *
   * `receiver_name` is the signature and is required; the proof-of-delivery
   * reference is assigned by the API, never sent.
   */
  complete(id: string, receiver: string): Observable<Trip> {
    return this.api.post<Trip>(`trips/${id}/complete`, { receiver_name: receiver });
  }
}

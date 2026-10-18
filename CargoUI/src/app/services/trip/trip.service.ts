import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';
import {
  Trip,
  TripConfirmPayload,
  TripCosts,
  TripHistoryEntry,
  TripPayload,
} from '../../models/trip/trip.model';
import { TripTicket } from '../../models/trip/trip-ticket.model';

@Injectable({ providedIn: 'root' })
export class TripService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Trip[]>> {
    return this.api.envelope<Trip[]>('trips', query);
  }

  find(id: string): Observable<Trip> {
    return this.api.get<Trip>(`trips/${id}`);
  }

  ticket(id: string): Observable<TripTicket> {
    return this.api.get<TripTicket>(`trips/${id}/ticket`);
  }

  history(id: string): Observable<TripHistoryEntry[]> {
    return this.api.get<TripHistoryEntry[]>(`trips/${id}/history`);
  }

  costs(id: string): Observable<TripCosts> {
    return this.api.get<TripCosts>(`trips/${id}/costs`);
  }

  create(payload: TripPayload): Observable<Trip> {
    return this.api.post<Trip>('trips', payload);
  }

  update(id: string, payload: Partial<TripPayload>): Observable<Trip> {
    return this.api.patch<Trip>(`trips/${id}`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`trips/${id}`);
  }

  confirm(id: string, payload: TripConfirmPayload): Observable<Trip> {
    return this.api.post<Trip>(`trips/${id}/confirm`, payload);
  }

  dispatch(id: string, location: string): Observable<Trip> {
    return this.api.post<Trip>(`trips/${id}/dispatch`, { location });
  }

  complete(id: string, receiver: string): Observable<Trip> {
    return this.api.post<Trip>(`trips/${id}/complete`, { receiver_name: receiver });
  }
}

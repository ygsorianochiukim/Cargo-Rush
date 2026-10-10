import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { Trip } from '../../models/trip/trip.model';
import {
  Trucker,
  TruckerVehicle,
  Wallet,
  WalletEntry,
  WalletEntryPayload,
} from '../../models/trucker/trucker.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';
import { ApiService } from '../shared/api.service';

@Injectable({ providedIn: 'root' })
export class TruckerService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Trucker[]>> {
    return this.api.envelope<Trucker[]>('truckers', query);
  }

  find(id: string): Observable<Trucker> {
    return this.api.get<Trucker>(`truckers/${id}`);
  }

  available(): Observable<Trucker[]> {
    return this.api.get<Trucker[]>('truckers/available');
  }

  approve(id: string): Observable<Trucker> {
    return this.api.post<Trucker>(`truckers/${id}/approve`, {});
  }

  suspend(id: string, reason?: string): Observable<Trucker> {
    return this.api.post<Trucker>(`truckers/${id}/suspend`, { reason: reason ?? null });
  }

  wallet(id: string, query?: ListQuery): Observable<Wallet> {
    return this.api.get<Wallet>(`truckers/${id}/wallet`, query);
  }

  settle(id: string, payload: WalletEntryPayload): Observable<WalletEntry> {
    return this.api.post<WalletEntry>(`truckers/${id}/wallet`, payload);
  }

  confirmPayment(truckerId: string, entryId: string): Observable<WalletEntry> {
    return this.api.post<WalletEntry>(`truckers/${truckerId}/wallet/${entryId}/confirm`, {});
  }

  vehicles(id: string): Observable<TruckerVehicle[]> {
    return this.api.get<TruckerVehicle[]>(`truckers/${id}/vehicles`);
  }

  verifyVehicle(truckerId: string, vehicleId: string): Observable<TruckerVehicle> {
    return this.api.post<TruckerVehicle>(`truckers/${truckerId}/vehicles/${vehicleId}/verify`, {});
  }

  rejectVehicle(truckerId: string, vehicleId: string, reason: string): Observable<TruckerVehicle> {
    return this.api.post<TruckerVehicle>(`truckers/${truckerId}/vehicles/${vehicleId}/reject`, { reason });
  }

  assign(tripId: string, truckerId: string, vehicleId?: string): Observable<Trip> {
    return this.api.post<Trip>(`trips/${tripId}/assign-trucker`, {
      trucker_id: truckerId,
      trucker_vehicle_id: vehicleId ?? null,
    });
  }

  release(tripId: string): Observable<Trip> {
    return this.api.deleteItem<Trip>(`trips/${tripId}/assign-trucker`);
  }
}

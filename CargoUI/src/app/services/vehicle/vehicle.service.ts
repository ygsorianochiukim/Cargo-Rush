import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';
import { StatusValue } from '../../models/shared/status.model';
import {
  MaintenanceJob,
  Vehicle,
  VehiclePayload,
} from '../../models/vehicle/vehicle.model';

/** Vehicle Management — DESIGN.md section 5.1. */
@Injectable({ providedIn: 'root' })
export class VehicleService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Vehicle[]>> {
    return this.api.envelope<Vehicle[]>('vehicles', query);
  }

  find(id: string): Observable<Vehicle> {
    return this.api.get<Vehicle>(`vehicles/${id}`);
  }

  create(payload: VehiclePayload): Observable<Vehicle> {
    return this.api.post<Vehicle>('vehicles', payload);
  }

  update(id: string, payload: Partial<VehiclePayload>): Observable<Vehicle> {
    return this.api.patch<Vehicle>(`vehicles/${id}`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`vehicles/${id}`);
  }

  /** Its own call: taking a unit off the road also releases its driver. */
  setStatus(id: string, status: StatusValue): Observable<Vehicle> {
    return this.api.post<Vehicle>(`vehicles/${id}/status`, { status });
  }

  maintenance(id: string): Observable<MaintenanceJob[]> {
    return this.api.get<MaintenanceJob[]>(`vehicles/${id}/maintenance`);
  }
}

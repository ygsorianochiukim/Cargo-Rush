import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { Envelope, ListQuery } from '../../models/shared/envelope.model';
import { MaintenanceJob, MaintenanceJobPayload } from '../../models/vehicle/vehicle.model';
import { ApiService } from '../shared/api.service';

@Injectable({ providedIn: 'root' })
export class MaintenanceService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<MaintenanceJob[]>> {
    return this.api.envelope<MaintenanceJob[]>('maintenance', query);
  }

  find(id: string): Observable<MaintenanceJob> {
    return this.api.get<MaintenanceJob>(`maintenance/${id}`);
  }

  create(payload: MaintenanceJobPayload): Observable<MaintenanceJob> {
    return this.api.post<MaintenanceJob>('maintenance', payload);
  }

  update(id: string, payload: Partial<MaintenanceJobPayload>): Observable<MaintenanceJob> {
    return this.api.patch<MaintenanceJob>(`maintenance/${id}`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`maintenance/${id}`);
  }
}

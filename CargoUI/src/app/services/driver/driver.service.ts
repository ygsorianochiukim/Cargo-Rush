import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { Driver, DriverPayload } from '../../models/driver/driver.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

/** Drivers Management — LTMS violations, licences, driver status. */
@Injectable({ providedIn: 'root' })
export class DriverService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Driver[]>> {
    return this.api.envelope<Driver[]>('drivers', query);
  }

  find(id: string): Observable<Driver> {
    return this.api.get<Driver>(`drivers/${id}`);
  }

  create(payload: DriverPayload): Observable<Driver> {
    return this.api.post<Driver>('drivers', payload);
  }

  update(id: string, payload: Partial<DriverPayload>): Observable<Driver> {
    return this.api.patch<Driver>(`drivers/${id}`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`drivers/${id}`);
  }

  /** The switch on the driver app's dashboard, also settable from the office. */
  setAvailability(id: string, available: boolean): Observable<Driver> {
    return this.api.post<Driver>(`drivers/${id}/availability`, { available });
  }
}

import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { GpsUnit, TrackingState } from '../../models/gps/gps.model';

@Injectable({ providedIn: 'root' })
export class GpsService {
  private readonly api = inject(ApiService);

  units(): Observable<GpsUnit[]> {
    return this.api.get<GpsUnit[]>('gps');
  }

  tracking(tripId: string): Observable<TrackingState> {
    return this.api.get<TrackingState>(`gps/trips/${tripId}/tracking`);
  }
}

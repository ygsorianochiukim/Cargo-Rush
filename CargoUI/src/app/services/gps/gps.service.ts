import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { GpsUnit, TrackingState } from '../../models/gps/gps.model';

/**
 * GPS Dashboard.
 *
 * Read-only here on purpose: the handset is the position source and the back
 * office is a reader (DESIGN.md section 5.4). There is no `record()` in this
 * client, and adding one would be the wrong shape of change.
 */
@Injectable({ providedIn: 'root' })
export class GpsService {
  private readonly api = inject(ApiService);

  /** Every unit on the road, with its latest position. */
  units(): Observable<GpsUnit[]> {
    return this.api.get<GpsUnit[]>('gps');
  }

  tracking(tripId: string): Observable<TrackingState> {
    return this.api.get<TrackingState>(`gps/trips/${tripId}/tracking`);
  }
}

import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import {
  ActivityEntry,
  DeliveryPoint,
  FleetBreakdown,
  Kpi,
  Receivables,
} from '../../models/dashboard/dashboard.model';

/**
 * The consolidated dashboard.
 *
 * Four independent calls rather than one fat endpoint, so a slow aggregate
 * cannot hold up the three tiles that were ready.
 */
@Injectable({ providedIn: 'root' })
export class DashboardService {
  private readonly api = inject(ApiService);

  kpis(): Observable<Kpi[]> {
    return this.api.get<Kpi[]>('dashboard/kpis');
  }

  fleet(): Observable<FleetBreakdown[]> {
    return this.api.get<FleetBreakdown[]>('dashboard/fleet');
  }

  deliveries(): Observable<DeliveryPoint[]> {
    return this.api.get<DeliveryPoint[]>('dashboard/deliveries');
  }

  activity(): Observable<ActivityEntry[]> {
    return this.api.get<ActivityEntry[]>('dashboard/activity');
  }

  /**
   * Receivables and income — pending payment against successful payment.
   *
   * A fifth call for the reason the other four are separate: it touches the
   * invoice table and the whole ledger, and a slow roll-up must not hold up
   * the tiles that were ready.
   */
  receivables(days?: number): Observable<Receivables> {
    return this.api.get<Receivables>('dashboard/receivables', days ? { days } : undefined);
  }
}

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

  receivables(days?: number): Observable<Receivables> {
    return this.api.get<Receivables>('dashboard/receivables', days ? { days } : undefined);
  }
}

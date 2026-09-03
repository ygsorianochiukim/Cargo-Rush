import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { DeliveryLog, DeliveryReport } from '../../models/delivery/delivery.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

/** Delivery Logs — the record, the proof, and the status report. */
@Injectable({ providedIn: 'root' })
export class DeliveryService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<DeliveryLog[]>> {
    return this.api.envelope<DeliveryLog[]>('delivery-logs', query);
  }

  find(id: string): Observable<DeliveryLog> {
    return this.api.get<DeliveryLog>(`delivery-logs/${id}`);
  }

  /** Pending / active / complete side by side. */
  report(): Observable<DeliveryReport> {
    return this.api.get<DeliveryReport>('delivery-logs/report');
  }
}

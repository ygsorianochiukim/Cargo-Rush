import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { DispatchRecord } from '../../models/dispatch/dispatch.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

/** Dispatch Monitoring — records are born with a trip, so arrival is the verb. */
@Injectable({ providedIn: 'root' })
export class DispatchService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<DispatchRecord[]>> {
    return this.api.envelope<DispatchRecord[]>('dispatch', query);
  }

  find(id: string): Observable<DispatchRecord> {
    return this.api.get<DispatchRecord>(`dispatch/${id}`);
  }

  arrive(id: string): Observable<DispatchRecord> {
    return this.api.post<DispatchRecord>(`dispatch/${id}/arrive`, {});
  }
}

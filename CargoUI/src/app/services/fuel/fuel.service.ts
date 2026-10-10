import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import {
  FuelBudget,
  FuelRecord,
  FuelRecordPayload,
} from '../../models/fuel/fuel.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

@Injectable({ providedIn: 'root' })
export class FuelService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<FuelRecord[]>> {
    return this.api.envelope<FuelRecord[]>('fuel', query);
  }

  create(payload: FuelRecordPayload): Observable<FuelRecord> {
    return this.api.post<FuelRecord>('fuel', payload);
  }

  update(id: string, payload: Partial<FuelRecordPayload>): Observable<FuelRecord> {
    return this.api.patch<FuelRecord>(`fuel/${id}`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`fuel/${id}`);
  }

  budget(date?: string): Observable<FuelBudget> {
    return this.api.get<FuelBudget>('fuel/budget', date ? { from: date } : undefined);
  }
}

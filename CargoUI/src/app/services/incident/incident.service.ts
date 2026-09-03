import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { Incident, IncidentPayload } from '../../models/incident/incident.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

/** Incident Management. Reporting one also raises a notification, API-side. */
@Injectable({ providedIn: 'root' })
export class IncidentService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Incident[]>> {
    return this.api.envelope<Incident[]>('incidents', query);
  }

  find(id: string): Observable<Incident> {
    return this.api.get<Incident>(`incidents/${id}`);
  }

  report(payload: IncidentPayload): Observable<Incident> {
    return this.api.post<Incident>('incidents', payload);
  }

  update(id: string, payload: Partial<IncidentPayload>): Observable<Incident> {
    return this.api.patch<Incident>(`incidents/${id}`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`incidents/${id}`);
  }
}

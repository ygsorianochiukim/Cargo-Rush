import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { Applicant, ApplicantStage, Employee, Pipeline } from '../../models/hr/hr.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

@Injectable({ providedIn: 'root' })
export class ApplicantService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Applicant[]>> {
    return this.api.envelope<Applicant[]>('applicants', query);
  }

  save(form: FormData, id?: string): Observable<Applicant> {
    return id
      ? this.api.postForm<Applicant>(`applicants/${id}`, form)
      : this.api.postForm<Applicant>('applicants', form);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`applicants/${id}`);
  }

  pipeline(): Observable<Pipeline> {
    return this.api.get<Pipeline>('applicants/pipeline');
  }

  moveTo(id: string, stage: ApplicantStage): Observable<Applicant> {
    return this.api.post<Applicant>(`applicants/${id}/stage`, { stage });
  }

  hire(
    id: string,
    overrides: {
      hired_on?: string;
      position?: string;
      position_id?: string;
      department?: string | null;
      employment_type?: string;
      pay_basis?: string;
      amount_cents?: number;
      driver_id?: string | null;
    } = {},
  ): Observable<Employee> {
    return this.api.post<Employee>(`applicants/${id}/hire`, overrides);
  }
}

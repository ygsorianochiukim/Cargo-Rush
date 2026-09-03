import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { Applicant, ApplicantStage, Employee, Pipeline } from '../../models/hr/hr.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

/** Applicants — the hiring pipeline in front of the roster. */
@Injectable({ providedIn: 'root' })
export class ApplicantService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Applicant[]>> {
    return this.api.envelope<Applicant[]>('applicants', query);
  }

  /** Multipart, for the same reason employees are: a CV and a photograph. */
  save(form: FormData, id?: string): Observable<Applicant> {
    return id
      ? this.api.postForm<Applicant>(`applicants/${id}`, form)
      : this.api.postForm<Applicant>('applicants', form);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`applicants/${id}`);
  }

  /** How many sit at each stage, empty stages included. */
  pipeline(): Observable<Pipeline> {
    return this.api.get<Pipeline>('applicants/pipeline');
  }

  /**
   * Move somebody along.
   *
   * A verb rather than a PATCH on `stage`, because it stamps the decision date
   * — and `hired` is refused here: hiring creates an employee record, and a
   * stage change that sometimes did that and sometimes did not would be a trap.
   */
  moveTo(id: string, stage: ApplicantStage): Observable<Applicant> {
    return this.api.post<Applicant>(`applicants/${id}/stage`, { stage });
  }

  /**
   * Hire them — the employee record is built from the application.
   *
   * Returns the new employee, because that is what the office does next: give
   * them a login, set their salary, put them on a truck.
   */
  hire(
    id: string,
    overrides: {
      hired_on?: string;
      position?: string;
      department?: string | null;
      employment_type?: string;
      base_salary_cents?: number;
      driver_id?: string | null;
    } = {},
  ): Observable<Employee> {
    return this.api.post<Employee>(`applicants/${id}/hire`, overrides);
  }
}

import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { Employee, ModuleState, RosterOverview, StaffCredentials } from '../../models/hr/hr.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

/** Employees — the roster, their logins, and what each of those can see. */
@Injectable({ providedIn: 'root' })
export class EmployeeService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Employee[]>> {
    return this.api.envelope<Employee[]>('employees', query);
  }

  /**
   * Register or edit somebody, photograph included.
   *
   * Always multipart and always a POST: PHP does not populate `$_FILES` on a
   * PUT or a PATCH, so an edit carries `_method` in the body instead — the
   * override Laravel already reads. `FormData` is built by the caller, which
   * drops the fields it was not given rather than sending them as empty
   * strings that would overwrite real values with blanks.
   */
  save(form: FormData, id?: string): Observable<Employee> {
    return id
      ? this.api.postForm<Employee>(`employees/${id}`, form)
      : this.api.postForm<Employee>('employees', form);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`employees/${id}`);
  }

  /** Headcount, the shape of the roster, and who still has no login. */
  overview(): Observable<RosterOverview> {
    return this.api.get<RosterOverview>('employees/overview');
  }

  /**
   * Give an employee a login.
   *
   * The envelope, not just the record: the password to hand over is in `meta`
   * and is shown once. There is no endpoint that will say it again.
   */
  createAccount(
    id: string,
    payload: { email: string; role: string; password?: string },
  ): Observable<{ employee: Employee; credentials: StaffCredentials | null }> {
    return this.api.postEnvelope<Employee>(`employees/${id}/account`, payload).pipe(
      map((envelope) => ({
        employee: envelope.data,
        credentials: (envelope.meta?.['credentials'] as StaffCredentials | undefined) ?? null,
      })),
    );
  }

  assignRole(id: string, role: string): Observable<Employee> {
    return this.api.post<Employee>(`employees/${id}/role`, { role });
  }

  modules(id: string): Observable<ModuleState> {
    return this.api.get<ModuleState>(`employees/${id}/modules`);
  }

  /**
   * Choose which modules the account sees.
   *
   * An empty array is the meaningful instruction that clears the assignment
   * and restores the default — everything the role allows. `rejected` in the
   * meta names anything the role could not open, so the UI can say why a box
   * would not stick rather than letting it silently spring back.
   */
  assignModules(
    id: string,
    modules: string[],
  ): Observable<{ state: ModuleState; rejected: string[] }> {
    return this.api.putEnvelope<ModuleState>(`employees/${id}/modules`, { modules }).pipe(
      map((envelope) => ({
        state: envelope.data,
        rejected: (envelope.meta?.['rejected'] as string[] | undefined) ?? [],
      })),
    );
  }
}

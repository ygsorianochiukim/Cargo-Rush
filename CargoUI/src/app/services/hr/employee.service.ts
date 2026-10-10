import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { Employee, ModuleState, RosterOverview, StaffCredentials } from '../../models/hr/hr.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

@Injectable({ providedIn: 'root' })
export class EmployeeService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Employee[]>> {
    return this.api.envelope<Employee[]>('employees', query);
  }

  save(form: FormData, id?: string): Observable<Employee> {
    return id
      ? this.api.postForm<Employee>(`employees/${id}`, form)
      : this.api.postForm<Employee>('employees', form);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`employees/${id}`);
  }

  overview(): Observable<RosterOverview> {
    return this.api.get<RosterOverview>('employees/overview');
  }

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

import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { CrewPayReport, CrewPayRosterRow } from '../../models/hr/payroll.model';

@Injectable({ providedIn: 'root' })
export class CrewPayReportService {
  private readonly api = inject(ApiService);

  roster(): Observable<CrewPayRosterRow[]> {
    return this.api.get<CrewPayRosterRow[]>('payroll/crew-report');
  }

  forEmployee(employeeId: string, from: string, to: string): Observable<CrewPayReport> {
    return this.api.get<CrewPayReport>(`payroll/crew-report/${employeeId}`, { from, to } as never);
  }
}

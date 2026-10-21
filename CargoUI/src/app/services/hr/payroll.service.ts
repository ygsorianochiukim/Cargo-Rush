import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { ApiService } from '../shared/api.service';
import {
  CutoffRequestStatus,
  EmployeePayComponent,
  EmployeePayComponentPayload,
  PayComponent,
  PayComponentPayload,
  PayPeriodOption,
  PayRun,
  PayRunLinePayload,
  PayRunPayload,
  PayrollCutoffRequest,
  PayrollCutoffRequestPayload,
  StoreCredit,
  StoreCreditPayload,
  StoreCreditState,
} from '../../models/hr/payroll.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

export interface PayrollQuery extends ListQuery {
  status?: string;
  from?: string;
  to?: string;
}

@Injectable({ providedIn: 'root' })
export class PayrollService {
  private readonly api = inject(ApiService);

  list(query?: PayrollQuery): Observable<Envelope<PayRun[]>> {
    return this.api.envelope<PayRun[]>('payroll', query);
  }

  periods(month?: string): Observable<Envelope<PayPeriodOption[]>> {
    return this.api.envelope<PayPeriodOption[]>(
      'payroll/periods',
      month === undefined || month === '' ? undefined : { month },
    );
  }

  find(id: string): Observable<PayRun> {
    return this.api.get<PayRun>(`payroll/${id}`);
  }

  create(payload: PayRunPayload): Observable<PayRun> {
    return this.api.post<PayRun>('payroll', payload);
  }

  rebuild(id: string): Observable<PayRun> {
    return this.api.post<PayRun>(`payroll/${id}/rebuild`, {});
  }

  adjustLine(id: string, lineId: string, payload: PayRunLinePayload): Observable<PayRun> {
    return this.api.patch<PayRun>(`payroll/${id}/lines/${lineId}`, payload);
  }

  addDeduction(
    id: string,
    lineId: string,
    payload: { pay_component_id?: string | null; name?: string | null; amount_cents: number },
  ): Observable<PayRun> {
    return this.api.post<PayRun>(`payroll/${id}/lines/${lineId}/deductions`, payload);
  }

  removeDeduction(id: string, lineId: string, componentId: string): Observable<PayRun> {
    return this.api.deleteItem<PayRun>(`payroll/${id}/lines/${lineId}/deductions/${componentId}`);
  }

  approve(id: string): Observable<PayRun> {
    return this.api.post<PayRun>(`payroll/${id}/approve`, {});
  }

  pay(id: string): Observable<PayRun> {
    return this.api.post<PayRun>(`payroll/${id}/pay`, {});
  }

  pdf(id: string, lineId?: string): Observable<Blob> {
    const query = lineId ? `?line=${encodeURIComponent(lineId)}` : '';

    return this.api.blob(`payroll/${id}/document.pdf${query}`);
  }

  nextRemittanceNo(on?: string): Observable<string> {
    return this.api
      .get<{ remittance_no: string }>(
        'payroll/remittance-number',
        on ? ({ on } as never) : undefined,
      )
      .pipe(map((row) => row.remittance_no));
  }

  remit(
    id: string,
    payload: { remitted_on: string; reference?: string | null },
  ): Observable<PayRun> {
    return this.api.post<PayRun>(`payroll/${id}/remit`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`payroll/${id}`);
  }
}

@Injectable({ providedIn: 'root' })
export class PayComponentService {
  private readonly api = inject(ApiService);

  list(activeOnly = false): Observable<Envelope<PayComponent[]>> {
    return this.api.envelope<PayComponent[]>(
      'payroll/components',
      activeOnly ? { active: 1 } : undefined,
    );
  }

  create(payload: PayComponentPayload): Observable<PayComponent> {
    return this.api.post<PayComponent>('payroll/components', payload);
  }

  update(id: string, payload: PayComponentPayload): Observable<PayComponent> {
    return this.api.patch<PayComponent>(`payroll/components/${id}`, payload);
  }

  remove(id: string): Observable<{ retired: PayComponent | null; reason: string | null }> {
    return this.api.deleteEnvelope<PayComponent>(`payroll/components/${id}`).pipe(
      map((envelope) => ({
        retired: envelope?.data ?? null,
        reason: (envelope?.meta?.['reason'] as string | undefined) ?? null,
      })),
    );
  }

  assignmentsFor(employeeId: string): Observable<Envelope<EmployeePayComponent[]>> {
    return this.api.envelope<EmployeePayComponent[]>(`employees/${employeeId}/pay-components`);
  }

  assign(payload: EmployeePayComponentPayload): Observable<EmployeePayComponent> {
    return this.api.post<EmployeePayComponent>('payroll/assignments', payload);
  }

  updateAssignment(
    id: string,
    payload: EmployeePayComponentPayload,
  ): Observable<EmployeePayComponent> {
    return this.api.patch<EmployeePayComponent>(`payroll/assignments/${id}`, payload);
  }

  unassign(id: string): Observable<void> {
    return this.api.delete(`payroll/assignments/${id}`);
  }
}

@Injectable({ providedIn: 'root' })
export class PayrollCutoffRequestService {
  private readonly api = inject(ApiService);

  list(status?: CutoffRequestStatus): Observable<Envelope<PayrollCutoffRequest[]>> {
    return this.api.envelope<PayrollCutoffRequest[]>(
      'payroll/cutoff-requests',
      status === undefined ? undefined : { status },
    );
  }

  pending(): Observable<PayrollCutoffRequest | null> {
    return this.api
      .get<PayrollCutoffRequest | PayrollCutoffRequest[]>('payroll/cutoff-requests/pending')
      .pipe(map((data) => (Array.isArray(data) ? null : data)));
  }

  file(payload: PayrollCutoffRequestPayload): Observable<PayrollCutoffRequest> {
    return this.api.post<PayrollCutoffRequest>('payroll/cutoff-requests', payload);
  }

  approve(id: string, note?: string): Observable<PayrollCutoffRequest> {
    return this.api.post<PayrollCutoffRequest>(`payroll/cutoff-requests/${id}/approve`, {
      ...(note ? { note } : {}),
    });
  }

  decline(id: string, note?: string): Observable<PayrollCutoffRequest> {
    return this.api.post<PayrollCutoffRequest>(`payroll/cutoff-requests/${id}/decline`, {
      ...(note ? { note } : {}),
    });
  }

  withdraw(id: string): Observable<PayrollCutoffRequest> {
    return this.api.deleteItem<PayrollCutoffRequest>(`payroll/cutoff-requests/${id}`);
  }
}

@Injectable({ providedIn: 'root' })
export class StoreCreditService {
  private readonly api = inject(ApiService);

  forEmployee(employeeId: string): Observable<StoreCreditState> {
    return this.api.envelope<StoreCredit[]>(`employees/${employeeId}/store-credits`).pipe(
      map((envelope) => ({
        rows: envelope.data,
        balance_cents: Number(envelope.meta?.['balance_cents'] ?? 0),
        next_deduction_cents: Number(envelope.meta?.['next_deduction_cents'] ?? 0),
      })),
    );
  }

  add(employeeId: string, payload: StoreCreditPayload): Observable<StoreCredit> {
    return this.api.post<StoreCredit>(`employees/${employeeId}/store-credits`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`store-credits/${id}`);
  }
}

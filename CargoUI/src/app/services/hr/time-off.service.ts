import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import {
  CrewPerformance,
  LeavePayload,
  LeaveRequest,
  PerformanceReport,
  TimeOffOverview,
  UndertimePayload,
  UndertimeRequest,
} from '../../models/hr/time-off.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

/** Leave, undertime and performance. */
@Injectable({ providedIn: 'root' })
export class TimeOffService {
  private readonly api = inject(ApiService);

  overview(): Observable<TimeOffOverview> {
    return this.api.get<TimeOffOverview>('hr/time-off');
  }

  /* --------------------------------------------------------------- Leave */

  leave(query?: ListQuery): Observable<Envelope<LeaveRequest[]>> {
    return this.api.envelope<LeaveRequest[]>('hr/leave', query);
  }

  requestLeave(payload: LeavePayload): Observable<LeaveRequest> {
    return this.api.post<LeaveRequest>('hr/leave', payload);
  }

  updateLeave(id: string, payload: LeavePayload): Observable<LeaveRequest> {
    return this.api.patch<LeaveRequest>(`hr/leave/${id}`, payload);
  }

  /**
   * Approve or reject.
   *
   * A verb rather than a PATCH on `status`, because the API also records who
   * decided and when — a status a client could set would let a request be
   * approved by nobody.
   */
  decideLeave(
    id: string,
    decision: 'approved' | 'rejected',
    note?: string,
  ): Observable<LeaveRequest> {
    return this.api.post<LeaveRequest>(`hr/leave/${id}/decision`, { decision, note: note ?? null });
  }

  withdrawLeave(id: string): Observable<LeaveRequest> {
    return this.api.post<LeaveRequest>(`hr/leave/${id}/withdraw`, {});
  }

  removeLeave(id: string): Observable<void> {
    return this.api.delete(`hr/leave/${id}`);
  }

  /* ----------------------------------------------------------- Undertime */

  undertime(query?: ListQuery): Observable<Envelope<UndertimeRequest[]>> {
    return this.api.envelope<UndertimeRequest[]>('hr/undertime', query);
  }

  requestUndertime(payload: UndertimePayload): Observable<UndertimeRequest> {
    return this.api.post<UndertimeRequest>('hr/undertime', payload);
  }

  updateUndertime(id: string, payload: UndertimePayload): Observable<UndertimeRequest> {
    return this.api.patch<UndertimeRequest>(`hr/undertime/${id}`, payload);
  }

  decideUndertime(
    id: string,
    decision: 'approved' | 'rejected',
    note?: string,
  ): Observable<UndertimeRequest> {
    return this.api.post<UndertimeRequest>(`hr/undertime/${id}/decision`, {
      decision,
      note: note ?? null,
    });
  }

  withdrawUndertime(id: string): Observable<UndertimeRequest> {
    return this.api.post<UndertimeRequest>(`hr/undertime/${id}/withdraw`, {});
  }

  removeUndertime(id: string): Observable<void> {
    return this.api.delete(`hr/undertime/${id}`);
  }

  /* --------------------------------------------------------- Performance */

  performance(range?: { from: string; to: string }): Observable<PerformanceReport> {
    return this.api.get<PerformanceReport>('hr/performance', range);
  }

  performanceFor(
    employeeId: string,
    range?: { from: string; to: string },
  ): Observable<CrewPerformance> {
    return this.api.get<CrewPerformance>(`hr/performance/${employeeId}`, range);
  }
}

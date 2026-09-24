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

/** A run list narrows by state and by the period it covers. */
export interface PayrollQuery extends ListQuery {
  status?: string;
  from?: string;
  to?: string;
}

/**
 * Payroll — building a run, correcting it, freezing it, paying it.
 *
 * Four verbs, and every one of them is a verb rather than a status PATCH, for
 * the reason posting a journal entry is: **approve** freezes figures and tells
 * the office, and **pay** writes the journal entry that puts the run in the
 * books. A status field that did either when set to a particular value would
 * hide what actually happened.
 *
 * `rebuild` is a POST rather than a PUT because it is not an edit: it throws
 * the lines away and works them out again from the employee records as they now
 * stand. Merging would leave somebody paid from two different calculations.
 */
@Injectable({ providedIn: 'root' })
export class PayrollService {
  private readonly api = inject(ApiService);

  list(query?: PayrollQuery): Observable<Envelope<PayRun[]>> {
    return this.api.envelope<PayRun[]>('payroll', query);
  }

  /**
   * The pay periods a month is allowed to have — the choice on screen.
   *
   * Which days a period runs between is the **company's** setting, and the API
   * owns working it out — so this answer differs per haulier and a client never
   * does calendar arithmetic of its own. The envelope's `meta.calendar` carries
   * the firm's cutoff days and a worked example, for a screen that wants to say
   * *why* these are the two choices.
   *
   * With no month it answers with the month holding the period that has just
   * closed and flags it, so the page opens on the run somebody has come to pay
   * without doing any calendar arithmetic of its own.
   */
  periods(month?: string): Observable<Envelope<PayPeriodOption[]>> {
    return this.api.envelope<PayPeriodOption[]>(
      'payroll/periods',
      month === undefined || month === '' ? undefined : { month },
    );
  }

  find(id: string): Observable<PayRun> {
    return this.api.get<PayRun>(`payroll/${id}`);
  }

  /** Open a run for a period and work everybody's pay out. */
  create(payload: PayRunPayload): Observable<PayRun> {
    return this.api.post<PayRun>('payroll', payload);
  }

  /** Work it out again, from the employee records as they now stand. */
  rebuild(id: string): Observable<PayRun> {
    return this.api.post<PayRun>(`payroll/${id}/rebuild`, {});
  }

  /** Correct one payslip. The whole run comes back, so the totals follow. */
  adjustLine(id: string, lineId: string, payload: PayRunLinePayload): Observable<PayRun> {
    return this.api.patch<PayRun>(`payroll/${id}/lines/${lineId}`, payload);
  }

  /**
   * Put a deduction on one payslip, for this run only.
   *
   * The charge no assignment exists for — a uniform, a breakage, a cash advance
   * against this fortnight. Either half names it: a `pay_component_id` takes
   * the name from the firm's catalogue so the same charge is spelled the same
   * way every time, a `name` on its own is the one-off nobody will catalogue.
   *
   * Returns the whole run, like every other edit here: the line's totals move
   * and so does the run's, and re-reading one figure would leave the other
   * stale on screen.
   */
  addDeduction(
    id: string,
    lineId: string,
    payload: { pay_component_id?: string | null; name?: string | null; amount_cents: number },
  ): Observable<PayRun> {
    return this.api.post<PayRun>(`payroll/${id}/lines/${lineId}/deductions`, payload);
  }

  /** Take one back off. Only the hand-added ones — the API refuses the rest. */
  removeDeduction(id: string, lineId: string, componentId: string): Observable<PayRun> {
    return this.api.deleteItem<PayRun>(`payroll/${id}/lines/${lineId}/deductions/${componentId}`);
  }

  /** Freeze it. Payslips can go out from here, so nothing may move after. */
  approve(id: string): Observable<PayRun> {
    return this.api.post<PayRun>(`payroll/${id}/approve`, {});
  }

  /** The money has gone out — and this is what posts it to the books. */
  pay(id: string): Observable<PayRun> {
    return this.api.post<PayRun>(`payroll/${id}/pay`, {});
  }

  /** Delete a draft. An approved run has been shown to people. */
  remove(id: string): Observable<void> {
    return this.api.delete(`payroll/${id}`);
  }
}

/**
 * The firm's salary structure — its allowances and deductions, and who is on
 * them.
 *
 * Kept beside payroll rather than under HR because it is the same room: what
 * somebody is paid on top of their basic is the private business a payslip is,
 * and the API gates it on `payroll.view` for exactly that reason.
 *
 * Nothing here is paginated, and that is a decision rather than an omission. A
 * firm's list of allowances is a dozen rows and stays a dozen rows, as is one
 * person's — paging either would mean a form had to fetch twice to render once.
 */
@Injectable({ providedIn: 'root' })
export class PayComponentService {
  private readonly api = inject(ApiService);

  /**
   * The catalogue.
   *
   * `active` narrows it to what payroll will actually use next time. The
   * settings screen wants everything, including what has been retired, because
   * retiring is reversible and a row nobody can see cannot be brought back.
   */
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

  /**
   * Remove a component — or retire it, where staff are on it.
   *
   * The API decides which, and answers differently: 204 for a delete, and 200
   * with the retired row for the other. Both stop it appearing on new payslips,
   * which is what was asked; what differs is that deleting one in use would
   * take every assignment with it, so a firm that removed a COLA by mistake
   * could not put it back.
   *
   * Typed as the row-or-nothing it is, so a caller has to handle both rather
   * than assuming the optimistic case — and through `deleteEnvelope` rather
   * than `deleteItem`, because the latter reads `.data` off a body a 204 does
   * not have.
   *
   * `meta.reason` carries the sentence to show when it was retired.
   */
  remove(id: string): Observable<{ retired: PayComponent | null; reason: string | null }> {
    return this.api.deleteEnvelope<PayComponent>(`payroll/components/${id}`).pipe(
      map((envelope) => ({
        retired: envelope?.data ?? null,
        reason: (envelope?.meta?.['reason'] as string | undefined) ?? null,
      })),
    );
  }

  /** What one person is paid on top of their basic. */
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

/**
 * Requests to move the firm's pay cutoff.
 *
 * Two audiences, two permissions, one service. Whoever runs payroll can `file`
 * and `withdraw`; only whoever holds the company settings can `approve` or
 * `decline` — and approving is what actually moves the cutoff, so it is a POST
 * to a verb rather than a status PATCH, exactly as approving a pay run is.
 *
 * `pending()` is its own call rather than a filter on the list because it
 * answers a different question: every screen that offers the request button
 * needs to know whether to offer it, and one that fetches and scans a log to
 * find out is one that fetches a log on every page load.
 */
@Injectable({ providedIn: 'root' })
export class PayrollCutoffRequestService {
  private readonly api = inject(ApiService);

  list(status?: CutoffRequestStatus): Observable<Envelope<PayrollCutoffRequest[]>> {
    return this.api.envelope<PayrollCutoffRequest[]>(
      'payroll/cutoff-requests',
      status === undefined ? undefined : { status },
    );
  }

  /**
   * The one waiting on a decision, or null.
   *
   * The API answers with an empty array when there is nothing waiting rather
   * than a 404, so "nobody has asked" is an ordinary answer instead of an
   * error every caller has to catch.
   */
  pending(): Observable<PayrollCutoffRequest | null> {
    return this.api
      .get<PayrollCutoffRequest | PayrollCutoffRequest[]>('payroll/cutoff-requests/pending')
      .pipe(map((data) => (Array.isArray(data) ? null : data)));
  }

  file(payload: PayrollCutoffRequestPayload): Observable<PayrollCutoffRequest> {
    return this.api.post<PayrollCutoffRequest>('payroll/cutoff-requests', payload);
  }

  /** Approving is what moves the cutoff. See the class note. */
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

  /**
   * The asker taking it back.
   *
   * A DELETE on the wire because that is the verb the screen offers, and a
   * *withdrawal* in the data — so it answers with the settled row rather than
   * a 204.
   */
  withdraw(id: string): Observable<PayrollCutoffRequest> {
    return this.api.deleteItem<PayrollCutoffRequest>(`payroll/cutoff-requests/${id}`);
  }
}

/**
 * The store tab — the mini-mart *pautang*, per person.
 *
 * A charge is goods taken against pay; a payment is money back. The balance is
 * the difference, and payroll takes it off at the cutoff.
 *
 * Its own service rather than a corner of `PayrollService` above, because it is
 * read from the roster screen and written at the till — two places that have
 * nothing to do with building a run.
 */
@Injectable({ providedIn: 'root' })
export class StoreCreditService {
  private readonly api = inject(ApiService);

  /**
   * One person's tab: the balance, and the rows behind it.
   *
   * Both, because they answer different questions. The balance is what the next
   * payslip takes; the rows are what it is made of, and a balance with nothing
   * behind it is a figure somebody has to take on trust.
   */
  forEmployee(employeeId: string): Observable<StoreCreditState> {
    return this.api.envelope<StoreCredit[]>(`employees/${employeeId}/store-credits`).pipe(
      map((envelope) => ({
        rows: envelope.data,
        balance_cents: Number(envelope.meta?.['balance_cents'] ?? 0),
        next_deduction_cents: Number(envelope.meta?.['next_deduction_cents'] ?? 0),
        cap_cents: Number(envelope.meta?.['cap_cents'] ?? 0),
      })),
    );
  }

  add(employeeId: string, payload: StoreCreditPayload): Observable<StoreCredit> {
    return this.api.post<StoreCredit>(`employees/${employeeId}/store-credits`, payload);
  }

  /**
   * Remove a row the storekeeper mis-keyed.
   *
   * The API refuses one payroll wrote — that figure is on a payslip somebody
   * has been handed — so a client should offer this only where `from_payroll`
   * is false.
   */
  remove(id: string): Observable<void> {
    return this.api.delete(`store-credits/${id}`);
  }
}

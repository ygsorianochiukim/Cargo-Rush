import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Observable } from 'rxjs';

import { Employee } from '../../models/hr/hr.model';
import { LeaveRequest, TimeOffOverview, UndertimeRequest } from '../../models/hr/time-off.model';
import { EmployeeService } from '../../services/hr/employee.service';
import { TimeOffService } from '../../services/hr/time-off.service';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { Modal } from '../../shared/modal';
import { TONE_CLASS } from '../../shared/status';
import { ErrorState, SkeletonRows } from '../../shared/states';

/** Leave & Undertime — one queue, two kinds of request. */
@Component({
  selector: 'app-time-off',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Field, Icon, Modal, ErrorState, SkeletonRows, ReactiveFormsModule],
  templateUrl: './time-off.page.html',
})
export class TimeOffPage {
  private readonly timeOffApi = inject(TimeOffService);
  private readonly employeesApi = inject(EmployeeService);
  private readonly confirm = inject(Confirm);
  private readonly fb = inject(FormBuilder);

  protected readonly fmt = fmt;
  protected readonly toneClass = TONE_CLASS;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  /** Which table is on screen. The desk works one queue but files two things. */
  protected readonly tab = signal<'leave' | 'undertime'>('leave');

  /** Null while loading — the four list states depend on telling that apart. */
  protected readonly leave = signal<LeaveRequest[] | null>(null);
  protected readonly undertime = signal<UndertimeRequest[] | null>(null);
  protected readonly overview = signal<TimeOffOverview | null>(null);
  protected readonly staff = signal<Employee[]>([]);
  protected readonly loadError = signal<string | null>(null);

  protected readonly onlyOpen = signal(false);

  protected readonly formOpen = signal(false);
  protected readonly busy = signal(false);
  protected readonly formError = signal<string | null>(null);

  protected readonly leaveForm = this.fb.group({
    employee_id: ['', Validators.required],
    type: ['vacation', Validators.required],
    starts_on: ['', Validators.required],
    ends_on: ['', Validators.required],
    reason: ['', Validators.required],
  });

  protected readonly undertimeForm = this.fb.group({
    employee_id: ['', Validators.required],
    date: ['', Validators.required],
    from_time: ['', Validators.required],
    to_time: ['', Validators.required],
    reason: ['', Validators.required],
  });

  constructor() {
    this.refresh();

    this.employeesApi.list({ status: 'active', per_page: 100 }).subscribe({
      next: (res) => this.staff.set(res.data),
      error: () => this.staff.set([]),
    });
  }

  protected refresh(): void {
    const query = this.onlyOpen() ? { open: 1 } : undefined;

    this.timeOffApi.leave(query).subscribe({
      next: (res) => {
        this.leave.set(res.data);
        this.loadError.set(null);
      },
      error: () => {
        this.leave.set(null);
        this.loadError.set('Could not load these requests. Check the connection and try again.');
      },
    });

    this.timeOffApi.undertime(query).subscribe({
      next: (res) => this.undertime.set(res.data),
      error: () => this.undertime.set(null),
    });

    this.timeOffApi.overview().subscribe({
      next: (o) => this.overview.set(o),
      error: () => this.overview.set(null),
    });
  }

  protected toggleOpenOnly(): void {
    this.onlyOpen.set(!this.onlyOpen());
    this.refresh();
  }

  protected readonly showingLeave = computed(() => this.tab() === 'leave');

  protected readonly rows = computed(() => (this.showingLeave() ? this.leave() : this.undertime()));

  protected readonly isEmpty = computed(() => (this.rows() ?? []).length === 0);

  /* --------------------------------------------------------------- Asking */

  protected openForm(): void {
    this.formOpen.set(true);
    this.formError.set(null);

    const today = new Date().toISOString().slice(0, 10);

    this.leaveForm.reset({
      employee_id: '',
      type: 'vacation',
      starts_on: today,
      ends_on: today,
      reason: '',
    });

    this.undertimeForm.reset({
      employee_id: '',
      date: today,
      from_time: '15:00',
      to_time: '17:00',
      reason: '',
    });
  }

  protected submit(): void {
    const form = this.showingLeave() ? this.leaveForm : this.undertimeForm;

    if (form.invalid) {
      form.markAllAsTouched();

      return;
    }

    this.busy.set(true);
    this.formError.set(null);

    // Typed as unknown: the two calls return different request shapes, and
    // nothing here reads the result — the list is refetched either way.
    const request: Observable<unknown> = this.showingLeave()
      ? this.timeOffApi.requestLeave(this.leaveForm.getRawValue() as never)
      : this.timeOffApi.requestUndertime(this.undertimeForm.getRawValue() as never);

    request.subscribe({
      next: () => {
        this.busy.set(false);
        this.formOpen.set(false);
        this.refresh();
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.formError.set(this.messageFor(error));
      },
    });
  }

  /* ------------------------------------------------------------- Deciding */

  protected decide(id: string, decision: 'approved' | 'rejected', note?: string): void {
    const request: Observable<unknown> = this.showingLeave()
      ? this.timeOffApi.decideLeave(id, decision, note)
      : this.timeOffApi.decideUndertime(id, decision, note);

    request.subscribe({
      next: () => this.refresh(),
      error: (error: HttpErrorResponse) => this.loadError.set(this.messageFor(error)),
    });
  }

  protected async reject(id: string, who: string): Promise<void> {
    const ok = await this.confirm.ask({
      title: `Reject ${who}'s request?`,
      body: 'They will see the decision and who made it.',
      confirmLabel: 'Reject',
      danger: true,
    });

    if (ok) this.decide(id, 'rejected');
  }

  protected async withdraw(id: string, who: string): Promise<void> {
    const ok = await this.confirm.ask({
      title: `Withdraw ${who}'s request?`,
      body: 'It stops counting against their record.',
      confirmLabel: 'Withdraw',
      danger: true,
    });

    if (!ok) return;

    const request: Observable<unknown> = this.showingLeave()
      ? this.timeOffApi.withdrawLeave(id)
      : this.timeOffApi.withdrawUndertime(id);

    request.subscribe({
      next: () => this.refresh(),
      error: (error: HttpErrorResponse) => this.loadError.set(this.messageFor(error)),
    });
  }

  /** The API's own words. A 422 here is a real rule, not a form slip. */
  private messageFor(error: HttpErrorResponse): string {
    const errors = error.error?.errors as Record<string, string[]> | undefined;

    if (error.status === 422 && errors) {
      return Object.values(errors)[0]?.[0] ?? 'Check the dates and try again.';
    }

    return error.error?.message ?? 'Could not do that. Check the connection and try again.';
  }

  protected readonly staffOptions = computed(() =>
    this.staff().map((e) => ({ value: e.id, label: `${e.full_name} — ${e.position}` })),
  );

  protected readonly initials = (name: string | null): string =>
    (name ?? '?')
      .split(' ')
      .slice(0, 2)
      .map((part) => part.charAt(0))
      .join('')
      .toUpperCase();
}

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { map } from 'rxjs';

import { Employee, ModuleState, RosterOverview, StaffCredentials } from '../../models/hr/hr.model';
import { employeeSpec } from '../../services/hr/employee.form';
import { EmployeeService } from '../../services/hr/employee.service';
import { AccessService } from '../../services/identity/access.service';
import { Card } from '../../shared/card';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { ListToolbar } from '../../shared/list-toolbar';
import { Modal } from '../../shared/modal';
import { recordList } from '../../shared/record-list';
import { ErrorState, SkeletonRows } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';

/**
 * Employees — the roster, and the access that goes with it.
 *
 * The record itself goes through the shared record form, because it really is
 * a flat record. What does not is everything about the login: creating one has
 * a password in it that is shown once, and module assignment is a set of
 * checkboxes whose available options depend on the role. Both live in the
 * access panel here rather than being bolted onto the generic form.
 *
 * The rule that shapes the panel: module assignment narrows what a role
 * already allows and can never widen it. So the boxes are drawn from what the
 * API says the role permits, and anything the server refuses comes back named
 * rather than silently unticking itself.
 */
@Component({
  selector: 'app-employees',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    Card,
    Field,
    Icon,
    ListToolbar,
    Modal,
    ErrorState,
    SkeletonRows,
    StatusPill,
    ReactiveFormsModule,
  ],
  templateUrl: './employees.page.html',
})
export class EmployeesPage {
  private readonly employeesApi = inject(EmployeeService);
  private readonly accessApi = inject(AccessService);
  private readonly fb = inject(FormBuilder);

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  private readonly spec = employeeSpec();

  protected readonly list = recordList<Employee>(this.spec, () =>
    this.employeesApi.list().pipe(map((res) => res.data)),
  );

  protected readonly rows = this.list.rows;

  protected readonly overview = signal<RosterOverview | null>(null);

  /* ------------------------------------------------------------- Access */

  protected readonly accessOpen = signal(false);
  protected readonly subject = signal<Employee | null>(null);
  protected readonly moduleState = signal<ModuleState | null>(null);
  protected readonly credentials = signal<StaffCredentials | null>(null);
  protected readonly accessError = signal<string | null>(null);
  protected readonly accessNotice = signal<string | null>(null);
  protected readonly busy = signal(false);

  /** Which module keys are ticked. A local mirror the checkboxes drive. */
  protected readonly picked = signal<Set<string>>(new Set());

  protected readonly accountForm = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    role: ['dispatcher', Validators.required],
    password: [''],
  });

  /**
   * The roles on offer, read from the API rather than hardcoded.
   *
   * A Treasury Officer the office added has to be assignable the moment it
   * exists, and `customer` is filtered out here as well as server-side: that
   * account belongs to a firm and is created from Customer Management.
   */
  protected readonly roles = signal<{ value: string; label: string }[]>([]);

  constructor() {
    this.refreshOverview();

    this.accessApi.roles(true).subscribe({
      next: (res) =>
        this.roles.set(
          res.data
            .filter((role) => role.key !== 'customer')
            .map((role) => ({ value: role.key, label: role.name })),
        ),
      error: () => this.roles.set([]),
    });
  }

  private refreshOverview(): void {
    this.employeesApi.overview().subscribe({
      next: (overview) => this.overview.set(overview),
      error: () => this.overview.set(null),
    });
  }

  protected openAccess(employee: Employee): void {
    this.subject.set(employee);
    this.accessOpen.set(true);
    this.credentials.set(null);
    this.accessError.set(null);
    this.accessNotice.set(null);
    this.moduleState.set(null);

    this.accountForm.reset({
      email: employee.email ?? '',
      // Their current role if they have one; otherwise the one their position
      // normally gets, so the office is not asked a question it already
      // answered when it picked the job title.
      role: employee.role ?? employee.suggested_role ?? this.roles()[0]?.value ?? '',
      password: '',
    });

    if (employee.has_account) this.loadModules(employee);
  }

  private loadModules(employee: Employee): void {
    this.employeesApi.modules(employee.id).subscribe({
      next: (state) => {
        this.moduleState.set(state);
        // No assignment means the default — everything the role allows — so
        // every box starts ticked rather than every box starting empty for an
        // account that in fact sees the lot.
        this.picked.set(
          new Set(state.customised ? state.assigned : state.available.map((m) => m.key)),
        );
      },
      error: () => this.accessError.set('Could not read what this account can see.'),
    });
  }

  protected createAccount(): void {
    const employee = this.subject();

    if (!employee || this.accountForm.invalid) {
      this.accountForm.markAllAsTouched();

      return;
    }

    this.busy.set(true);
    this.accessError.set(null);

    const { email, role, password } = this.accountForm.getRawValue();

    this.employeesApi
      .createAccount(employee.id, {
        email: String(email),
        role: String(role),
        ...(password ? { password: String(password) } : {}),
      })
      .subscribe({
        next: (result) => {
          this.busy.set(false);
          this.credentials.set(result.credentials);
          this.subject.set(result.employee);
          this.list.refresh();
          this.refreshOverview();
          this.loadModules(result.employee);
        },
        error: (error: HttpErrorResponse) => {
          this.busy.set(false);
          this.accessError.set(this.messageFor(error));
        },
      });
  }

  protected changeRole(): void {
    const employee = this.subject();
    const role = this.accountForm.getRawValue().role;

    if (!employee || !role) return;

    this.busy.set(true);
    this.accessError.set(null);

    this.employeesApi.assignRole(employee.id, String(role)).subscribe({
      next: (updated) => {
        this.busy.set(false);
        this.subject.set(updated);
        // A role change clears any module assignment, server-side: the old set
        // was a subset of what the old role could see. Re-reading is what shows
        // the promotion actually took.
        this.accessNotice.set(
          'Role updated. Any custom module selection was cleared, so this account sees everything the new role allows.',
        );
        this.loadModules(updated);
        this.list.refresh();
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.accessError.set(this.messageFor(error));
      },
    });
  }

  protected toggleModule(key: string): void {
    const next = new Set(this.picked());
    next.has(key) ? next.delete(key) : next.add(key);
    this.picked.set(next);
  }

  protected readonly isPicked = (key: string) => this.picked().has(key);

  /** Ticking everything is the same as no assignment at all, so say so. */
  protected readonly allPicked = computed(() => {
    const state = this.moduleState();

    return state !== null && state.available.every((m) => this.picked().has(m.key));
  });

  protected saveModules(): void {
    const employee = this.subject();
    const state = this.moduleState();

    if (!employee || !state) return;

    this.busy.set(true);
    this.accessError.set(null);
    this.accessNotice.set(null);

    // Everything ticked is sent as an empty list, which clears the assignment
    // and restores the default. Storing a row per module would mean a later
    // promotion silently kept the old role's menu.
    const modules = this.allPicked() ? [] : [...this.picked()];

    this.employeesApi.assignModules(employee.id, modules).subscribe({
      next: (result) => {
        this.busy.set(false);
        this.moduleState.set(result.state);
        this.accessNotice.set(
          result.rejected.length > 0
            ? `Not assigned: ${result.rejected.join(', ')}. This role has no permission for those modules, so a link to them would only fail on click.`
            : 'Saved.',
        );
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.accessError.set(this.messageFor(error));
      },
    });
  }

  private messageFor(error: HttpErrorResponse): string {
    const errors = error.error?.errors as Record<string, string[]> | undefined;

    if (error.status === 422 && errors) {
      return Object.values(errors)[0]?.[0] ?? 'Check the details and try again.';
    }

    return error.error?.message ?? 'Could not do that. Check the connection and try again.';
  }

  /* ------------------------------------------------------------- Roster */

  /**
   * The roster is cards, not the shared table, and the photograph is why.
   *
   * A staff list is one of the few places in this app where the picture is the
   * fastest way to find the row — the office knows the face before the payroll
   * number. A photograph does not fit a dense table, and each row here also
   * carries two actions rather than one, since editing the record and changing
   * what the account can reach are different jobs.
   */
  protected readonly initials = (employee: Employee): string =>
    `${employee.first_name.charAt(0)}${employee.last_name.charAt(0)}`.toUpperCase();

  /** Nobody hired yet is a different screen from a filter that matched nothing. */
  protected readonly isEmpty = computed(() => (this.rows() ?? []).length === 0);
}

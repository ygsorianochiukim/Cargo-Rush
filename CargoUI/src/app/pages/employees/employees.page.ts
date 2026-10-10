import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { map } from 'rxjs';

import { Employee, ModuleState, RosterOverview, StaffCredentials } from '../../models/hr/hr.model';
import { employeeSpec } from '../../services/hr/employee.form';
import { EmployeeService } from '../../services/hr/employee.service';
import { AccessService } from '../../services/identity/access.service';
import { Card } from '../../shared/card';
import { EmployeePayComponents } from './employee-pay-components';
import { EmployeeStoreCredits } from './employee-store-credits';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { ListToolbar } from '../../shared/list-toolbar';
import { Modal } from '../../shared/modal';
import { recordList } from '../../shared/record-list';
import { ErrorState, SkeletonRows } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';

@Component({
  selector: 'app-employees',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    Card,
    EmployeePayComponents,
    EmployeeStoreCredits,
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


  protected readonly accessOpen = signal(false);
  protected readonly subject = signal<Employee | null>(null);

  protected readonly moduleState = signal<ModuleState | null>(null);
  protected readonly credentials = signal<StaffCredentials | null>(null);
  protected readonly accessError = signal<string | null>(null);
  protected readonly accessNotice = signal<string | null>(null);
  protected readonly busy = signal(false);

  protected readonly picked = signal<Set<string>>(new Set());

  protected readonly accountForm = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    role: ['dispatcher', Validators.required],
    password: [''],
  });

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

  protected readonly payFor = signal<Employee | null>(null);
  protected readonly payOpen = signal(false);
  protected readonly tabFor = signal<Employee | null>(null);
  protected readonly tabOpen = signal(false);

  protected openPay(employee: Employee): void {
    this.payFor.set(employee);
    this.payOpen.set(true);
  }

  protected openTab(employee: Employee): void {
    this.tabFor.set(employee);
    this.tabOpen.set(true);
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
      role: employee.role ?? this.roles()[0]?.value ?? '',
      password: '',
    });

    if (employee.has_account) this.loadModules(employee);
  }

  private loadModules(employee: Employee): void {
    this.employeesApi.modules(employee.id).subscribe({
      next: (state) => {
        this.moduleState.set(state);
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


  protected readonly initials = (employee: Employee): string =>
    `${employee.first_name.charAt(0)}${employee.last_name.charAt(0)}`.toUpperCase();

  protected readonly missingContributions = (employee: Employee): string =>
    [
      employee.sss_enrolled ? null : 'SSS',
      employee.philhealth_enrolled ? null : 'PhilHealth',
      employee.pagibig_enrolled ? null : 'Pag-IBIG',
    ]
      .filter((agency) => agency !== null)
      .join(', ');

  protected readonly isEmpty = computed(() => (this.rows() ?? []).length === 0);
}

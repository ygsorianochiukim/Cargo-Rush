import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import {
  PAY_TIERS,
  PayBasis,
  PayTier,
  PermissionGroup,
  Position,
  PositionPayload,
  Role,
} from '../../models/identity/access.model';
import { AccessService } from '../../services/identity/access.service';
import { IdentityService } from '../../services/identity/identity.service';
import { CompanyCard } from './company-card';
import { PayrollCutoffCard } from './payroll-cutoff-card';
import { RatesCard } from './rates-card';
import { YardCard } from './yard-card';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { Field } from '../../shared/field';
import { Icon } from '../../shared/icon';
import { Modal } from '../../shared/modal';
import { ErrorState, SkeletonRows } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';

/**
 * Access Control — roles, the permission matrix, and job titles.
 *
 * The matrix is the screen. A role is only understandable next to what it can
 * reach, so the ticks are edited in place rather than behind a dialog, and the
 * groupings match the sidebar so "Finance" means the same thing in both.
 *
 * Two roles behave differently on purpose and the page says so rather than
 * failing on save: the administrator holds everything including permissions
 * added later, so its ticks are not editable; and a system role can have its
 * permissions tuned but cannot be deleted.
 */
@Component({
  selector: 'app-access',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    Card,
    CompanyCard,
    PayrollCutoffCard,
    RatesCard,
    YardCard,
    Field,
    Icon,
    Modal,
    ErrorState,
    SkeletonRows,
    StatusPill,
    ReactiveFormsModule,
  ],
  templateUrl: './access.page.html',
})
export class AccessPage {
  private readonly accessApi = inject(AccessService);
  private readonly confirm = inject(Confirm);
  private readonly fb = inject(FormBuilder);
  private readonly identity = inject(IdentityService);

  /**
   * Whether to show the company card at all.
   *
   * The endpoints behind it are gated on `company.manage` regardless — this
   * only decides whether somebody is shown a control that would 403. A general
   * manager holds it; an HR officer who can read the roles does not.
   */
  protected readonly canManageCompany = signal(this.identity.has('company.manage'));

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly tab = signal<'roles' | 'positions'>('roles');

  /** Null means still loading — the four list states depend on telling apart. */
  protected readonly roles = signal<Role[] | null>(null);
  protected readonly positions = signal<Position[] | null>(null);
  protected readonly groups = signal<PermissionGroup[] | null>(null);
  protected readonly loadError = signal<string | null>(null);
  protected readonly notice = signal<string | null>(null);

  protected readonly selectedRoleId = signal<string | null>(null);
  /** The ticks as edited, before saving. */
  protected readonly picked = signal<Set<string>>(new Set());
  protected readonly busy = signal(false);

  protected readonly roleOpen = signal(false);
  protected readonly positionOpen = signal(false);
  protected readonly editingPosition = signal<Position | null>(null);

  protected readonly roleForm = this.fb.group({
    name: ['', Validators.required],
    description: [''],
  });

  protected readonly positionForm = this.fb.group({
    name: ['', Validators.required],
    description: [''],
    /**
     * Does somebody in this job need a `drivers` record?
     *
     * Which is the same question as *do they use the handset*. Asked outright
     * rather than inferred from a role, because a firm that gives its mechanics
     * a driver login to shunt units around the yard is not hiring drivers.
     */
    drives: [false],
    /**
     * The rate card — one basis, and a figure for each tier.
     *
     * A default at the moment of hire, not a salary: hiring into this job opens
     * the person a contract on the tier's figure, and they carry that from then
     * on. Editing these changes what the *next* hire is offered and nothing
     * about anybody already on the job.
     *
     * Zero is a real answer and means nobody has priced that tier — the hire
     * then opens no contract rather than one at ₱0.00, which would look
     * identical to a real figure on every screen afterwards.
     */
    pay_basis: ['monthly'],
    trainee: [0],
    probationary: [0],
    regular: [0],
  });

  constructor() {
    this.refresh();
  }

  protected refresh(): void {
    this.accessApi.roles().subscribe({
      next: (res) => {
        this.roles.set(res.data);
        this.loadError.set(null);

        const current = this.selectedRoleId();
        if (current === null || !res.data.some((r) => r.id === current)) {
          const first = res.data[0] ?? null;
          first ? this.selectRole(first) : this.selectedRoleId.set(null);
        } else {
          this.selectRole(res.data.find((r) => r.id === current)!);
        }
      },
      error: () => {
        this.roles.set(null);
        this.loadError.set('Could not load access control. Check the connection and try again.');
      },
    });

    this.accessApi.permissions().subscribe({
      next: (groups) => this.groups.set(groups),
      error: () => this.groups.set(null),
    });

    this.accessApi.positions().subscribe({
      next: (res) => this.positions.set(res.data),
      error: () => this.positions.set(null),
    });
  }

  /* --------------------------------------------------------------- Roles */

  protected readonly selectedRole = computed(
    () => this.roles()?.find((r) => r.id === this.selectedRoleId()) ?? null,
  );

  protected selectRole(role: Role): void {
    this.selectedRoleId.set(role.id);
    this.notice.set(null);
    this.picked.set(new Set(role.all_permissions ? [] : role.permissions));
  }

  protected toggle(key: string): void {
    const role = this.selectedRole();
    if (role === null || role.all_permissions) return;

    const next = new Set(this.picked());
    next.has(key) ? next.delete(key) : next.add(key);
    this.picked.set(next);
  }

  protected readonly isPicked = (key: string) => this.picked().has(key);

  /** Every permission in a group ticked — drives the group's select-all box. */
  protected readonly groupAllPicked = (group: PermissionGroup): boolean =>
    group.permissions.length > 0 && group.permissions.every((p) => this.picked().has(p.key));

  protected toggleGroup(group: PermissionGroup): void {
    const role = this.selectedRole();
    if (role === null || role.all_permissions) return;

    const next = new Set(this.picked());
    const on = !this.groupAllPicked(group);

    for (const permission of group.permissions) {
      on ? next.add(permission.key) : next.delete(permission.key);
    }

    this.picked.set(next);
  }

  protected save(): void {
    const role = this.selectedRole();
    if (role === null) return;

    this.busy.set(true);
    this.notice.set(null);

    this.accessApi.updateRole(role.id, { permissions: [...this.picked()] }).subscribe({
      next: () => {
        this.busy.set(false);
        this.notice.set('Saved. Anybody holding this role sees the change on their next request.');
        this.refresh();
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.notice.set(this.messageFor(error));
      },
    });
  }

  protected openRoleForm(): void {
    this.roleForm.reset({ name: '', description: '' });
    this.roleOpen.set(true);
  }

  protected createRole(): void {
    if (this.roleForm.invalid) {
      this.roleForm.markAllAsTouched();

      return;
    }

    this.busy.set(true);

    const { name, description } = this.roleForm.getRawValue();

    this.accessApi.createRole({ name: String(name), description: description || null }).subscribe({
      next: (role) => {
        this.busy.set(false);
        this.roleOpen.set(false);
        this.selectedRoleId.set(role.id);
        this.refresh();
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.notice.set(this.messageFor(error));
      },
    });
  }

  protected async removeRole(role: Role): Promise<void> {
    const ok = await this.confirm.ask({
      title: `Delete ${role.name}?`,
      body: 'Only possible while nobody holds it.',
      confirmLabel: 'Delete role',
      danger: true,
    });

    if (!ok) return;

    this.accessApi.removeRole(role.id).subscribe({
      next: () => {
        this.selectedRoleId.set(null);
        this.refresh();
      },
      error: (error: HttpErrorResponse) => this.notice.set(this.messageFor(error)),
    });
  }

  /* ----------------------------------------------------------- Positions */

  protected openPositionForm(position: Position | null = null): void {
    this.editingPosition.set(position);

    this.positionForm.reset({
      name: position?.name ?? '',
      description: position?.description ?? '',
      drives: position?.drives ?? false,
      pay_basis: position?.pay_basis ?? 'monthly',
      trainee: (position?.trainee_amount_cents ?? 0) / 100,
      probationary: (position?.probationary_amount_cents ?? 0) / 100,
      regular: (position?.regular_amount_cents ?? 0) / 100,
    });

    this.positionOpen.set(true);
  }

  protected savePosition(): void {
    if (this.positionForm.invalid) {
      this.positionForm.markAllAsTouched();

      return;
    }

    this.busy.set(true);

    const { name, description, drives, pay_basis, trainee, probationary, regular } =
      this.positionForm.getRawValue();

    /**
     * All three figures go, whatever the basis.
     *
     * Unlike the two columns this replaced, there is nothing to clear on a
     * switch: the basis says what the amounts *mean* — a month, a day, a trip —
     * rather than which of them is read. A job moved from monthly to per-trip
     * keeps its three numbers and they are now per haul, which is wrong often
     * enough to be worth the office correcting and never stale in the way a
     * hidden second column was.
     */
    const payload: PositionPayload = {
      name: String(name),
      description: description || null,
      drives: Boolean(drives),
      pay_basis: String(pay_basis) as PayBasis,
      trainee_amount_cents: this.centavos(trainee),
      probationary_amount_cents: this.centavos(probationary),
      regular_amount_cents: this.centavos(regular),
    };

    const existing = this.editingPosition();
    const request = existing
      ? this.accessApi.updatePosition(existing.id, payload)
      : this.accessApi.createPosition(payload);

    request.subscribe({
      next: () => {
        this.busy.set(false);
        this.positionOpen.set(false);
        this.refresh();
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.notice.set(this.messageFor(error));
      },
    });
  }

  /**
   * The rate card, edited where it is read.
   *
   * A price list is a table, and a table somebody has to open a dialog to
   * change one cell of is a table they will keep a spreadsheet beside instead.
   * So the basis and the three figures save in place; the dialog is still there
   * for adding a job and for the fields that are not numbers.
   *
   * Sent as a PATCH of the one field that moved rather than the whole row, so
   * two people editing different columns of the same job cannot overwrite each
   * other's work with a stale copy of it.
   */
  protected readonly tiers = PAY_TIERS;

  /** The cell a tier is drawn from, so the table can stay a loop. */
  protected amountFor(position: Position, tier: PayTier): number {
    return (
      {
        trainee: position.trainee_amount_cents,
        probationary: position.probationary_amount_cents,
        regular: position.regular_amount_cents,
      }[tier] ?? 0
    );
  }

  protected setBasis(position: Position, basis: string): void {
    this.patchPosition(position, { pay_basis: basis as PayBasis });
  }

  protected setAmount(position: Position, tier: PayTier, pesos: string): void {
    const cents = this.centavos(pesos);

    // Nothing moved, so nothing is sent — a blur on a field somebody only
    // tabbed through should not write to the roster.
    if (cents === this.amountFor(position, tier)) return;

    this.patchPosition(position, { [`${tier}_amount_cents`]: cents });
  }

  /**
   * One field, saved, with the row replaced by what came back.
   *
   * The server's copy rather than the typed one: it has already rounded,
   * recomposed `pay_summary` off the firm's pay calendar, and answered whether
   * the job now counts as priced. Patching the local row by hand would be a
   * second implementation of all three.
   */
  private patchPosition(position: Position, payload: Partial<PositionPayload>): void {
    this.busy.set(true);

    this.accessApi.updatePosition(position.id, payload).subscribe({
      next: (res) => {
        this.busy.set(false);
        this.positions.update(
          (list) => list?.map((row) => (row.id === position.id ? res : row)) ?? list,
        );
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.notice.set(this.messageFor(error));
        // Put the row back as the server still has it, so a rejected edit does
        // not leave a figure on screen that nothing agreed to.
        this.accessApi.positions().subscribe((res) => this.positions.set(res.data));
      },
    });
  }

  /** Pesos off a form, centavos onto the wire. */
  private centavos(pesos: unknown): number {
    return Math.max(0, Math.round(Number(pesos ?? 0) * 100));
  }

  /**
   * Delete a position, or hear that it was retired.
   *
   * The list is re-read rather than the row removed locally: the two outcomes
   * look identical from here and only the server knows which happened.
   */
  protected async removePosition(position: Position): Promise<void> {
    const ok = await this.confirm.ask({
      title: `Delete ${position.name}?`,
      body: 'A position employees hold is switched off instead.',
      confirmLabel: 'Delete position',
      danger: true,
    });

    if (!ok) return;

    this.accessApi.removePosition(position.id).subscribe({
      next: () => {
        this.accessApi.positions().subscribe((res) => {
          this.positions.set(res.data);

          this.notice.set(
            res.data.some((p) => p.id === position.id)
              ? `${position.name} is held by employees, so it was switched off rather than deleted.`
              : null,
          );
        });
      },
      error: (error: HttpErrorResponse) => this.notice.set(this.messageFor(error)),
    });
  }

  protected readonly roleOptions = computed(() =>
    (this.roles() ?? []).map((r) => ({ value: r.id, label: r.name })),
  );

  private messageFor(error: HttpErrorResponse): string {
    const errors = error.error?.errors as Record<string, string[]> | undefined;

    if (error.status === 422 && errors) {
      return Object.values(errors)[0]?.[0] ?? 'Check the details and try again.';
    }

    return error.error?.message ?? 'Could not do that. Check the connection and try again.';
  }
}

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { map } from 'rxjs';

import { Applicant, ApplicantStage, Pipeline } from '../../models/hr/hr.model';
import { applicantSpec } from '../../services/hr/applicant.form';
import { ApplicantService } from '../../services/hr/applicant.service';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { ListToolbar } from '../../shared/list-toolbar';
import { Modal } from '../../shared/modal';
import { recordList } from '../../shared/record-list';
import { TONE_CLASS } from '../../shared/status';
import { ErrorState, SkeletonRows } from '../../shared/states';

/**
 * Applicants — the hiring pipeline in front of the roster.
 *
 * The stage strip at the top shows every stage including the empty ones, which
 * is deliberate: a pipeline drawn only from what exists has gaps in it, and a
 * gap reads as a broken screen rather than as "nobody is at interview" — which
 * is exactly the thing worth seeing.
 *
 * Hiring is a separate action from a stage change, and refused as one by the
 * API. It builds the employee record from the application rather than making
 * somebody retype a name, a number and an address already on the screen —
 * retyping is where the two records start to disagree.
 */
@Component({
  selector: 'app-applicants',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Field, Icon, ListToolbar, Modal, ErrorState, SkeletonRows, ReactiveFormsModule],
  templateUrl: './applicants.page.html',
})
export class ApplicantsPage {
  private readonly applicantsApi = inject(ApplicantService);
  private readonly confirm = inject(Confirm);
  private readonly fb = inject(FormBuilder);

  protected readonly fmt = fmt;
  protected readonly toneClass = TONE_CLASS;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  private readonly spec = applicantSpec();

  /** Null means the whole pipeline; a stage narrows it. */
  protected readonly filter = signal<ApplicantStage | null>(null);

  protected readonly list = recordList<Applicant>(this.spec, () =>
    this.applicantsApi.list().pipe(map((res) => res.data)),
  );

  protected readonly pipeline = signal<Pipeline | null>(null);

  protected readonly rows = computed(() => {
    const all = this.list.rows();
    const stage = this.filter();

    if (all === null) return null;

    return stage === null ? all : all.filter((a) => a.stage === stage);
  });

  /* --------------------------------------------------------------- Hire */

  protected readonly hireOpen = signal(false);
  protected readonly candidate = signal<Applicant | null>(null);
  protected readonly hireError = signal<string | null>(null);
  protected readonly hired = signal<{ name: string; employee_no: string } | null>(null);
  protected readonly busy = signal(false);

  protected readonly hireForm = this.fb.group({
    hired_on: ['', Validators.required],
    position: ['', Validators.required],
    department: [''],
    employment_type: ['probationary'],
    base_salary: [0],
  });

  constructor() {
    this.refreshPipeline();
  }

  protected refreshPipeline(): void {
    this.applicantsApi.pipeline().subscribe({
      next: (pipeline) => this.pipeline.set(pipeline),
      error: () => this.pipeline.set(null),
    });
  }

  protected toggleFilter(stage: ApplicantStage): void {
    this.filter.set(this.filter() === stage ? null : stage);
  }

  /* -------------------------------------------------------------- Stage */

  protected move(applicant: Applicant, stage: ApplicantStage): void {
    this.applicantsApi.moveTo(applicant.id, stage).subscribe({
      next: () => {
        this.list.refresh();
        this.refreshPipeline();
      },
      error: () => this.hireError.set('Could not move that application along.'),
    });
  }

  protected async reject(applicant: Applicant): Promise<void> {
    const ok = await this.confirm.ask({
      title: `Not proceeding with ${applicant.full_name}?`,
      body: 'The application is kept on record rather than deleted.',
      confirmLabel: 'Mark as not proceeding',
      danger: true,
    });

    if (ok) this.move(applicant, 'rejected');
  }

  /** The next step in the pipeline, or null once a decision has been made. */
  protected readonly nextStage = (applicant: Applicant): ApplicantStage | null => {
    const order: ApplicantStage[] = ['applied', 'screening', 'interview', 'offered'];
    const at = order.indexOf(applicant.stage);

    return at === -1 || at === order.length - 1 ? null : order[at + 1];
  };

  protected readonly nextLabel = (applicant: Applicant): string => {
    const next = this.nextStage(applicant);

    return next === null ? '' : `Move to ${next}`;
  };

  /* --------------------------------------------------------------- Hire */

  protected openHire(applicant: Applicant): void {
    this.candidate.set(applicant);
    this.hireOpen.set(true);
    this.hireError.set(null);
    this.hired.set(null);

    // Prefilled from the application, because everything here except the start
    // date and the salary is already known.
    this.hireForm.reset({
      hired_on: new Date().toISOString().slice(0, 10),
      position: applicant.position_applied,
      department: '',
      employment_type: 'probationary',
      base_salary: 0,
    });
  }

  protected hire(): void {
    const applicant = this.candidate();

    if (!applicant || this.hireForm.invalid) {
      this.hireForm.markAllAsTouched();

      return;
    }

    this.busy.set(true);
    this.hireError.set(null);

    const { hired_on, position, department, employment_type, base_salary } =
      this.hireForm.getRawValue();

    this.applicantsApi
      .hire(applicant.id, {
        hired_on: String(hired_on),
        position: String(position),
        department: department || null,
        employment_type: String(employment_type),
        base_salary_cents: Math.round(Number(base_salary ?? 0) * 100),
      })
      .subscribe({
        next: (employee) => {
          this.busy.set(false);
          this.hired.set({ name: employee.full_name, employee_no: employee.employee_no });
          this.list.refresh();
          this.refreshPipeline();
        },
        error: (error: HttpErrorResponse) => {
          this.busy.set(false);
          this.hireError.set(
            error.error?.message ??
              'Could not hire this applicant. Check the details and try again.',
          );
        },
      });
  }

  protected readonly initials = (applicant: Applicant): string =>
    `${applicant.first_name.charAt(0)}${applicant.last_name.charAt(0)}`.toUpperCase();

  protected readonly isEmpty = computed(() => (this.rows() ?? []).length === 0);
}

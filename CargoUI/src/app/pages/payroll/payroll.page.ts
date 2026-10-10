import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';

import {
  PayComponent,
  PayPeriodOption,
  PayrollCalendar,
  PayRun,
  PayRunLine,
  PayRunLinePayload,
  PayslipComponent,
} from '../../models/hr/payroll.model';
import { CompanyService } from '../../services/identity/company.service';
import { IdentityService } from '../../services/identity/identity.service';
import { PayComponentService, PayrollService } from '../../services/hr/payroll.service';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { EmptyState, ErrorState } from '../../shared/states';

type View = 'simple' | 'register';

interface Part {
  label: string;
  why: string;
  cents: number;
  field: keyof PayRunLinePayload | null;
  signed?: boolean;
}

@Component({
  selector: 'app-payroll',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Field, Icon, EmptyState, ErrorState],
  templateUrl: './payroll.page.html',
})
export class PayrollPage {
  private readonly payroll = inject(PayrollService);
  private readonly components = inject(PayComponentService);
  private readonly confirm = inject(Confirm);
  private readonly company = inject(CompanyService);
  private readonly identity = inject(IdentityService);

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly cellClass =
    'h-8 w-24 rounded-control border border-cr-line bg-cr-surface px-2 text-right text-[13px] tabular-nums focus:border-cr-blue focus:outline-none';

  protected readonly runs = signal<PayRun[] | null>(null);
  protected readonly run = signal<PayRun | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly notice = signal<string | null>(null);
  protected readonly busy = signal(false);

  protected readonly view = signal<View>('simple');

  protected readonly opened = signal<ReadonlySet<string>>(new Set<string>());

  protected readonly payslip = signal<PayRunLine | null>(null);

  protected readonly month = signal<string>('');
  protected readonly periods = signal<PayPeriodOption[]>([]);

  protected readonly calendar = signal<PayrollCalendar | null>(null);

  protected readonly cutoffHint = computed(
    () => this.calendar()?.description ?? 'Payroll is cut off on the days your firm has set.',
  );
  protected readonly periodIndex = signal<number>(-1);

  protected readonly payDate = signal<string>('');

  protected readonly period = computed(
    () => this.periods().find((option) => option.index === this.periodIndex()) ?? null,
  );

  protected readonly periodSummary = computed(() => {
    const period = this.period();
    const pay = this.payDate();

    if (period === null || pay === '') return null;

    const opening =
      `${period.label} · ${period.days} ${period.days === 1 ? 'day' : 'days'} · ` +
      `cut off ${fmt.date(period.cutoff)}`;

    return pay === period.cutoff
      ? `${opening} · paid the same day`
      : `${opening} · paid ${fmt.date(pay)}`;
  });

  protected readonly steps = computed(() => {
    const run = this.run();

    if (run === null) return [];

    const status = run.status;

    return [
      {
        n: 1,
        title: 'Check the figures',
        detail:
          'Every amount below was worked out from the employee records and the government rate tables. ' +
          'Anything that is wrong can be typed over while the run is a draft.',
        state: status === 'draft' ? 'now' : 'done',
      },
      {
        n: 2,
        title: 'Approve',
        detail:
          'Locks the run so payslips can go out. After this nothing on it can be changed — ' +
          'a correction means a new run or a journal entry.',
        state: status === 'draft' ? 'later' : 'done',
      },
      {
        n: 3,
        title: 'Pay and record',
        detail:
          'Say the money has gone out. This is what writes the run into the accounts: ' +
          'wages to expense, each fund to what it is owed, the take-home out of the bank.',
        state: status === 'paid' ? 'done' : status === 'approved' ? 'now' : 'later',
      },
    ];
  });

  protected readonly nextStep = computed(() => {
    const run = this.run();

    if (run === null) return null;

    if (run.status === 'draft') {
      return run.staff_count === 0
        ? 'Nobody is on this run. Only active employees with a monthly salary on record are paid here — check the roster, then rebuild.'
        : 'Check the payslips below. When they are right, approve the run.';
    }

    if (run.status === 'approved') {
      return `Approved and locked. Hand out the payslips, then record the run as paid once ${fmt.pesos(run.net_cents)} has left the bank.`;
    }

    return run.journal_reference === null
      ? 'Paid. This run is finished.'
      : `Paid, and in the accounts as ${run.journal_reference}. This run is finished.`;
  });

  protected readonly schedules = [
    { value: 'split', label: 'Split across every run' },
    { value: 'first', label: 'All on the first run' },
    { value: 'second', label: 'All on the second run' },
  ] as const;

  protected readonly canSetPolicy = computed(() => this.identity.has('company.manage'));

  protected readonly remittances = computed(() => {
    const run = this.run();

    if (run === null) return [];

    return [
      { label: 'SSS', goesTo: 'Social Security System', cents: run.statutory.sss },
      { label: 'PhilHealth', goesTo: 'PhilHealth', cents: run.statutory.philhealth },
      { label: 'Pag-IBIG', goesTo: 'Pag-IBIG Fund', cents: run.statutory.pagibig },
      { label: 'Withholding tax', goesTo: 'BIR', cents: run.statutory.withholding_tax },
      { label: 'Advances recovered', goesTo: 'the company', cents: run.statutory.other },
    ];
  });

  protected readonly employerShares = computed(() => {
    const run = this.run();

    if (run === null) return [];

    const share = run.employer_contributions;

    return [
      { label: 'SSS', goesTo: 'Social Security System', cents: share.sss },
      { label: 'EC', goesTo: 'SSS, for Employees’ Compensation', cents: share.ec },
      { label: 'PhilHealth', goesTo: 'PhilHealth', cents: share.philhealth },
      { label: 'Pag-IBIG', goesTo: 'Pag-IBIG Fund', cents: share.pagibig },
    ];
  });

  protected readonly missingContributions = computed(() => {
    const run = this.run();

    if (run === null || !(run.can_edit || run.can_approve)) return null;

    const people = run.lines
      .map((line) => ({
        name: line.name,
        missing: [
          line.sss_enrolled === false ? 'SSS' : null,
          line.philhealth_enrolled === false ? 'PhilHealth' : null,
          line.pagibig_enrolled === false ? 'Pag-IBIG' : null,
        ].filter((agency): agency is string => agency !== null),
      }))
      .filter((person) => person.missing.length > 0);

    if (people.length === 0) return null;

    return {
      people,
      everybody: people.length === run.lines.length && people.every((p) => p.missing.length === 3),
    };
  });

  constructor() {
    this.load();
    this.loadPeriods();

    this.components.list(true).subscribe({
      next: (res) => this.catalogue.set(res.data.filter((row) => row.kind === 'deduction')),
      error: () => undefined,
    });
  }

  protected loadPeriods(month?: string): void {
    this.payroll.periods(month).subscribe({
      next: (page) => {
        this.periods.set(page.data);
        this.month.set(String(page.meta?.['month'] ?? month ?? ''));
        this.calendar.set((page.meta?.['calendar'] as PayrollCalendar | undefined) ?? null);

        const keep = page.data.find((option) => option.index === this.periodIndex());
        const chosen = keep ?? page.data.find((option) => option.suggested) ?? page.data[0];

        if (chosen !== undefined) this.choosePeriod(chosen.index);
      },
      error: () => this.periods.set([]),
    });
  }

  protected setSchedule(schedule: string): void {
    const run = this.run();

    this.busy.set(true);

    this.company.updateProfile({ payroll_deduct_on: schedule }).subscribe({
      next: (company) => {
        this.busy.set(false);
        this.notice.set(company.payroll_deduct_on_detail);

        if (run !== null && run.can_edit)
          this.act(this.payroll.rebuild(run.id), company.payroll_deduct_on_detail);
        else if (run !== null) this.choose(run.id);
      },
      error: (failure: HttpErrorResponse) => {
        this.busy.set(false);
        this.notice.set(
          failure.status === 403
            ? 'Changing when the contributions come off is a company setting, and this account cannot change company settings.'
            : (failure.error?.message ?? 'Could not change that setting.'),
        );
      },
    });
  }

  protected choosePeriod(index: number): void {
    this.periodIndex.set(index);

    const period = this.periods().find((option) => option.index === index);

    if (period !== undefined) this.payDate.set(period.release);
  }

  protected load(): void {
    this.payroll.list({ per_page: 50 }).subscribe({
      next: (page) => {
        this.runs.set(page.data);
        this.error.set(null);

        const current = this.run();
        const first = page.data[0];

        if (current === null && first !== undefined) this.choose(first.id);
        else if (current !== null) {
          const fresh = page.data.find((candidate) => candidate.id === current.id);

          if (fresh === undefined) this.run.set(first ?? null);
        }
      },
      error: () => {
        this.runs.set(null);
        this.error.set('Could not load the pay runs. Check the connection and try again.');
      },
    });
  }

  protected choose(id: string): void {
    this.payslip.set(null);
    this.opened.set(new Set<string>());

    this.payroll.find(id).subscribe({
      next: (run) => {
        this.run.set(run);
        this.error.set(null);
      },
      error: () => this.error.set('Could not load that run.'),
    });
  }

  protected toggle(id: string): void {
    const next = new Set(this.opened());

    if (next.has(id)) next.delete(id);
    else next.add(id);

    this.opened.set(next);
  }

  protected isOpen(id: string): boolean {
    return this.opened().has(id);
  }

  protected earnings(run: PayRun, line: PayRunLine): Part[] {
    const parts: Part[] = [
      {
        label: `Basic pay for ${run.period_label}`,
        why: run.is_first_cutoff
          ? 'Half of the monthly salary. The other half is on the 16th-to-end payslip, and the two add up to the salary exactly.'
          : 'The other half of the monthly salary — including the odd centavo, so the two payslips add up to the salary exactly.',
        cents: line.basic_cents,
        field: null,
      },
      {
        label: 'Allowance',
        why: 'Meal, transport or any other allowance for this period.',
        cents: line.allowance_cents,
        field: 'allowance_cents',
      },
      {
        label: 'Overtime',
        why: 'Overtime worked in this period, entered by the office.',
        cents: line.overtime_cents,
        field: 'overtime_cents',
      },
      {
        label: 'Adjustment',
        why: 'A bonus, or a deduction for time not worked. May be a minus figure.',
        cents: line.adjustments_cents,
        field: 'adjustments_cents',
        signed: true,
      },
    ];

    return run.can_edit ? parts : parts.filter((part) => part.cents !== 0 || part.field === null);
  }

  protected deductions(run: PayRun, line: PayRunLine): Part[] {
    const share = run.carries_contributions
      ? run.deduct_on === 'split'
        ? "Half of the month's contribution, which is this firm's policy."
        : `The whole month's contribution: this firm takes it all on the ${run.is_first_cutoff ? 'first' : 'second'} cutoff.`
      : `Nothing on this payslip — this firm takes the whole month on the ${run.is_first_cutoff ? 'second' : 'first'} cutoff.`;

    const notEnrolled = (agency: string) =>
      `Not deducted — this person is not enrolled with ${agency}. Change that on the Benefits card in Access Control.`;

    const parts: Part[] = [
      {
        label: 'SSS',
        why:
          line.sss_enrolled === false
            ? notEnrolled('SSS')
            : `Social Security — retirement, sickness and maternity cover. A share of the monthly salary up to a ceiling. ${share}`,
        cents: line.sss_cents,
        field: 'sss_cents',
      },
      {
        label: 'PhilHealth',
        why:
          line.philhealth_enrolled === false
            ? notEnrolled('PhilHealth')
            : `Government health insurance. A share of the monthly salary between a floor and a ceiling. ${share}`,
        cents: line.philhealth_cents,
        field: 'philhealth_cents',
      },
      {
        label: 'Pag-IBIG',
        why:
          line.pagibig_enrolled === false
            ? notEnrolled('Pag-IBIG')
            : `The housing fund. A share of the monthly salary, capped — so most payslips show the cap rather than the percentage. ${share}`,
        cents: line.pagibig_cents,
        field: 'pagibig_cents',
      },
      {
        label: 'Withholding tax',
        why:
          line.withholding_tax_cents === 0
            ?
              'None. This salary is inside the range that pays no income tax — ₱250,000 a year is exempt.'
            : "Income tax, held back and sent to the BIR on the employee's behalf. Charged on what is " +
              'left after the funds above, using the BIR’s own table.',
        cents: line.withholding_tax_cents,
        field: 'withholding_tax_cents',
      },
      {
        label: 'Store tab',
        why: 'The mini-mart tab, taken off at this cutoff. Settled against the tab when the run is approved — change it by editing the tab or the person’s cap, not here.',
        cents: line.store_deduction_cents,
        field: null,
      },
      {
        label: 'Advance or other deduction',
        why: 'Something the company is recovering — a cash advance, say. This goes back to the company, not to an agency.',
        cents: line.other_deductions_cents,
        field: 'other_deductions_cents',
      },
    ];

    return parts.filter((part) => {
      if (part.label === 'Store tab') return part.cents !== 0;

      return part.field !== 'other_deductions_cents' || part.cents !== 0 || run.can_edit;
    });
  }

  protected build(): void {
    const period = this.period();

    if (period === null) return;

    this.busy.set(true);

    this.payroll
      .create({
        period_start: period.start,
        period_end: period.end,
        pay_date: this.payDate(),
      })
      .subscribe({
        next: (run) => {
          this.busy.set(false);
          this.run.set(run);
          this.notice.set(
            run.staff_count === 0
              ? `${run.reference} opened, with nobody on it — only active employees with a monthly salary on record are paid here.`
              : `${run.reference} built · ${run.staff_count} on the payroll · take-home ${fmt.pesos(run.net_cents)}.`,
          );
          this.load();
        },
        error: (failure: HttpErrorResponse) => {
          this.busy.set(false);
          this.notice.set(failure.error?.message ?? 'Could not open that run.');
        },
      });
  }

  protected async rebuild(run: PayRun): Promise<void> {
    const go = await this.confirm.ask({
      title: `Work ${run.reference} out again?`,
      body:
        'Every payslip is thrown away and worked out again from the employee records and the rate ' +
        'tables as they now stand. Any allowance, overtime or advance typed in by hand is lost with them.',
      confirmLabel: 'Work it out again',
      icon: 'gauge',
    });

    if (!go) return;

    this.act(this.payroll.rebuild(run.id), `${run.reference} worked out again.`);
  }

  protected async approve(run: PayRun): Promise<void> {
    const go = await this.confirm.ask({
      title: `Approve ${run.reference}?`,
      body:
        `${run.staff_count} ${run.staff_count === 1 ? 'payslip' : 'payslips'}, ` +
        `${fmt.pesos(run.net_cents)} of take-home pay. ` +
        'Approving locks the run so payslips can go out, and nothing on it can be changed afterwards.',
      confirmLabel: 'Approve run',
      icon: 'badge',
    });

    if (!go) return;

    this.act(this.payroll.approve(run.id), `${run.reference} approved and locked.`);
  }

  protected async pay(run: PayRun): Promise<void> {
    const go = await this.confirm.ask({
      title: `Record ${run.reference} as paid?`,
      body:
        'This writes the run into the accounts: ' +
        `${fmt.pesos(run.gross_cents)} as a wages cost, ` +
        `${fmt.pesos(run.deductions_cents)} as owed to the funds and the BIR, and ` +
        `${fmt.pesos(run.net_cents)} out of the bank. ` +
        'An entry in the accounts cannot be edited afterwards, only reversed.',
      confirmLabel: 'Record as paid',
      icon: 'wallet',
    });

    if (!go) return;

    this.act(this.payroll.pay(run.id), `${run.reference} paid and written into the accounts.`);
  }

  protected async remove(run: PayRun): Promise<void> {
    const go = await this.confirm.ask({
      title: `Delete ${run.reference}?`,
      body: 'A draft has never been approved and no payslip has gone out, so deleting it pays nobody differently.',
      confirmLabel: 'Delete draft',
      danger: true,
    });

    if (!go) return;

    this.busy.set(true);

    this.payroll.remove(run.id).subscribe({
      next: () => {
        this.busy.set(false);
        this.notice.set(`${run.reference} deleted.`);
        this.run.set(null);
        this.load();
      },
      error: (failure: HttpErrorResponse) => {
        this.busy.set(false);
        this.notice.set(failure.error?.message ?? 'Could not delete that run.');
      },
    });
  }

  protected edit(
    run: PayRun,
    line: PayRunLine,
    field: keyof PayRunLinePayload | null,
    value: string,
  ): void {
    if (field === null) return;

    const pesos = Number(value);

    if (!Number.isFinite(pesos)) return;

    this.busy.set(true);

    this.payroll.adjustLine(run.id, line.id, { [field]: Math.round(pesos * 100) }).subscribe({
      next: (fresh) => {
        this.busy.set(false);
        this.run.set(fresh);
        this.notice.set(null);
      },
      error: (failure: HttpErrorResponse) => {
        this.busy.set(false);
        this.notice.set(failure.error?.message ?? 'Could not save that correction.');
      },
    });
  }

  protected note(
    run: PayRun,
    line: PayRunLine,
    field: 'adjustment_note' | 'deduction_note',
    value: string,
  ): void {
    this.payroll.adjustLine(run.id, line.id, { [field]: value === '' ? null : value }).subscribe({
      next: (fresh) => this.run.set(fresh),
      error: (failure: HttpErrorResponse) =>
        this.notice.set(failure.error?.message ?? 'Could not save that note.'),
    });
  }

  protected readonly catalogue = signal<PayComponent[]>([]);

  protected readonly draft = signal<Record<string, { name: string; amount: string }>>({});

  protected draftFor(lineId: string): { name: string; amount: string } {
    return this.draft()[lineId] ?? { name: '', amount: '' };
  }

  protected setDraft(lineId: string, field: 'name' | 'amount', value: string): void {
    this.draft.update((all) => ({
      ...all,
      [lineId]: { ...this.draftFor(lineId), [field]: value },
    }));
  }

  protected addDeduction(run: PayRun, line: PayRunLine): void {
    const draft = this.draftFor(line.id);
    const name = draft.name.trim();
    const pesos = Number(draft.amount);

    if (name === '' || !Number.isFinite(pesos) || pesos <= 0) {
      this.notice.set('Give the deduction a name and an amount.');

      return;
    }

    const known = this.catalogue().find(
      (component) => component.name.toLowerCase() === name.toLowerCase(),
    );

    this.busy.set(true);

    this.payroll
      .addDeduction(run.id, line.id, {
        pay_component_id: known?.id ?? null,
        name,
        amount_cents: Math.round(pesos * 100),
      })
      .subscribe({
        next: (fresh) => {
          this.busy.set(false);
          this.run.set(fresh);
          this.notice.set(null);
          this.draft.update((all) => ({ ...all, [line.id]: { name: '', amount: '' } }));
        },
        error: (failure: HttpErrorResponse) => {
          this.busy.set(false);
          this.notice.set(failure.error?.message ?? 'Could not add that deduction.');
        },
      });
  }

  protected removeDeduction(run: PayRun, line: PayRunLine, componentId: string): void {
    this.busy.set(true);

    this.payroll.removeDeduction(run.id, line.id, componentId).subscribe({
      next: (fresh) => {
        this.busy.set(false);
        this.run.set(fresh);
        this.notice.set(null);
      },
      error: (failure: HttpErrorResponse) => {
        this.busy.set(false);
        this.notice.set(failure.error?.message ?? 'Could not remove that deduction.');
      },
    });
  }

  protected charges(line: PayRunLine): PayslipComponent[] {
    return line.components.filter((component) => component.kind === 'deduction');
  }

  protected print(): void {
    window.print();
  }

  protected pesos(cents: number): string {
    return (cents / 100).toFixed(2);
  }

  protected stateLabel(run: PayRun): string {
    if (run.status === 'paid') return 'Paid';
    if (run.status === 'approved') return 'Approved · not yet paid';

    return 'Draft';
  }

  protected stateClass(run: PayRun): string {
    if (run.status === 'paid') return 'bg-cr-success-bg text-cr-success';
    if (run.status === 'approved') return 'bg-cr-tint text-cr-blue';

    return 'bg-cr-warning-bg text-cr-warning';
  }

  private act(request: ReturnType<PayrollService['approve']>, message: string): void {
    this.busy.set(true);

    request.subscribe({
      next: (run) => {
        this.busy.set(false);
        this.run.set(run);
        this.notice.set(message);
        this.load();
      },
      error: (failure: HttpErrorResponse) => {
        this.busy.set(false);
        this.notice.set(failure.error?.message ?? 'That did not go through.');
      },
    });
  }
}


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

/** Which of the two readings of a run is on screen. */
type View = 'simple' | 'register';

/** One line of a payslip in the plain-language view. */
interface Part {
  label: string;
  /** Why this figure is what it is, in words rather than a rate. */
  why: string;
  cents: number;
  /**
   * The API field to send when somebody types over it.
   *
   * Null for a figure that is worked out rather than entered — the basic pay,
   * and the two totals. Nothing on screen offers an input for one of those,
   * because typing into a total is a way of making the parts disagree with it.
   */
  field: keyof PayRunLinePayload | null;
  /** Signed figures allow a minus: a docked half-day is real. */
  signed?: boolean;
}

/**
 * Payroll — a period's pay, checked, frozen, handed out, and put in the books.
 *
 * ## Written for two readers
 *
 * A payroll screen is read by an office manager who wants to know whether the
 * figures are right, and by an accountant who wants the register for the file.
 * Those are different documents, so the page offers both and defaults to the
 * first:
 *
 * **Simple** is one card per person, and it shows the arithmetic rather than
 * assuming it: earned, less held back, equals take-home. Opening a card
 * explains every line on it in words — what SSS is *for*, why the tax is
 * charged after the three funds — because "why is my pay less than my salary"
 * is the question payroll actually gets asked, and a spreadsheet does not
 * answer it.
 *
 * **Register** is the wide grid: everybody, every column, totals along the
 * foot. It is the sheet that goes in the payroll file and the one that prints.
 *
 * ## The three steps, shown as three steps
 *
 * Build, approve, pay. The page states where a run has got to and what happens
 * next, in plain words, because the consequences are not reversible and are not
 * guessable from a status word: approving means payslips can go out and nothing
 * can be changed again, and paying writes the run into the accounts.
 *
 * Payroll's own three words are used for its own three states. The shared
 * status vocabulary has no word for "approved but not yet paid" — borrowing
 * `assigned` from trips put the word *Assigned* on a pay run, which tells a
 * reader nothing.
 *
 * ## No rates on this page
 *
 * The explanations say what each deduction is and how it behaves — a percentage
 * of the monthly salary, up to a ceiling, split across the month's payslips —
 * and never the percentage itself. Those rates live in `config/cargo.php`
 * because **they change, and they change by circular**. Printing "4.5%" here
 * would make this file the second place that knows the SSS rate, and the one
 * nobody remembers to update.
 *
 * ## What the API decides, not this page
 *
 * Each run arrives with `can_edit`, `can_approve` and `can_pay` on it. The
 * rules are the API's, and two clients deriving them from `status` is two
 * places to get them wrong.
 *
 * ## Why the tax does not follow an edit
 *
 * Typing an allowance changes the gross and the net and deliberately leaves the
 * withholding tax alone. An allowance is usually a one-off that the BIR's table
 * would tax as if it were the person's regular pay, and an office correcting
 * one payslip has not asked for the tax to move under them. A run that needs
 * the tax redone is rebuilt — which is the button beside it, and the page says
 * so where somebody is typing.
 *
 * ## Who is on a run
 *
 * Active employees with a monthly basic on record. A fleet's drivers are often
 * paid **per trip**, and that money is already in the daily truck sheet's
 * driver and helper columns — so somebody with no basic is left off rather than
 * paid twice. Anything they are owed goes on as an adjustment.
 */
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

  /** Small, right-aligned and numeric: a figure somebody can type over. */
  protected readonly cellClass =
    'h-8 w-24 rounded-control border border-cr-line bg-cr-surface px-2 text-right text-[13px] tabular-nums focus:border-cr-blue focus:outline-none';

  protected readonly runs = signal<PayRun[] | null>(null);
  protected readonly run = signal<PayRun | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly notice = signal<string | null>(null);
  protected readonly busy = signal(false);

  /** Plain-language cards, or the accountant's grid. See the class note. */
  protected readonly view = signal<View>('simple');

  /** Which payslips have been opened up to show their arithmetic. */
  protected readonly opened = signal<ReadonlySet<string>>(new Set<string>());

  /**
   * Whose payslip is on screen as a document, if anybody's.
   *
   * The register answers "what does this run cost"; a payslip answers "what am
   * I being handed, and why is it less than my salary". Different documents for
   * different readers, off the same run.
   */
  protected readonly payslip = signal<PayRunLine | null>(null);

  /**
   * Which month's periods are on offer, and which of them is chosen.
   *
   * A month and a choice rather than three free date fields, because a pay
   * period is not a range: payroll is cut off on the firm's own days, so it
   * is the 1st to the 15th or the 16th to the end of the month. The two on
   * offer come from the API — see `PayrollService.periods()` — so this page
   * cannot produce a period the API would refuse, and cannot get February
   * wrong.
   */
  protected readonly month = signal<string>('');
  protected readonly periods = signal<PayPeriodOption[]>([]);

  /**
   * The firm's own calendar, as the API described it.
   *
   * Sent in `meta.calendar` beside the periods so this screen can say *why*
   * these are the choices without doing any calendar arithmetic of its own —
   * which is the bug the whole cutoff feature exists to remove, and would be
   * a poor one to reintroduce on the screen that reads it.
   */
  protected readonly calendar = signal<PayrollCalendar | null>(null);

  /**
   * The firm's calendar in words, shown on hovering the period chips.
   *
   * Hardcoded to "Payroll is cut off on the 1st and the 16th" until now, which
   * was true of the fixed calendar this module started on and of nothing
   * since. A firm on the 5th, the 15th and the 25th saw its three correct
   * periods above a sentence naming two days it had never chosen.
   */
  protected readonly cutoffHint = computed(
    () => this.calendar()?.description ?? 'Payroll is cut off on the days your firm has set.',
  );
  /**
   * Which period of the month is chosen, by position.
   *
   * Was the `half` string — "first", "second", "month" — which can name at
   * most two periods. A firm cutting off three times a month has two of them
   * reporting "second", so looking a period up by it returned whichever came
   * first: clicking 16–25 selected 6–15, and the summary underneath described
   * the wrong fortnight.
   *
   * `-1` is "nothing chosen yet", which is a real state while the periods are
   * still loading and is not the same as the first one.
   */
  protected readonly periodIndex = signal<number>(-1);

  /**
   * When the money leaves the bank.
   *
   * Follows the **release** of whichever period is chosen — the cutoff plus the
   * firm's own lag, so a period closing on the 15th offers the 17th. Stays
   * editable: paying on the 20th instead is ordinary, and the books care when
   * the money moved rather than when the period ended.
   */
  protected readonly payDate = signal<string>('');

  /** The chosen period, or null while the choice is still loading. */
  protected readonly period = computed(
    () => this.periods().find((option) => option.index === this.periodIndex()) ?? null,
  );

  /**
   * The choice read back, in one short line.
   *
   * The difference between the period, the cutoff and the pay date is exactly
   * what somebody gets wrong the first time, so all three are still said —
   * but as four labelled scraps rather than the two sentences this used to be.
   * The toolbar above it is already three controls and two buttons; a
   * paragraph under them was read as a warning rather than a read-back.
   */
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

  /**
   * Where this run has got to, and what each step means.
   *
   * Three steps rather than a status word, because the reader's question is
   * "what happens if I press the button" and a word like *approved* does not
   * answer it.
   */
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

  /** One sentence on what to do next, for somebody who has just landed here. */
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

  /**
   * The three answers to "which cutoff do the contributions come off".
   *
   * A company setting rather than a payroll one, so it is only editable by
   * somebody holding `company.manage` — an office manager who can run payroll
   * but not change company policy sees the sentence and not the control.
   */
  /**
   * Named for the runs rather than for halves of a month.
   *
   * These read "Split across both", "All on the 1st–15th" and "All on the
   * 16th–end" — all three true of a fortnightly payroll on the old fixed
   * calendar, and none of them true of a firm closing on the 5th, the 15th and
   * the 25th. It was offering somebody a choice between two halves of a month
   * they do not have.
   */
  protected readonly schedules = [
    { value: 'split', label: 'Split across every run' },
    { value: 'first', label: 'All on the first run' },
    { value: 'second', label: 'All on the second run' },
  ] as const;

  protected readonly canSetPolicy = computed(() => this.identity.has('company.manage'));

  /** What is held back across the whole run, and who each part goes to. */
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

  constructor() {
    this.load();
    // No month asked for: the API answers with the one holding the period that
    // has just closed, which is the run somebody has come to pay.
    this.loadPeriods();

    /**
     * The deduction catalogue, for the picker on a payslip.
     *
     * Active only, and failures are swallowed: it is a convenience — the same
     * charge spelled the same way every time — and a firm that has never set
     * one up simply types the name. A page that refused to open because a
     * secondary list would not load would be worse than one without the list.
     */
    this.components.list(true).subscribe({
      next: (res) => this.catalogue.set(res.data.filter((row) => row.kind === 'deduction')),
      error: () => undefined,
    });
  }

  /**
   * Fetch the periods a month is allowed to have, and choose one.
   *
   * The suggested period wins on the first load; afterwards the same half is
   * kept across a change of month, because somebody stepping back through
   * September and August is comparing the same fortnight.
   */
  protected loadPeriods(month?: string): void {
    this.payroll.periods(month).subscribe({
      next: (page) => {
        this.periods.set(page.data);
        this.month.set(String(page.meta?.['month'] ?? month ?? ''));
        this.calendar.set((page.meta?.['calendar'] as PayrollCalendar | undefined) ?? null);

        // Keep the period somebody was already looking at across a month
        // change, by position rather than by name.
        const keep = page.data.find((option) => option.index === this.periodIndex());
        const chosen = keep ?? page.data.find((option) => option.suggested) ?? page.data[0];

        if (chosen !== undefined) this.choosePeriod(chosen.index);
      },
      // Not fatal, and not worth an error state over: the run on screen is
      // still readable, only opening a new one is unavailable.
      error: () => this.periods.set([]),
    });
  }

  /**
   * Change which cutoff the contributions come off.
   *
   * A company setting, so it changes every run from here on. The draft on
   * screen is worked out again straight afterwards, because a policy change
   * whose effect only shows up on the *next* run is a change somebody makes
   * twice before believing it. An approved or paid run is left alone — its
   * figures are frozen, and the message says so.
   */
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

  /**
   * Pick a period, and move the pay date to the day that period is released.
   *
   * It used to default to the cutoff, which on the old fixed calendar was the
   * day after the period ended and near enough. It is not near enough now: a
   * firm that closes on the 5th, the 15th and the 25th releases on the 7th, the
   * 17th and the 27th, and defaulting to the cutoff had the form offering to
   * pay two days before the budget exists.
   */
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

        // Land on the newest run rather than an empty right-hand column: the
        // run somebody has come to look at is almost always the last one.
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

  /** Open one payslip up to show how it was worked out. */
  protected toggle(id: string): void {
    const next = new Set(this.opened());

    if (next.has(id)) next.delete(id);
    else next.add(id);

    this.opened.set(next);
  }

  protected isOpen(id: string): boolean {
    return this.opened().has(id);
  }

  /**
   * What somebody earned this period, line by line.
   *
   * The basic has no input against it because it is the monthly salary divided
   * across the month's runs — changing it here would be changing the salary in
   * one fortnight only, which is what the adjustment line is for.
   */
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

    // A draft is being worked on, so every line it can take shows even at zero.
    // A frozen run shows only what is actually on the payslip — a column of
    // zeroes on a document somebody has been handed is noise.
    return run.can_edit ? parts : parts.filter((part) => part.cents !== 0 || part.field === null);
  }

  /**
   * What was held back, and what each part is for.
   *
   * No percentages here on purpose — see the note at the top of this class.
   */
  protected deductions(run: PayRun, line: PayRunLine): Part[] {
    /**
     * Why a fund line is the size it is — including zero.
     *
     * A payslip with no SSS on it is either correct, because the firm takes the
     * whole month on the other cutoff, or a mistake. The reader cannot tell
     * which without being told the policy, so the policy is what the note says
     * when this cutoff carries nothing.
     */
    const share = run.carries_contributions
      ? run.deduct_on === 'split'
        ? "Half of the month's contribution, which is this firm's policy."
        : `The whole month's contribution: this firm takes it all on the ${run.is_first_cutoff ? 'first' : 'second'} cutoff.`
      : `Nothing on this payslip — this firm takes the whole month on the ${run.is_first_cutoff ? 'second' : 'first'} cutoff.`;

    /**
     * A contribution somebody is not enrolled for.
     *
     * Its own sentence, because "SSS ₱0.00" otherwise reads as the cutoff
     * policy above and is not — this is a standing fact about the person, and
     * the fix for it is on their record rather than on the run. Frozen on the
     * payslip, so an old one keeps saying what was true then.
     */
    const notEnrolled = (agency: string) =>
      `Not deducted — this person is not enrolled with ${agency}. Change that on their employee record.`;

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
            ? // The salary range that triggers income tax at all — decided on
              // the monthly salary, not on one fortnight, so an allowance
              // cannot make somebody a taxpayer for a fortnight and not the
              // next.
              'None. This salary is inside the range that pays no income tax — ₱250,000 a year is exempt.'
            : "Income tax, held back and sent to the BIR on the employee's behalf. Charged on what is " +
              'left after the funds above, using the BIR’s own table.',
        cents: line.withholding_tax_cents,
        field: 'withholding_tax_cents',
      },
      {
        /**
         * The store tab, and it is **not editable** — unlike everything else
         * on this panel.
         *
         * The figure is the outstanding balance as at the period end, capped
         * by the person's own cap, and it is settled against the tab when the
         * run is approved. Typing over it here would take an amount the tab
         * never learns about, and the two would disagree from then on. The way
         * to change it is a line on the tab or a cap on the record.
         */
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

    /**
     * The four statutory lines stay even at zero, because each of their zeroes
     * now explains itself — "the whole month comes off the other cutoff", "this
     * salary pays no income tax". Those are the two questions this panel exists
     * to answer, and hiding the line hides the answer. Only an advance nobody
     * took is dropped.
     */
    return parts.filter((part) => {
      // The store tab is dropped when there is none, on a draft as well as on
      // a frozen run: unlike the four statutory lines, its zero explains
      // nothing — it means the person has no tab, which is most people.
      if (part.label === 'Store tab') return part.cents !== 0;

      return part.field !== 'other_deductions_cents' || part.cents !== 0 || run.can_edit;
    });
  }

  /** Open a run for the chosen period and work everybody's pay out. */
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

  /** Work it out again from the employee records as they now stand. */
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

  /** Freeze it. Payslips can go out from here. */
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

  /** The money has gone out — and this is what posts it to the books. */
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

  /** Delete a draft. An approved run has been shown to people. */
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

  /**
   * Correct one figure on one payslip.
   *
   * Sent as it is typed, one field at a time, and the whole run comes back —
   * so every total on the page follows the cell without this page adding
   * anything up itself. The field takes pesos because that is what a person
   * types; centavos are what crosses the wire.
   */
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

  /**
   * The firm's deduction catalogue, for the picker on a payslip.
   *
   * Loaded once with the page rather than per payslip: it is a short list that
   * changes rarely, and fetching it every time somebody opens a row would be a
   * request per click for the same twenty words.
   *
   * Empty is the normal state for a firm that has never set one up, and the
   * picker falls back to a typed name — which is the case this feature is
   * mostly for.
   */
  protected readonly catalogue = signal<PayComponent[]>([]);

  /** The half-typed deduction, per payslip. Cleared once it is saved. */
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

  /**
   * Put the typed deduction on the payslip.
   *
   * The name is matched against the catalogue first, so picking "Uniform" from
   * the list and typing it both end up pointing at the same component — which
   * is what makes the same charge spell itself the same way on every payslip.
   * A name nothing matches goes on as a one-off, which is allowed and is the
   * point.
   */
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

  /** The itemised deductions on a payslip — assigned and hand-added alike. */
  protected charges(line: PayRunLine): PayslipComponent[] {
    return line.components.filter((component) => component.kind === 'deduction');
  }

  protected print(): void {
    window.print();
  }

  /** Pesos, for a figure somebody types. The wire is centavos. */
  protected pesos(cents: number): string {
    return (cents / 100).toFixed(2);
  }

  /**
   * Payroll's own three words for its own three states.
   *
   * Not the shared status vocabulary: it has no word for the frozen middle, and
   * borrowing `assigned` from trips put *Assigned* on a pay run — which tells
   * a reader nothing about payroll.
   */
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

  /** One request, one notice, one refresh. The shape of all four verbs. */
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

/*
 * No date arithmetic below this line, and that is the change.
 *
 * This page used to work out the fortnight just ending, and the last day of a
 * month, for itself. Both are now answered by `GET payroll/periods` — because
 * the pay schedule is a rule about the business rather than a calendar trick,
 * and a client that computes it is a second place to get February wrong.
 */

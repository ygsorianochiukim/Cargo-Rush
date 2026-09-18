import { Timestamped } from '../shared/envelope.model';

/**
 * Payroll: a period's pay, frozen, handed out, and put in the books.
 *
 * Money is integer centavos, like everywhere else. Two things about the shape
 * are worth knowing before reading it.
 *
 * **A run always arrives with its lines.** A run without them is a period and a
 * total, which is not something anybody can check — and the screen that shows a
 * run is the screen somebody checks it on before approving it.
 *
 * **The statutory figures are kept apart.** SSS, PhilHealth, Pag-IBIG and the
 * withholding tax are four separate columns rather than one deduction, because
 * each is remitted to its own agency on its own form. A single total would turn
 * the monthly remittance into a spreadsheet exercise.
 */

/** draft → approved → paid. Nothing goes backwards. */
export type PayRunStatus = 'draft' | 'approved' | 'paid';

/** What each agency is owed out of a run, plus what the firm recovered. */
export interface PayRunStatutory {
  sss: number;
  philhealth: number;
  pagibig: number;
  withholding_tax: number;
  /** Advances and anything else the firm held back. Not an agency's. */
  other: number;
}

/**
 * One person's payslip.
 *
 * The name and the position are copied onto the line rather than read through
 * the employee: a payslip is a statement about a fortnight and has to keep
 * saying what it said after somebody is promoted or leaves.
 */
export interface PayRunLine {
  id: string;
  employee_id: string;
  employee_no: string | null;
  name: string;
  position: string | null;

  /**
   * How this payslip was worked out, frozen on the line.
   *
   * A salary is a monthly figure and is split across the cutoffs. Trip and
   * daily pay is already this period's — it is summed from the days actually
   * worked between the two dates, so there is nothing to split.
   *
   * Frozen because a person moved from a day rate to a salary must not have
   * last March's payslip start describing itself as a monthly one.
   */
  pay_basis: 'monthly' | 'daily' | 'per_trip';
  pay_basis_label: string;
  /**
   * The workings behind `basic_cents`, so it can be checked.
   *
   * `days_worked` is what a daily rate was multiplied by, `trips` is what a
   * trip rate was, and `sheet_days` is how many days of the truck sheet the
   * person appears on. All zero on a monthly payslip, where none of the
   * questions arise — and a per-trip figure with nothing beside it is a number
   * the person holding it cannot verify.
   */
  days_worked: number;
  sheet_days: number;
  trips: number;

  /** The period's pay: a share of a monthly salary, or the period's own work. */
  basic_cents: number;
  allowance_cents: number;
  overtime_cents: number;
  /** Signed: a bonus and a docked half-day are both real. */
  adjustments_cents: number;
  adjustment_note: string | null;

  /**
   * What the firm's own salary structure added and took off this payslip.
   *
   * Kept apart from `allowance_cents` and `other_deductions_cents` above, which
   * stay the hand-typed escape hatch for a one-off. Sharing a column would mean
   * a rebuild — which recomputes the structure — silently wiping or doubling a
   * figure somebody typed.
   */
  component_earnings_cents: number;
  component_deductions_cents: number;
  /** The itemised lines behind those two totals. Frozen copies. */
  components: PayslipComponent[];

  sss_cents: number;
  philhealth_cents: number;
  pagibig_cents: number;

  /**
   * Which contributions this payslip was subject to, frozen on the line.
   *
   * Sent with the figures because "SSS ₱0.00" has two quite different
   * explanations — the person is not enrolled, or there was nothing to
   * contribute on — and only one of them is something for the office to fix.
   */
  sss_enrolled: boolean;
  philhealth_enrolled: boolean;
  pagibig_enrolled: boolean;

  withholding_tax_cents: number;
  other_deductions_cents: number;
  /** What came off the store tab — the mini-mart *pautang*. */
  store_deduction_cents: number;
  deduction_note: string | null;

  /**
   * Why this payslip is zero, when it is. Null when it is not.
   *
   * A ₱0.00 line is almost always one of three things — the truck sheet names
   * nobody, it names them with no salary against the day, or a daily rate was
   * never set — and they need different fixing. Composed by the API from the
   * frozen columns, so an old payslip goes on explaining itself the way it did.
   */
  zero_explanation: string | null;

  /** Stored, because these three are what was printed on the paper. */
  gross_cents: number;
  deductions_cents: number;
  net_cents: number;
}

/** One row of somebody's store tab — the mini-mart *pautang*. */
export interface StoreCredit {
  id: string;
  employee_id: string;
  /** `charge` is goods taken against pay; `payment` is money back. */
  kind: 'charge' | 'payment';
  kind_label: string;
  /** Which way it moves the balance: +1 or -1. */
  sign: number;
  /** Always positive, whichever kind it is. */
  amount_cents: number;
  signed_cents: number;
  description: string | null;
  outlet: string | null;
  charged_on: string;
  pay_run_line_id: string | null;
  /**
   * Written by payroll rather than the storekeeper.
   *
   * These cannot be deleted — the figure is on a payslip somebody has been
   * handed, and removing it would leave the tab and the payslip disagreeing.
   * The way to undo one is a correcting charge.
   */
  from_payroll: boolean;
  recorded_by: string | null;
  notes: string | null;
}

/** The tab as the API returns it: the rows, and the figures beside them. */
export interface StoreCreditState {
  rows: StoreCredit[];
  balance_cents: number;
  /** What the next payslip would take, at today's balance. */
  next_deduction_cents: number;
  /** The most one payslip may take. Zero is the whole balance. */
  cap_cents: number;
}

export interface StoreCreditPayload {
  kind?: 'charge' | 'payment';
  amount_cents: number;
  description?: string | null;
  outlet?: string | null;
  charged_on?: string;
  notes?: string | null;
}

/**
 * One itemised allowance or deduction on a payslip.
 *
 * A **frozen copy** — the name and the amount as they were on the day, not a
 * join onto the catalogue. Renaming a component, repricing it or removing it
 * outright leaves every payslip already issued saying exactly what it said.
 * `pay_component_id` is for opening the catalogue row, never for reading it,
 * and is null once that row is gone.
 */
export interface PayslipComponent {
  id: string;
  pay_component_id: string | null;
  name: string;
  kind: PayComponentKind;
  /** `+` or `−`, so a template does not decide what a deduction looks like. */
  sign: string;
  taxable: boolean;
  amount_cents: number;
  /**
   * Did somebody type this onto the payslip on the run itself?
   *
   * Which is the same question as *may it be taken back off here*. A row from
   * the salary structure is the catalogue's answer to this period, and removing
   * it on one payslip would be a correction the next rebuild silently undoes —
   * so the screen offers a remove only on the ones it can honour.
   */
  added_by_hand: boolean;
}

export interface PayRun extends Timestamped {
  id: string;
  /** `PR-2026-0004`, from the API's own series. Never chosen by a client. */
  reference: string;

  period_start: string;
  period_end: string;
  /** `1–15 Sep 2026` — how a period reads on a list. */
  period_label: string;
  /** When the money goes out, which is the date the journal entry carries. */
  pay_date: string;

  status: PayRunStatus;
  approved_at: string | null;
  approved_by_name: string | null;
  paid_at: string | null;

  /**
   * The entry this run posted, once it was paid.
   *
   * Null before that, and it is the link that says payroll reached the books
   * rather than stopping at a spreadsheet.
   */
  journal_entry_id: string | null;
  journal_reference: string | null;

  /** True for the 1st-to-15th payslip. */
  is_first_cutoff: boolean;
  /**
   * Which cutoff the firm takes the monthly contributions on, and what that
   * means in words.
   *
   * This is what makes a ₱0.00 SSS line legible: a payslip with no
   * contributions on it is either correct — because the firm takes them all on
   * the other cutoff — or a mistake, and a reader cannot tell which without
   * being told the policy. It arrives with the run so the figures and the
   * explanation of them cannot get out of step.
   */
  deduct_on: 'split' | 'first' | 'second';
  deduct_on_label: string;
  deduct_on_detail: string;
  /** Does this cutoff carry the funds at all? */
  carries_contributions: boolean;

  staff_count: number;
  gross_cents: number;
  deductions_cents: number;
  net_cents: number;
  statutory: PayRunStatutory;
  currency: string;

  /**
   * What this page may offer.
   *
   * From the API, because the rules are the API's: a draft can be rebuilt,
   * edited, approved and deleted; an approved run can only be paid; a paid one
   * is finished. Two clients working that out from `status` is two places to
   * get it wrong.
   */
  can_edit: boolean;
  can_approve: boolean;
  can_pay: boolean;

  notes: string | null;

  lines: PayRunLine[];
}

/**
 * One of the pay periods a month is allowed to have.
 *
 * A period is one of the firm's own one or two per month — never a range
 * somebody types, because the statutory figures on every payslip are half a
 * month's contributions and a semi-monthly tax table.
 *
 * **Which days those are is the company's setting**, not the platform's: a firm
 * cutting off on the 10th and the 25th gets different periods from one on the
 * 15th and the end of the month, and a period may start in the month before the
 * one it belongs to. The API works all of it out and this app renders it — the
 * same reasoning as the account types and the journal categories, and it means
 * a client cannot get February wrong, cannot keep offering two halves to an
 * office that has switched to paying monthly, and cannot disagree with the
 * server about whose fortnight this is.
 */
export interface PayPeriodOption {
  /**
   * `first`, `second`, or `month` where payroll runs once.
   *
   * A *position* in the month rather than a pair of dates, which is what keeps
   * it meaningful once a firm moves its cutoffs.
   */
  half: 'first' | 'second' | 'month';
  /** The same thing as a number, from zero, in the order periods close. */
  index: number;
  start: string;
  end: string;
  /** `1–15 Sep 2026`, or `26 Aug–10 Sep 2026` where the period crosses. */
  label: string;
  /** `1–15` — for a choice where the month is already on screen. */
  short: string;
  /** The day the period closes and payroll is run: the day after it ends. */
  cutoff: string;
  /** Days in the period, counting both ends. Thirteen in a short February. */
  days: number;
  /**
   * The period that has just closed — the one an office has come to pay.
   *
   * Sent rather than worked out here, because "which period is due" is a
   * question about the calendar and the pay schedule, and both live on the
   * server.
   */
  suggested: boolean;
}

/** Opening a run: the period it covers, and the day the money goes out. */
export interface PayRunPayload {
  period_start: string;
  period_end: string;
  pay_date: string;
}

/**
 * A correction to one payslip.
 *
 * Every field optional, because this is a correction and not a rewrite. Note
 * what is *not* recomputed when a gross moves: the withholding tax. An
 * allowance is usually a one-off that the BIR table would tax as if it were the
 * person's regular pay, and an office correcting a payslip has not asked for
 * the tax to move under them. A run that needs the tax redone is rebuilt.
 */
export interface PayRunLinePayload {
  allowance_cents?: number;
  overtime_cents?: number;
  adjustments_cents?: number;
  adjustment_note?: string | null;
  sss_cents?: number;
  philhealth_cents?: number;
  pagibig_cents?: number;
  withholding_tax_cents?: number;
  other_deductions_cents?: number;
  deduction_note?: string | null;
}

/**
 * When a firm's pay periods close — its own setting, not the platform's.
 *
 * One or two day-of-month numbers, each the **last day worked** in a period.
 * `[15, 31]` is the Philippine norm and gives the 1st–15th and the 16th–end;
 * `[10, 25]` gives the 26th–10th and the 11th–25th; `[31]` alone is a monthly
 * payroll. A day past the end of a short month clamps, so 31 is how a firm says
 * "the end of the month" and February takes care of itself.
 *
 * The description, the day labels and the worked example periods all come from
 * the API rather than being assembled here. That is not laziness: "the 10th and
 * the 25th" is not something an office can picture, "26 Aug–10 Sep" is, and a
 * client that built the second from the first would be a second implementation
 * of a calendar — which is the bug this whole setting exists to remove.
 */
export interface PayrollCalendar {
  cutoff_days: number[];
  runs_per_month: number;
  /** `Payroll runs twice a month, cut off on the 15th and the last day…` */
  description: string;
  /** `the 15th`, `the last day of the month` — one per cutoff day. */
  day_labels: string[];
  /** What those days come to, for a month, so the office can check them. */
  example_periods: Omit<PayPeriodOption, 'suggested'>[];
}

/** Which side of a payslip a component lands on. */
export type PayComponentKind = 'earning' | 'deduction';

/** A peso figure, or a share of the monthly basic. */
export type PayComponentBasis = 'fixed' | 'percent_of_basic';

/**
 * Which payslip of the month carries a component.
 *
 * `each_run` is the case the statutory contributions have no equivalent of, and
 * it is the one an office gets wrong: "₱2,000 rice allowance" almost always
 * means a month of it, while "₱500 a payslip" means ₱500 a payslip — which on a
 * semi-monthly payroll is ₱1,000 a month. Neither can be guessed from the
 * number, so the form asks.
 */
export type PayComponentSchedule = 'each_run' | 'monthly_split' | 'first_cutoff' | 'second_cutoff';

/**
 * One row of the firm's salary structure.
 *
 * What this company pays and deducts, as a list it keeps — a rice allowance, a
 * COLA, a uniform deduction. It is what payroll should do *next* time, and not
 * what any payslip says: that is `PayslipComponent`, frozen onto the run.
 */
export interface PayComponent extends Timestamped {
  id: string;
  name: string;

  kind: PayComponentKind;
  kind_label: string;
  /** `+` or `−`. From the API so no template decides how a deduction reads. */
  sign: string;

  basis: PayComponentBasis;
  basis_label: string;
  /** Centavos, for a fixed component. Ignored by a percentage one. */
  amount_cents: number;
  /** Basis points (1000 = 10% of the monthly basic). */
  rate_bp: number;

  schedule: PayComponentSchedule;
  schedule_label: string;
  schedule_detail: string;
  /** Is the amount a month's worth, or one payslip's? */
  is_monthly: boolean;

  /**
   * Does this go into the tax base?
   *
   * Always false for a deduction, whatever was saved — the flag is meaningless
   * there, and the API reports it rather than making this app remember.
   */
  taxable: boolean;

  status: string;
  position: number;
  notes: string | null;
  currency: string;

  /**
   * How many people are on it.
   *
   * What tells the office that removing this will *retire* it rather than
   * delete it: a component somebody is assigned cannot be deleted without
   * taking their assignments with it.
   */
  assignment_count?: number;
}

/** One person's assignment of one component, over a stretch of time. */
export interface EmployeePayComponent extends Timestamped {
  id: string;
  employee_id: string;
  pay_component_id: string;
  /** The catalogue row, so a list can show a name rather than an id. */
  component: PayComponent | null;

  /**
   * This person's own figures, or null where they follow the catalogue.
   *
   * Null is not zero. It means "whatever the component says", which is what
   * keeps a firm-wide raise to one edited row — so a form must not turn it into
   * 0 on the way in, or the next save would pin this person to nothing.
   */
  amount_cents: number | null;
  rate_bp: number | null;

  /**
   * What is in force, override or not.
   *
   * `effective_amount_cents` is null for a percentage component, deliberately:
   * there is no peso answer without a salary, and ₱0.00 would read as a
   * component that pays nothing.
   */
  effective_amount_cents: number | null;
  effective_rate_bp: number | null;
  overrides_amount: boolean;

  effective_from: string;
  /** Null is open-ended, which is most assignments. */
  effective_to: string | null;

  status: string;
  notes: string | null;
  currency: string;
}

/** Creating or editing a component. */
export interface PayComponentPayload {
  name?: string;
  kind?: PayComponentKind;
  basis?: PayComponentBasis;
  amount_cents?: number;
  rate_bp?: number;
  schedule?: PayComponentSchedule;
  taxable?: boolean;
  status?: string;
  position?: number;
  notes?: string | null;
}

/**
 * Putting somebody on a component.
 *
 * The employee and the component are required on create and refused on update:
 * moving an assignment to a different person is not an edit, it is a mistake
 * corrected by removing one row and adding another.
 */
export interface EmployeePayComponentPayload {
  employee_id?: string;
  pay_component_id?: string;
  amount_cents?: number | null;
  rate_bp?: number | null;
  effective_from?: string;
  effective_to?: string | null;
  status?: string;
  notes?: string | null;
}

/** pending → approved | declined | withdrawn. Nothing reopens. */
export type CutoffRequestStatus = 'pending' | 'approved' | 'declined' | 'withdrawn';

/**
 * A request to move the firm's pay cutoff.
 *
 * The cutoff is written through the company settings, which needs
 * `company.manage` — a permission only the administrator holds by default. But
 * the person who *notices* the cutoff is wrong is whoever runs payroll, and
 * before this their only route was to find the administrator and describe it
 * out loud. This is that conversation, written down: the change, the reason,
 * and a decision that **applies it** rather than instructing somebody to go and
 * retype it.
 *
 * The days are the least useful field here and are not what an administrator
 * reads. `summary` and `calendar` are — "10, 25" is not something anybody can
 * picture well enough to approve.
 */
export interface PayrollCutoffRequest extends Timestamped {
  id: string;

  cutoff_days: number[];
  /** What is being asked for, worked out — periods and all. */
  calendar: PayrollCalendar;
  /** `Payroll runs twice a month, cut off on the 10th and the 25th.` */
  summary: string;

  /** Null where the request had no opinion, which is most of them. */
  payroll_deduct_on: 'split' | 'first' | 'second' | null;
  payroll_deduct_on_label: string | null;

  reason: string;

  status: CutoffRequestStatus;
  requested_by_name: string | null;
  decided_by_name: string | null;
  decided_at: string | null;
  decision_note: string | null;

  /** What the firm was on when this was approved. Null until then. */
  previous_cutoff_days: number[] | null;

  /**
   * The three fields only a *pending* request carries.
   *
   * All about the moment of deciding rather than about the request, which is
   * why the API does not store them: they would be stale by the time anybody
   * read them back.
   */
  current_calendar?: PayrollCalendar;
  /** Somebody already made this change by hand — do not approve it twice. */
  already_in_force?: boolean;
  /**
   * Why this cannot be approved yet, if it cannot — almost always an open
   * draft pay run, named. Sent so the button can explain itself before
   * anybody presses it rather than answering with a 422.
   */
  blocked_reason?: string | null;
}

/** Asking for the cutoff to move. The reason is not optional. */
export interface PayrollCutoffRequestPayload {
  cutoff_days: number[];
  payroll_deduct_on?: 'split' | 'first' | 'second' | null;
  reason: string;
}

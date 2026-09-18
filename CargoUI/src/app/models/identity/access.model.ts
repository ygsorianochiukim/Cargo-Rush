import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/**
 * Access control — roles, what each reaches, and the job titles behind them.
 *
 * The split is the design: a **position** is what somebody is (Driver,
 * Treasury Officer), a **role** is what they can open. Keeping them apart is
 * what lets a driver who also keeps the books have the accountant's access
 * without inventing a job title for it.
 */
export interface Permission {
  id: string;
  /** Matched literally by the API's route middleware. */
  key: string;
  name: string;
  description: string | null;
}

/** The vocabulary, grouped by module, for the permission matrix. */
export interface PermissionGroup {
  group: string;
  permissions: Permission[];
}

export interface Role extends Timestamped {
  id: string;
  /** What `users.role` holds. */
  key: string;
  name: string;
  description: string | null;
  /** Part of the app itself: editable, not deletable. */
  is_system: boolean;
  /**
   * Holds everything, including permissions added in later releases, so its
   * list is not a set of ticks the client can edit.
   */
  all_permissions: boolean;
  position: number;
  status: StatusValue;
  /** `['*']` when `all_permissions`. */
  permissions: string[];
  permission_count: number | null;
  /** What makes "can I delete this?" answerable without asking the server. */
  user_count?: number;
}

export interface RolePayload {
  name: string;
  description?: string | null;
  status?: StatusValue;
  /** Permission keys. Omitted means "not part of this edit". */
  permissions?: string[];
}

export interface Position extends Timestamped {
  id: string;
  key: string;
  name: string;
  description: string | null;

  /**
   * Whether registering somebody into this job also asks for a licence, and
   * opens them a `drivers` record.
   *
   * Its own field on the position rather than something inferred from the role
   * the job used to suggest — that link is gone. What somebody *is* and what
   * they can *open* are different questions, and conflating them means you
   * cannot have two drivers where one also keeps the books.
   */
  drives: boolean;

  /**
   * The rate card: one basis, and a figure for each tier.
   *
   * A **default at the moment of hire**, not a salary. Hiring into this job
   * opens the person a contract and copies the tier's figure onto it; payroll
   * reads the contract and never looks here again. So editing these changes
   * what the *next* hire is offered and nothing about anybody already on the
   * job — a live link would silently restate what every existing driver is
   * owed the day the rate moved.
   *
   * Three figures rather than five: contractual and part-time hires are
   * engagements rather than stages and are paid the regular figure.
   *
   * `has_rate_card` is sent rather than left to a client comparing figures to
   * zero — zero and "nobody has said" look identical from the outside, and only
   * one of them should fill in a form.
   */
  pay_basis: PayBasis;
  pay_basis_label: string;
  /** "a month", "a day", "a trip" — what the figures beside it mean. */
  pay_basis_unit: string;
  trainee_amount_cents: number;
  probationary_amount_cents: number;
  regular_amount_cents: number;
  has_rate_card: boolean;

  /**
   * What the job comes to on **one payslip**, in a sentence.
   *
   * The figure an office cannot work out from the form and most wants: ₱15,000
   * a month is not ₱15,000 a payslip, it is ₱7,500 twice — and whether it is
   * twice at all depends on the firm's cutoff, set on another screen entirely.
   *
   * Composed by the API, because that is where the pay calendar lives. A client
   * dividing by two would be a second implementation of the pay schedule, and
   * the first firm it got wrong would be the one paying monthly.
   */
  pay_summary: string;

  position: number;
  status: StatusValue;
  employee_count?: number;
}

/** How a figure is arrived at — and what the amount beside it is per. */
export type PayBasis = 'monthly' | 'daily' | 'per_trip';

/** The three columns of a rate card, in the order somebody moves through them. */
export const PAY_TIERS = ['trainee', 'probationary', 'regular'] as const;

export type PayTier = (typeof PAY_TIERS)[number];

export interface PositionPayload {
  name: string;
  description?: string | null;
  drives?: boolean;
  status?: StatusValue;
  /** The rate card. See `Position`. */
  pay_basis?: PayBasis;
  trainee_amount_cents?: number;
  probationary_amount_cents?: number;
  regular_amount_cents?: number;
}

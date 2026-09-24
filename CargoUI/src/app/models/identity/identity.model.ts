import { PayrollCalendar } from '../hr/payroll.model';

/**
 * `GET /api/v1/me` — drives the sidebar user chip (DESIGN.md section 7.2).
 *
 * `role` is the machine enum and `role_label` the display string; the client
 * uppercases the label and never reads words out of the enum.
 */
export interface Me {
  id: number;
  name: string;
  email: string;

  /**
   * The company this account signed in to.
   *
   * Never null, for any role — it is what the account belongs to. Shown in the
   * sidebar so nobody has to wonder whose system they are looking at.
   *
   * It is not a filter the client applies. Every list the API returns is
   * already scoped to this company server-side, from the same account this was
   * read off; sending an id back would be ignored.
   */
  company_id: string;
  company_name: string;
  company_code: string;
  /**
   * The company's mark, a 64px square PNG. Null means render its initials —
   * the same fallback the user chip makes for an account with no avatar.
   *
   * Derived server-side on every read, never stored, so it changes the moment
   * the logo does and cannot outlive a move of the install.
   */
  company_logo_url: string | null;

  role: string;
  role_label: string;
  /** Null means render initials. */
  avatar_url: string | null;
  permissions: string[];

  /** Present only when the account is linked to a driver record. */
  driver_id: string | null;
  licence_no: string | null;
  licence_expiry: string | null;
  available: boolean | null;
}

/**
 * `GET /api/v1/navigation` — the sidebar and the mobile tab bar.
 *
 * Already filtered by permission and already sorted. The client renders what
 * comes back and keeps no list of its own (DESIGN.md section 7.3).
 */
export interface NavItem {
  key: string;
  label: string;
  /** A name from the shared icon set, never a URL. */
  icon: string;
  route: string;
  order: number;
  mobile: boolean;
  /** Sidebar section heading; items sharing a group render together. */
  group: string;
  /** Absent or null means no badge. Zero is never sent. */
  badge?: number | null;
}

/**
 * `GET /api/v1/company` — the caller's own company, for the settings card.
 *
 * No id anywhere: it is read from and written to as *this* company, scoped to
 * the account, exactly as the driver and customer endpoints are.
 */
export interface Company {
  id: string;
  name: string;
  code: string;
  /** A 64px square PNG, or null. See `Me.company_logo_url`. */
  logo_url: string | null;
  contact_name: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  address: string | null;
  /**
   * Where the yard is, as a pin rather than as a sentence.
   *
   * What puts the company on the carrier list a shipper picks from in the
   * customer app: an address cannot be sorted by distance or drawn on a map.
   * Null until somebody drops one — at registration, or on the company card.
   */
  latitude: number | null;
  longitude: number | null;
  /** Derived from the pin: is this company offered to shippers at all? */
  discoverable: boolean;

  /**
   * Which cutoff the monthly SSS, PhilHealth and Pag-IBIG come off.
   *
   * A payroll *policy*, and it sits on the company for the reason the VAT rate
   * does: the SSS percentage is the government's and is the same for every firm
   * on the platform, while whether a firm loads a month of contributions onto
   * the first payslip or the second differs between two companies in the same
   * yard. All three answers remit the same amount — what changes is which
   * payslip is lighter.
   *
   * The label and the sentence come from the API so the screen offering the
   * choice does not keep its own copy of what each option means.
   */
  payroll_deduct_on: 'split' | 'first' | 'second';
  payroll_deduct_on_label: string;
  payroll_deduct_on_detail: string;

  /**
   * The days this firm's pay periods close on.
   *
   * The other payroll policy, and the one that used to be an environment
   * variable — which meant one cutoff for every haulier on the install, so a
   * firm closing on the 10th and the 25th could not be described at all.
   *
   * **Null means the install default**, which is what a firm that has never
   * touched the setting has. That is why there are two fields: this one is what
   * a settings form edits and may be empty, while `payroll_calendar` is always
   * the calendar actually in force. A screen showing an office its own cutoff
   * wants the second.
   */
  payroll_cutoff_days: number[] | null;
  payroll_calendar: PayrollCalendar;
}

/** What `POST /api/v1/login` takes. */
export interface Credentials {
  email: string;
  password: string;
  /** Present asks for a bearer token; absent sets the SPA cookie. */
  device_name?: string;
}

/**
 * What `POST /api/v1/register` takes — a company and the first person in it.
 *
 * One call, because they are one act: a company nobody can sign in to is a row,
 * and an account with no company has nowhere to put anything. It answers with
 * the same `Me` a login does, already signed in, so the client carries on into
 * the app rather than bouncing back to a form.
 *
 * There is no `code` field. The company's handle is derived from its name by
 * the API — it is an identifier rather than a choice, and asking somebody to
 * invent one is asking a question they have no basis to answer.
 */
export interface Registration {
  company_name: string;
  contact_phone?: string;
  address?: string;
  /**
   * The pin on the yard, store or depot.
   *
   * Optional, and a pair or neither — the API refuses half a coordinate. It is
   * what makes the company visible to shippers in the customer app, which is
   * why the sign-up form asks for it rather than leaving it to a settings
   * screen somebody may never open.
   */
  latitude?: number;
  longitude?: number;

  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

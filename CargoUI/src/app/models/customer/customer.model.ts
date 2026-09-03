import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/** Customer Management — records, transaction history, feedback. */
export interface Customer extends Timestamped {
  id: string;
  name: string;
  contact: string;
  /** Aggregates the API derives; never entered. */
  trips_total: number;
  outstanding_cents: number;
  currency: string;
  rating: number;
  status: StatusValue;
  /** What the firm signs in with. Null for a customer with no portal account. */
  login_email: string | null;
  /**
   * The starting password, on the response that just created the account and
   * nowhere else. The one chance the office has to read it and pass it on — a
   * customer read back later has null here.
   */
  default_password: string | null;
}

export interface CustomerPayload {
  name: string;
  contact: string;
  rating?: number;
  status?: StatusValue;
  /**
   * The address to give the firm a portal login at. Omitted rather than
   * blanked when there is none: sending it empty is not a request for an
   * account, and the API only ever adds one, never takes one away.
   */
  email?: string;
}

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
  /** A default, not a rule — the account still names its own role. */
  default_role_id: string | null;
  default_role_key: string | null;
  default_role_name: string | null;
  position: number;
  status: StatusValue;
  employee_count?: number;
}

export interface PositionPayload {
  name: string;
  description?: string | null;
  default_role_id?: string | null;
  status?: StatusValue;
}

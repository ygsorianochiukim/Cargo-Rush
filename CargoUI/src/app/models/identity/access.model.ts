import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

export interface Permission {
  id: string;
  key: string;
  name: string;
  description: string | null;
}

export interface PermissionGroup {
  group: string;
  permissions: Permission[];
}

export interface Role extends Timestamped {
  id: string;
  key: string;
  name: string;
  description: string | null;
  is_system: boolean;
  all_permissions: boolean;
  position: number;
  status: StatusValue;
  permissions: string[];
  permission_count: number | null;
  user_count?: number;
}

export interface RolePayload {
  name: string;
  description?: string | null;
  status?: StatusValue;
  permissions?: string[];
}

export interface Position extends Timestamped {
  id: string;
  key: string;
  name: string;
  description: string | null;

  drives: boolean;

  pay_basis: PayBasis;
  pay_basis_label: string;
  pay_basis_unit: string;
  trainee_amount_cents: number;
  probationary_amount_cents: number;
  regular_amount_cents: number;
  has_rate_card: boolean;

  pay_summary: string;

  position: number;
  status: StatusValue;
  employee_count?: number;
}

export type PayBasis = 'monthly' | 'daily' | 'per_trip';

export const PAY_TIERS = ['trainee', 'probationary', 'regular'] as const;

export type PayTier = (typeof PAY_TIERS)[number];

export interface PositionPayload {
  name: string;
  description?: string | null;
  drives?: boolean;
  status?: StatusValue;
  pay_basis?: PayBasis;
  trainee_amount_cents?: number;
  probationary_amount_cents?: number;
  regular_amount_cents?: number;
}

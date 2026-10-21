import { StatusValue } from '../shared/status.model';

export type AssetCondition = 'good' | 'fair' | 'damaged';

export interface AssetCategory {
  id: string;
  name: string;
  status: StatusValue;
  assets_count?: number;
}

export interface Asset {
  id: string;
  tag: string | null;
  name: string;
  category_id: string | null;
  category_name: string | null;
  serial_no: string | null;
  purchased_on: string | null;
  cost_cents: number | null;
  supplier_id: string | null;
  supplier_name: string | null;
  custodian_id: string | null;
  custodian_name: string | null;
  assigned_on: string | null;
  location: string | null;
  condition: AssetCondition;
  status: StatusValue;
  note: string | null;
}

export interface AssetTurnover {
  id: string;
  asset_id: string;
  from_employee_id: string | null;
  from_name: string | null;
  to_employee_id: string | null;
  to_name: string | null;
  turned_over_on: string;
  condition: AssetCondition;
  status: StatusValue;
  note: string | null;
  recorded_by_name: string | null;
}

export interface AssetTurnoverPayload {
  to_employee_id: string | null;
  turned_over_on: string;
  condition: AssetCondition;
  status?: string;
  note?: string | null;
}

export interface Custodian {
  value: string;
  label: string;
  position: string | null;
  department: string | null;
}

export interface AssetLogRow extends AssetTurnover {
  asset_name: string | null;
  asset_tag: string | null;
}

export interface AssetTally {
  count: number;
  cost_cents: number;
  assigned: number;
  available: number;
  maintenance: number;
  inactive: number;
}

export interface AssetCategoryLine extends AssetTally {
  category_id: string | null;
  name: string;
}

export interface AssetCustodianLine {
  employee_id: string;
  name: string;
  position: string | null;
  department: string | null;
  count: number;
  cost_cents: number;
  damaged: number;
  held_since: string | null;
}

export interface AssetBucket {
  key: string;
  label?: string;
  count: number;
  cost_cents: number;
}

export interface AssetReport {
  totals: AssetTally;
  by_category: AssetCategoryLine[];
  by_custodian: AssetCustodianLine[];
  by_condition: AssetBucket[];
  by_year: { year: number; count: number; cost_cents: number }[];
  by_age: AssetBucket[];
}

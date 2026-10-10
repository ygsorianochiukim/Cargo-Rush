import { PayrollCalendar } from '../hr/payroll.model';

export interface Me {
  id: number;
  name: string;
  email: string;

  company_id: string;
  company_name: string;
  company_code: string;
  company_logo_url: string | null;

  role: string;
  role_label: string;
  avatar_url: string | null;
  permissions: string[];

  driver_id: string | null;
  licence_no: string | null;
  licence_expiry: string | null;
  available: boolean | null;
}

export interface NavItem {
  key: string;
  label: string;
  icon: string;
  route: string;
  order: number;
  mobile: boolean;
  group: string;
  badge?: number | null;
}

export interface Company {
  id: string;
  name: string;
  code: string;
  logo_url: string | null;
  contact_name: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  address: string | null;
  latitude: number | null;
  longitude: number | null;
  discoverable: boolean;

  payroll_deduct_on: 'split' | 'first' | 'second';
  payroll_deduct_on_label: string;
  payroll_deduct_on_detail: string;

  payroll_benefits_enabled: boolean;

  payroll_cutoff_days: number[] | null;

  payroll_release_lag_days: number | null;

  payroll_calendar: PayrollCalendar;

  rates: CompanyRates;

  rate_defaults: CompanyRates;

  rate_overrides: CompanyRateOverrides;
}

export interface CompanyRates {
  trucker_commission_bp: number;
  billing_terms_days: number;
  vat_registered: boolean;
  vat_rate_bp: number;
  withholding_rate_bp: number;
  prices_include_vat: boolean;
  currency: string;
}

export interface CompanyRateOverrides {
  billing_terms_days: number | null;
  withholding_rate_bp: number | null;
  prices_include_vat: boolean | null;
}

export interface Credentials {
  email: string;
  password: string;
  device_name?: string;
}

export interface Registration {
  company_name: string;
  contact_phone?: string;
  address?: string;
  latitude?: number;
  longitude?: number;

  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

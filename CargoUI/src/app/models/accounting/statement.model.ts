export interface StatementAccount {
  id: string;
  code: string;
  name: string;
  balance_cents: number;
}

export interface StatementGroup {
  group: string;
  accounts: StatementAccount[];
  total_cents: number;
}

export interface StatementSection {
  groups: StatementGroup[];
  total_cents: number;
}

export interface IncomeStatement {
  range: { from: string | null; to: string | null };

  revenue: StatementSection;
  expenses: StatementSection;

  cost_of_services_cents: number | null;
  gross_profit_cents: number | null;
  gross_margin_pct: number | null;

  net_income_cents: number;
  net_margin_pct: number | null;

  currency: string;
}

export interface BalanceSheet {
  as_of: string;

  assets: StatementSection;
  liabilities: StatementSection;

  equity: {
    groups: StatementGroup[];
    posted_total_cents: number;
    earnings_not_closed_cents: number;
    total_cents: number;
  };

  liabilities_and_equity_cents: number;
  balanced: boolean;
  difference_cents: number;

  currency: string;
}

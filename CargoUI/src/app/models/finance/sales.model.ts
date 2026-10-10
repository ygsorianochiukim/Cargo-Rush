export type Granularity = 'daily' | 'weekly' | 'monthly';

export interface SalesBucket {
  key: string;
  label: string;
  from: string;
  to: string;
  sales_cents: number;
  expenses_cents: number;
  net_cents: number;
  entry_count: number;
}

export interface SalesTotals {
  sales_cents: number;
  expenses_cents: number;
  net_cents: number;
  margin: number | null;
  average_cents: number;
  best: SalesBucket | null;
}

export interface SalesReport {
  granularity: Granularity;
  range: { from: string; to: string };
  series: SalesBucket[];
  totals: SalesTotals;
  currency: string;
}

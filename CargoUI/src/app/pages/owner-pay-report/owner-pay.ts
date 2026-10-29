import { OwnerCostKind, OwnerCutoff, OwnerTransaction } from '../../models/finance/payables.model';
import { fmt } from '../../shared/format';

export const COST_KINDS: readonly OwnerCostKind[] = ['fuel', 'allowance', 'maintenance'];

export function costWord(kind: OwnerCostKind): string {
  switch (kind) {
    case 'fuel':
      return 'Fuel';
    case 'allowance':
      return 'Allowance';
    case 'maintenance':
      return 'Maintenance';
  }
}

export function percent(bp: number): string {
  return `${(bp / 100).toFixed(bp % 100 === 0 ? 0 : 2)}%`;
}

export function cutoffFormula(cutoff: OwnerCutoff): string {
  const costs = COST_KINDS.filter((kind) => cutoff[`${kind}_cents`] > 0).map(
    (kind) => `− ${fmt.pesos(cutoff[`${kind}_cents`])} ${costWord(kind).toLowerCase()}`,
  );

  return [
    `${fmt.pesos(cutoff.gross_cents)} income`,
    `− ${fmt.pesos(cutoff.commission_cents)} company share`,
    `= ${fmt.pesos(cutoff.share_cents)}`,
    ...costs,
    `= ${fmt.pesos(cutoff.net_cents)}`,
  ].join(' ');
}

export function cutoffState(cutoff: OwnerCutoff): { label: string; tone: string } {
  switch (cutoff.state) {
    case 'paid':
      return { label: 'Paid', tone: 'bg-cr-success-bg text-cr-success' };
    case 'processing':
      return { label: 'Sent · waiting to clear', tone: 'bg-cr-blue/10 text-cr-blue' };
    case 'open':
      return { label: 'Cutoff still running', tone: 'bg-cr-tint text-cr-ink-muted' };
    case 'unpaid':
      return cutoff.net_cents < 0
        ? { label: 'Costs exceed share', tone: 'bg-cr-red-bg text-cr-red' }
        : { label: 'Unpaid', tone: 'bg-cr-warning-bg text-cr-warning' };
    default:
      return { label: 'No runs', tone: 'bg-cr-tint text-cr-ink-muted' };
  }
}

export function transactionState(entry: OwnerTransaction): { label: string; tone: string } {
  switch (entry.state) {
    case 'paid':
      return { label: 'Paid', tone: 'bg-cr-success-bg text-cr-success' };
    case 'processing':
      return { label: 'Sent · waiting to clear', tone: 'bg-cr-blue/10 text-cr-blue' };
    case 'charged':
      return { label: 'Charged', tone: 'bg-cr-red-bg text-cr-red' };
    default:
      return { label: 'Credited', tone: 'bg-cr-tint text-cr-ink-muted' };
  }
}

export function transactionTitle(entry: OwnerTransaction): string {
  if (entry.kind === 'payout') {
    return entry.period_label ? `Settlement · ${entry.period_label}` : 'Paid out';
  }

  if (entry.kind === 'remittance') return 'Remitted by the owner';

  return entry.description;
}

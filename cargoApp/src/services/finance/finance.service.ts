import {
  LedgerEntry,
  LedgerEntryPayload,
  Truck,
} from '@/models/finance/finance.model';

import { api } from '../shared/api.service';

/**
 * The day's trip income and expenses, recorded from the cab.
 *
 * This writes the same row the back office reads in Daily Trip Monitoring —
 * there is no separate mobile ledger.
 */
export const financeService = {
  trucks(): Promise<Truck[]> {
    return api.get<Truck[]>('finance/trucks');
  },

  /** Routes the fleet actually runs, to suggest in the entry form. */
  routes(): Promise<string[]> {
    return api.get<string[]>('finance/routes');
  },

  /**
   * Record a day.
   *
   * Total expenses and net income are absent from the payload on purpose —
   * both are derived, and posting a total would let it disagree with its own
   * parts. The saved row comes back carrying both.
   */
  log(entry: LedgerEntryPayload): Promise<LedgerEntry> {
    return api.post<LedgerEntry>('ledger', entry);
  },
};

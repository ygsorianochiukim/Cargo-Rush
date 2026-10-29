import {
  ChangeDetectionStrategy,
  Component,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';

import { OwnerTransaction } from '../../models/finance/payables.model';
import { FinanceService } from '../../services/finance/finance.service';
import { fmt } from '../../shared/format';
import { EmptyState, ErrorState, SkeletonRows } from '../../shared/states';
import { transactionState, transactionTitle } from './owner-pay';

@Component({
  selector: 'app-owner-history-list',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [EmptyState, ErrorState, SkeletonRows],
  template: `
    @if (error(); as message) {
      <app-error-state [message]="message" (retry)="load()" />
    } @else if (rows() === null) {
      <app-skeleton-rows [count]="4" />
    } @else if (rows()!.length === 0) {
      <app-empty-state
        icon="wallet"
        title="Nothing settled yet"
        body="Every settlement, cost charged and remittance for this owner will be listed here."
      />
    } @else {
      <div class="overflow-x-auto rounded-control border border-cr-line">
        <table class="w-full min-w-[560px] text-[12px]">
          <thead class="bg-cr-tint text-left">
            <tr>
              <th class="px-3 py-2 font-semibold">Date</th>
              <th class="px-3 py-2 font-semibold">Transaction</th>
              <th class="px-3 py-2 font-semibold">How · reference</th>
              <th class="px-3 py-2 text-right font-semibold">Amount</th>
              <th class="px-3 py-2 text-right font-semibold">Status</th>
            </tr>
          </thead>
          <tbody>
            @for (entry of rows(); track entry.id) {
              <tr class="border-t border-cr-line/70">
                <td class="cr-num px-3 py-2 whitespace-nowrap">
                  {{ fmt.date(entry.occurred_on) }}
                </td>
                <td class="px-3 py-2">
                  <span class="block font-semibold">{{ title(entry) }}</span>
                  @if (entry.kind === 'payout' && entry.runs_count > 0) {
                    <span class="block text-cr-ink-muted">
                      {{ entry.runs_count }} {{ entry.runs_count === 1 ? 'run' : 'runs' }}
                      @if (entry.note && !entry.note.startsWith('Cutoff ')) {
                        · {{ entry.note }}
                      }
                    </span>
                  } @else if (entry.kind !== 'adjustment' && entry.note) {
                    <span class="block text-cr-ink-muted">{{ entry.note }}</span>
                  } @else if (entry.kind === 'adjustment' && entry.period_label) {
                    <span class="block text-cr-ink-muted"
                      >Taken off the {{ entry.period_label }} settlement</span
                    >
                  }
                </td>
                <td class="px-3 py-2">
                  {{ entry.method ? entry.method.replace('_', ' ') : '—' }}
                  @if (entry.reference) {
                    <span class="cr-num block text-cr-ink-muted">{{ entry.reference }}</span>
                  }
                </td>
                <td
                  class="cr-num px-3 py-2 text-right font-semibold whitespace-nowrap"
                  [class]="entry.amount_cents > 0 ? 'text-cr-success' : 'text-cr-ink'"
                >
                  {{ entry.amount_cents > 0 ? '+' : '−'
                  }}{{
                    fmt.pesos(entry.amount_cents < 0 ? -entry.amount_cents : entry.amount_cents)
                  }}
                </td>
                <td class="px-3 py-2 text-right">
                  <span
                    class="inline-flex rounded-full px-2 py-[3px] text-[10px] font-semibold tracking-[0.06em] uppercase whitespace-nowrap"
                    [class]="state(entry).tone"
                  >
                    {{ state(entry).label }}
                  </span>
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>
    }
  `,
})
export class OwnerHistoryList {
  private readonly finance = inject(FinanceService);

  readonly truckerId = input.required<string>();

  protected readonly fmt = fmt;
  protected readonly state = transactionState;
  protected readonly title = transactionTitle;

  protected readonly rows = signal<OwnerTransaction[] | null>(null);
  protected readonly error = signal<string | null>(null);

  constructor() {
    effect(() => {
      this.truckerId();

      untracked(() => this.load());
    });
  }

  protected load(): void {
    const id = this.truckerId();

    this.rows.set(null);
    this.error.set(null);

    if (!id) {
      this.rows.set([]);

      return;
    }

    this.finance.ownerHistory(id).subscribe({
      next: (rows) => this.rows.set(rows),
      error: () => this.error.set('Could not load the transaction history. Check the connection.'),
    });
  }
}

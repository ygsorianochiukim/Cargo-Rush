import { ChangeDetectionStrategy, Component, effect, inject, input, model, signal } from '@angular/core';
import { Router } from '@angular/router';

import { ReceivableLine, ReceivableLines } from '../../models/finance/finance.model';
import { FinanceService } from '../../services/finance/finance.service';
import { Column, DataTable } from '../../shared/data-table';
import { fmt } from '../../shared/format';
import { Modal } from '../../shared/modal';
import { ErrorState } from '../../shared/states';

/**
 * Who owed the fleet money at the close of the quarter, and how much.
 *
 * Opened from the Receivables tile, and asked about the quarter's last day
 * rather than today — the same way Payables is — so a closed quarter shows
 * what was outstanding then, including invoices that have since been paid.
 * Most overdue first, which is the order anybody chasing money works in.
 */
@Component({
  selector: 'app-receivable-lines-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DataTable, ErrorState, Modal],
  template: `
    <app-modal
      [(open)]="open"
      title="Receivables"
      [subtitle]="'Owed to us as at ' + fmt.date(asOf())"
      icon="billing"
      size="lg"
    >
      @if (error()) {
        <app-error-state [message]="error()!" (retry)="load()" />
      } @else {
        <app-data-table
          [columns]="columns"
          [rows]="report()?.lines ?? null"
          [minWidth]="640"
          rowAction="Open"
          emptyIcon="billing"
          emptyTitle="Nothing owed to us"
          emptyBody="Every invoice raised by this date had been paid."
          searchPlaceholder="Search a customer or invoice…"
          (open)="go($any($event))"
        />

        @if (report(); as r) {
          <div class="mt-3 flex items-baseline justify-between border-t border-cr-line pt-3">
            <span class="text-[14px] font-semibold">
              {{ r.lines.length }} {{ r.lines.length === 1 ? 'account' : 'accounts' }}
            </span>
            <span class="cr-num text-[16px] font-semibold">{{ fmt.pesos(r.total_cents) }}</span>
          </div>
        }
      }
    </app-modal>
  `,
})
export class ReceivableLinesDialog {
  private readonly financeApi = inject(FinanceService);
  private readonly router = inject(Router);

  readonly open = model(false);
  /** The quarter's last day. */
  readonly asOf = input.required<string>();

  protected readonly report = signal<ReceivableLines | null>(null);
  protected readonly error = signal<string | null>(null);

  protected readonly fmt = fmt;

  protected readonly columns: Column<ReceivableLine>[] = [
    {
      label: 'Owed by',
      kind: 'strong',
      value: (l) => l.counterparty,
      sub: (l) => l.reference ?? (l.source === 'trucker' ? 'Partner trucker' : null),
    },
    { label: 'Issued', kind: 'num', value: (l) => (l.issued_at ? fmt.date(l.issued_at) : null) },
    {
      label: 'Due',
      kind: 'num',
      value: (l) => (l.due_at ? fmt.date(l.due_at) : null),
      sub: (l) => (l.overdue ? 'Overdue' : null),
    },
    { label: 'Balance', kind: 'num', value: (l) => fmt.pesos(l.amount_cents) },
  ];

  constructor() {
    effect(() => {
      if (this.open()) this.load();
    });
  }

  protected load(): void {
    this.report.set(null);
    this.error.set(null);

    this.financeApi.receivableLines(this.asOf()).subscribe({
      next: (report) => this.report.set(report),
      error: () => this.error.set('Could not load the receivables. Check the connection and try again.'),
    });
  }

  /** An invoice opens itself; a partner's balance lives on the Truckers page. */
  protected go(line: ReceivableLine): void {
    this.open.set(false);

    if (line.source === 'invoice') {
      void this.router.navigate(['/billing', line.record_id]);
    } else {
      void this.router.navigate(['/truckers']);
    }
  }
}

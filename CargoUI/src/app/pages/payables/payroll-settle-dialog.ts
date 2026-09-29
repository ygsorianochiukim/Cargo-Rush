import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';

import { PayableLine } from '../../models/finance/payables.model';
import { PayrollService } from '../../services/hr/payroll.service';
import { fmt } from '../../shared/format';
import { Field } from '../../shared/field';
import { Modal } from '../../shared/modal';

/**
 * Settling a payroll line from Payables — a confirmation, not a form.
 *
 * Two kinds of payroll debt reach Payables, and each is one fact to confirm:
 *
 *   **Take-home pay** on an approved run (`id` is the run) — the money has
 *   been released to the staff. This is the same "pay" the Payroll screen
 *   records, and it posts the run to the books.
 *
 *   **Contributions and tax** on a paid run (`id` is `<run>:statutory`) — SSS,
 *   PhilHealth, Pag-IBIG and the BIR have been paid. The date and the office's
 *   reference (a PRN, an eFPS confirmation) are recorded with it.
 */
@Component({
  selector: 'app-payroll-settle-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Field, Modal],
  template: `
    <app-modal
      [open]="true"
      [title]="remittance() ? 'Record remittance' : 'Mark as paid'"
      [subtitle]="line().name"
      icon="badge"
      size="sm"
      [locked]="busy()"
      (closed)="closed.emit()"
    >
      <div class="rounded-control bg-cr-tint px-4 py-3">
        <p class="cr-meta">{{ remittance() ? 'TO THE AGENCIES' : 'TAKE-HOME PAY' }}</p>
        <p class="cr-num mt-1 text-[24px] font-bold">{{ money(line().amount_cents) }}</p>
        <p class="mt-1 text-[12px] text-cr-ink-muted">{{ line().detail }}</p>
      </div>

      @if (remittance()) {
        <p class="mt-4 text-[13px]">
          Confirm the SSS, PhilHealth, Pag-IBIG and withholding tax on this run have been paid. It
          comes off Payables and is posted to the books on the date below.
        </p>

        <!-- Our own number, assigned automatically; nothing to type. -->
        <div
          class="mt-4 flex items-center justify-between rounded-control border border-cr-line px-3 py-2"
        >
          <span class="cr-meta">REMITTANCE NO.</span>
          <span class="cr-num text-[14px] font-semibold">{{ number() ?? '…' }}</span>
        </div>

        <!--
          Stacked rather than side by side: at this dialog's width two
          columns left each box too narrow for its label and its hint.
        -->
        <div class="mt-4 space-y-3">
          <app-field label="DATE PAID" [required]="true">
            <input
              type="date"
              class="h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] focus:border-cr-blue focus:outline-none"
              [max]="today"
              [value]="on()"
              (input)="setDate($any($event.target).value)"
            />
          </app-field>
          <app-field
            label="AGENCY REFERENCE"
            hint="Optional — the SSS PRN or eFPS confirmation no."
          >
            <input
              type="text"
              maxlength="120"
              class="h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] focus:border-cr-blue focus:outline-none"
              [value]="reference()"
              (input)="reference.set($any($event.target).value)"
            />
          </app-field>
        </div>
      } @else {
        <p class="mt-4 text-[13px]">
          Confirm the take-home pay on this run has been released to the staff. This marks the run
          paid and posts it to the books — the same as paying it from the Payroll screen — and it
          cannot be undone except by a journal entry.
        </p>
      }

      @if (failure(); as message) {
        <p role="alert" class="mt-3 text-[12px] font-medium text-cr-red">{{ message }}</p>
      }

      <ng-container modal-footer>
        <button
          type="button"
          class="h-10 rounded-control px-4 text-[14px] font-semibold text-cr-ink transition-colors hover:bg-cr-tint disabled:opacity-60"
          [disabled]="busy()"
          (click)="closed.emit()"
        >
          Cancel
        </button>
        <button
          type="button"
          class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
          [disabled]="busy() || (remittance() && !on())"
          (click)="confirm()"
        >
          {{ busy() ? 'Saving…' : remittance() ? 'Mark as remitted' : 'Mark as paid' }}
        </button>
      </ng-container>
    </app-modal>
  `,
})
export class PayrollSettleDialog {
  private readonly payroll = inject(PayrollService);

  readonly line = input.required<PayableLine>();

  /** Recorded — the page re-reads what is owed. */
  readonly settled = output<void>();
  readonly closed = output<void>();

  protected readonly today = new Date().toLocaleDateString('en-CA');

  protected readonly on = signal(this.today);
  protected readonly reference = signal('');
  protected readonly busy = signal(false);
  protected readonly failure = signal<string | null>(null);

  /** The contributions line carries the run id with a `:statutory` suffix. */
  protected readonly remittance = computed(() => this.line().id.endsWith(':statutory'));
  private readonly runId = computed(() => this.line().id.split(':')[0]);

  /** The REM number this remittance will be given — shown, never typed. */
  protected readonly number = signal<string | null>(null);

  constructor() {
    this.preview(this.today);
  }

  /** A remittance is numbered in the year it was sent, so a date can change it. */
  protected setDate(value: string): void {
    const year = this.on().slice(0, 4);

    this.on.set(value);

    if (value && value.slice(0, 4) !== year) this.preview(value);
  }

  private preview(on: string): void {
    this.number.set(null);
    this.payroll.nextRemittanceNo(on).subscribe({
      next: (no) => this.number.set(no),
      // Only a preview: saving assigns the number regardless.
      error: () => this.number.set('Assigned on save'),
    });
  }

  protected money(cents: number): string {
    return fmt.pesos(cents);
  }

  protected confirm(): void {
    this.busy.set(true);
    this.failure.set(null);

    const request = this.remittance()
      ? this.payroll.remit(this.runId(), {
          remitted_on: this.on(),
          reference: this.reference().trim() || null,
        })
      : this.payroll.pay(this.runId());

    request.subscribe({
      next: () => {
        this.busy.set(false);
        this.settled.emit();
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);

        const errors = error.error?.errors as Record<string, string[]> | undefined;

        this.failure.set(
          error.status === 403
            ? 'You can see payroll but not settle it. Ask whoever runs payroll.'
            : ((errors && Object.values(errors)[0]?.[0]) ??
                error.error?.message ??
                'Could not save that. Check the connection and try again.'),
        );
      },
    });
  }
}

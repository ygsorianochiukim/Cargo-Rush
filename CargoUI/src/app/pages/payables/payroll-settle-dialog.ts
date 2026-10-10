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

        <div
          class="mt-4 flex items-center justify-between rounded-control border border-cr-line px-3 py-2"
        >
          <span class="cr-meta">REMITTANCE NO.</span>
          <span class="cr-num text-[14px] font-semibold">{{ number() ?? '…' }}</span>
        </div>

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

  readonly settled = output<void>();
  readonly closed = output<void>();

  protected readonly today = new Date().toLocaleDateString('en-CA');

  protected readonly on = signal(this.today);
  protected readonly reference = signal('');
  protected readonly busy = signal(false);
  protected readonly failure = signal<string | null>(null);

  protected readonly remittance = computed(() => this.line().id.endsWith(':statutory'));
  private readonly runId = computed(() => this.line().id.split(':')[0]);

  protected readonly number = signal<string | null>(null);

  constructor() {
    this.preview(this.today);
  }

  protected setDate(value: string): void {
    const year = this.on().slice(0, 4);

    this.on.set(value);

    if (value && value.slice(0, 4) !== year) this.preview(value);
  }

  private preview(on: string): void {
    this.number.set(null);
    this.payroll.nextRemittanceNo(on).subscribe({
      next: (no) => this.number.set(no),
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

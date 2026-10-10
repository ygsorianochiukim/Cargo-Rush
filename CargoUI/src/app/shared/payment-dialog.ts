import {
  ChangeDetectionStrategy,
  Component,
  Injectable,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Subject } from 'rxjs';

import { Invoice } from '../models/billing/billing.model';
import { BillingService } from '../services/billing/billing.service';
import { Field } from './field';
import { fmt } from './format';
import { Modal } from './modal';

@Injectable({ providedIn: 'root' })
export class PaymentDialog {
  readonly open = signal(false);

  readonly invoice = signal<Invoice | null>(null);

  readonly choices = signal<Invoice[]>([]);

  private readonly recordedSubject = new Subject<void>();

  readonly recorded = this.recordedSubject.asObservable();

  forInvoice(invoice: Invoice): void {
    this.invoice.set(invoice);
    this.choices.set([]);
    this.open.set(true);
  }

  forAny(choices: Invoice[]): void {
    this.invoice.set(null);
    this.choices.set(choices);
    this.open.set(true);
  }

  announceRecorded(): void {
    this.recordedSubject.next();
  }
}

@Component({
  selector: 'app-payment-form',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Field, Modal, ReactiveFormsModule],
  template: `
    <app-modal
      [open]="dialog.open()"
      (openChange)="dialog.open.set($event)"
      title="Record a payment"
      [subtitle]="subtitle()"
      icon="wallet"
      size="sm"
    >
      <form [formGroup]="form" class="flex flex-col gap-3">
        @if (dialog.invoice() === null) {
          <app-field label="Against which invoice" required [error]="errorFor('invoice_id')">
            <select
              formControlName="invoice_id"
              [class]="inputClass"
              (change)="pick($any($event.target).value)"
            >
              <option value="">Choose an invoice…</option>
              @for (choice of dialog.choices(); track choice.id) {
                <option [value]="choice.id">
                  {{ choice.number }} — {{ choice.customer }} ·
                  {{ fmt.money(choice.balance_cents, choice.currency) }} owed
                </option>
              }
            </select>
          </app-field>
        }

        <app-field
          [label]="paying() ? 'Amount paid (₱)' : 'Amount received (₱)'"
          required
          [hint]="balanceHint()"
          [error]="errorFor('amount')"
        >
          <input type="number" step="0.01" min="0" formControlName="amount" [class]="inputClass" />
        </app-field>

        <app-field
          [label]="paying() ? 'Date paid' : 'Date received'"
          required
          [error]="errorFor('paid_on')"
        >
          <input type="date" formControlName="paid_on" [class]="inputClass" />
        </app-field>

        <app-field label="How" required>
          <select formControlName="method" [class]="inputClass">
            <option value="bank_transfer">Bank transfer</option>
            <option value="cash">Cash</option>
            <option value="cheque">Cheque</option>
            <option value="gcash">GCash</option>
            <option value="other">Other</option>
          </select>
        </app-field>

        <app-field
          label="Reference"
          [hint]="
            paying()
              ? 'The cheque number or transfer reference — what a bank statement can be matched against.'
              : 'The deposit slip or cheque number — what a bank statement can be matched against.'
          "
          [error]="errorFor('reference')"
        >
          <input
            type="text"
            formControlName="reference"
            placeholder="BDO-77120"
            [class]="inputClass"
          />
        </app-field>

        @if (error(); as message) {
          <p
            class="rounded-control bg-cr-red-bg px-3 py-2 text-[13px] font-medium text-cr-red"
            role="alert"
          >
            {{ message }}
          </p>
        }
      </form>

      <ng-container modal-footer>
        <button
          type="button"
          class="h-10 rounded-control px-4 text-[14px] font-semibold text-cr-ink-muted transition-colors hover:bg-cr-tint"
          (click)="dialog.open.set(false)"
        >
          Cancel
        </button>
        <button
          type="button"
          class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-50"
          [disabled]="saving()"
          (click)="save()"
        >
          {{ saving() ? 'Recording…' : 'Record payment' }}
        </button>
      </ng-container>
    </app-modal>
  `,
})
export class PaymentForm {
  protected readonly dialog = inject(PaymentDialog);
  private readonly billing = inject(BillingService);
  private readonly fb = inject(FormBuilder);

  protected readonly fmt = fmt;
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  private readonly fieldErrors = signal<Record<string, string[]>>({});

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly form = this.fb.nonNullable.group({
    invoice_id: [''],
    amount: [0, [Validators.required, Validators.min(0.01)]],
    paid_on: ['', Validators.required],
    method: ['bank_transfer', Validators.required],
    reference: [''],
  });

  private readonly pickedId = signal('');

  private readonly target = computed(() => {
    const fixed = this.dialog.invoice();
    if (fixed !== null) return fixed;

    const id = this.pickedId();

    return this.dialog.choices().find((choice) => choice.id === id) ?? null;
  });

  protected readonly paying = computed(() => this.dialog.invoice()?.direction === 'payable');

  protected readonly subtitle = computed(() => {
    const invoice = this.dialog.invoice();

    return invoice === null
      ? 'The invoice becomes paid — or part paid — from what is recorded here.'
      : `${invoice.number} · ${invoice.customer ?? invoice.payee ?? ''}`;
  });

  protected readonly balanceHint = computed(() => {
    const invoice = this.target();

    if (invoice === null) return 'Choose an invoice and this fills in what is owed.';

    return `${fmt.money(invoice.balance_cents, invoice.currency)} owed. Record less for a part payment.`;
  });

  constructor() {
    effect(() => {
      if (this.dialog.open()) this.reset();
    });
  }

  protected pick(id: string): void {
    this.pickedId.set(id);

    const invoice = this.target();

    if (invoice !== null) {
      this.form.patchValue({ amount: invoice.balance_cents / 100 });
    }
  }

  protected errorFor(field: string): string | null {
    const fromApi =
      this.fieldErrors()[field]?.[0] ??
      (field === 'amount' ? this.fieldErrors()['amount_cents']?.[0] : undefined) ??
      (field === 'invoice_id' ? this.fieldErrors()['allocations.0.invoice_id']?.[0] : undefined);

    if (fromApi) return fromApi;

    const control = this.form.get(field);
    if (!control || control.valid || !(control.touched || control.dirty)) return null;

    if (control.hasError('required')) return 'This is required.';
    if (control.hasError('min')) return 'Enter an amount.';

    return 'Check this value.';
  }

  protected save(): void {
    if (this.saving()) return;

    const invoice = this.target();

    if (invoice === null) {
      this.error.set('Choose the invoice this payment is against.');

      return;
    }

    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }

    const values = this.form.getRawValue();
    const cents = Math.round(Number(values.amount) * 100);

    this.saving.set(true);
    this.error.set(null);
    this.fieldErrors.set({});

    this.billing
      .recordPayment({
        customer_id: invoice.customer_id,
        direction: invoice.direction,
        amount_cents: cents,
        paid_on: values.paid_on,
        method: values.method,
        reference: values.reference || null,
        allocations: [{ invoice_id: invoice.id, amount_cents: cents }],
      })
      .subscribe({
        next: () => {
          this.saving.set(false);
          this.dialog.open.set(false);
          this.dialog.announceRecorded();
          this.reset();
        },
        error: (failure: { error?: { message?: string; errors?: Record<string, string[]> } }) => {
          this.saving.set(false);
          this.fieldErrors.set(failure.error?.errors ?? {});
          this.error.set(
            failure.error?.errors ? null : (failure.error?.message ?? 'That was not accepted.'),
          );
        },
      });
  }

  private reset(): void {
    const invoice = this.dialog.invoice();

    this.pickedId.set(invoice?.id ?? '');

    this.form.reset({
      invoice_id: invoice?.id ?? '',
      amount: invoice ? invoice.balance_cents / 100 : 0,
      paid_on: new Date().toISOString().slice(0, 10),
      method: 'bank_transfer',
      reference: '',
    });

    this.fieldErrors.set({});
    this.error.set(null);
  }
}

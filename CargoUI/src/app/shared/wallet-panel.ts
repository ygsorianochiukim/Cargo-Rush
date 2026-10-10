import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
} from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';

import { Wallet } from '../models/trucker/trucker.model';
import { TruckerService } from '../services/trucker/trucker.service';
import { Field } from './field';
import { fmt } from './format';
import { Icon } from './icon';
import { EmptyState, ErrorState, SkeletonRows } from './states';

@Component({
  selector: 'app-wallet-panel',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Field, Icon, SkeletonRows, EmptyState, ErrorState, ReactiveFormsModule],
  templateUrl: './wallet-panel.html',
})
export class WalletPanel {
  private readonly truckersApi = inject(TruckerService);
  private readonly fb = inject(FormBuilder);

  readonly truckerId = input.required<string>();

  readonly changed = output<void>();

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly wallet = signal<Wallet | null>(null);
  protected readonly walletError = signal<string | null>(null);

  protected readonly busy = signal(false);
  protected readonly formError = signal<string | null>(null);

  protected readonly settleKind = signal<'payout' | 'remittance' | 'adjustment'>('payout');

  protected readonly settleForm = this.fb.group({
    amount: [null as number | null],
    method: ['bank_transfer'],
    cleared: [false],
    reference: [''],
    note: [''],
  });

  protected readonly picked = signal<Set<string>>(new Set());

  constructor() {
    effect(() => {
      const id = this.truckerId();

      untracked(() => {
        this.formError.set(null);
        this.settleForm.reset({
          amount: null,
          method: 'bank_transfer',
          cleared: false,
          reference: '',
          note: '',
        });
        this.load(id);
      });
    });
  }

  private load(id: string): void {
    this.wallet.set(null);
    this.walletError.set(null);
    this.picked.set(new Set());

    this.truckersApi.wallet(id).subscribe({
      next: (wallet) => {
        this.wallet.set(wallet);

        this.settleKind.set(
          wallet.unpaid_earnings_count > 0
            ? 'payout'
            : wallet.unremitted_commission_count > 0
              ? 'remittance'
              : 'adjustment',
        );
      },
      error: () => this.walletError.set('Could not load this wallet.'),
    });
  }

  protected readonly standing = computed(() => {
    const wallet = this.wallet();

    if (wallet === null) return null;

    return wallet.standing === 'owed_to_company'
      ? {
          label: 'Owes the fleet',
          tone: 'text-cr-red',
          hint: 'Take a remittance, or let it net against their next payout.',
        }
      : wallet.standing === 'owed_to_trucker'
        ? {
            label: 'Owed to this trucker',
            tone: 'text-cr-success',
            hint: 'Pay this out and record the reference.',
          }
        : { label: 'Settled', tone: 'text-cr-ink-muted', hint: 'Nothing owed either way.' };
  });

  protected readonly settleKinds = computed(() => {
    const wallet = this.wallet();

    return [
      ...((wallet?.unpaid_earnings_count ?? 0) > 0
        ? [{ value: 'payout', label: 'Pay the trucker' }]
        : []),
      ...((wallet?.unremitted_commission_count ?? 0) > 0
        ? [{ value: 'remittance', label: 'Collect commission' }]
        : []),
      { value: 'adjustment', label: 'Adjustment' },
    ];
  });

  protected confirmPayment(entryId: string): void {
    if (this.busy()) return;

    const id = this.truckerId();

    this.busy.set(true);
    this.formError.set(null);

    this.truckersApi.confirmPayment(id, entryId).subscribe({
      next: () => {
        this.busy.set(false);
        this.load(id);
        this.changed.emit();
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.formError.set(this.messageFor(error));
      },
    });
  }

  protected pick(entryId: string): void {
    const next = new Set(this.picked());

    next.has(entryId) ? next.delete(entryId) : next.add(entryId);

    this.picked.set(next);
  }

  protected readonly outstanding = computed(() => {
    const wallet = this.wallet();
    const kind = this.settleKind();

    if (wallet === null || kind === 'adjustment') return [];

    const want = kind === 'payout' ? 'earning' : 'commission';

    return wallet.entries.filter((entry) => entry.kind === want && !entry.settled);
  });

  protected pickKind(kind: string): void {
    this.settleKind.set(kind as 'payout' | 'remittance' | 'adjustment');
    this.picked.set(new Set());
  }

  protected readonly settleTotal = computed(() => {
    const chosen = this.picked();
    const rows = this.outstanding();
    const counted = chosen.size === 0 ? rows : rows.filter((entry) => chosen.has(entry.id));

    return counted.reduce((sum, entry) => sum + Math.abs(entry.amount_cents), 0);
  });

  protected settle(): void {
    const id = this.truckerId();
    const raw = this.settleForm.getRawValue();
    const kind = this.settleKind();

    if (kind === 'adjustment') {
      if (!raw.note?.trim()) {
        this.formError.set('Say what this adjustment is for.');

        return;
      }

      if (!raw.amount) {
        this.formError.set('Enter the amount to adjust by.');

        return;
      }
    } else if (this.outstanding().length === 0) {
      this.formError.set('There is nothing outstanding to settle.');

      return;
    }

    this.busy.set(true);
    this.formError.set(null);

    this.truckersApi
      .settle(id, {
        kind,
        ...(kind === 'adjustment'
          ? {
              amount_cents: Math.round((raw.amount ?? 0) * 100),
            }
          : {
              entry_ids: [...this.picked()],
              method: raw.method ?? 'bank_transfer',
              cleared: Boolean(raw.cleared),
            }),
        reference: raw.reference?.trim() || null,
        note: raw.note?.trim() || null,
      })
      .subscribe({
        next: () => {
          this.busy.set(false);
          this.settleForm.reset({
            amount: null,
            method: raw.method ?? 'bank_transfer',
            cleared: false,
            reference: '',
            note: '',
          });
          this.load(id);
          this.changed.emit();
        },
        error: (error: HttpErrorResponse) => {
          this.busy.set(false);
          this.formError.set(this.messageFor(error));
        },
      });
  }

  protected money(cents: number): string {
    return fmt.pesos(cents);
  }

  protected rate(bp: number): string {
    return `${(bp / 100).toFixed(bp % 100 === 0 ? 0 : 1)}%`;
  }

  private messageFor(error: HttpErrorResponse): string {
    const errors = error.error?.errors as Record<string, string[]> | undefined;

    if (error.status === 422 && errors) {
      return Object.values(errors)[0]?.[0] ?? 'Check the figures and try again.';
    }

    return error.error?.message ?? 'Could not do that. Check the connection and try again.';
  }
}

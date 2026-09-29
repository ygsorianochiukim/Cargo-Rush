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

/**
 * One partner's wallet — the balance, the arithmetic behind it, and the settle
 * form.
 *
 * Lifted out of the Truckers drawer so Payables can settle a partner (or a
 * rented truck's owner, which is the same wallet) where the reader already is,
 * instead of sending them to the roster to open the same thing there.
 *
 * Each partner has one running account that nets both directions: what the
 * fleet owes them for work it billed, less what they owe the fleet on runs they
 * billed themselves. `changed` fires after anything moves money, so the page
 * around it can re-read its own figures.
 */
@Component({
  selector: 'app-wallet-panel',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Field, Icon, SkeletonRows, EmptyState, ErrorState, ReactiveFormsModule],
  templateUrl: './wallet-panel.html',
})
export class WalletPanel {
  private readonly truckersApi = inject(TruckerService);
  private readonly fb = inject(FormBuilder);

  /** The partner whose wallet this is. */
  readonly truckerId = input.required<string>();

  /** A payment, remittance, adjustment or confirmation went through. */
  readonly changed = output<void>();

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly wallet = signal<Wallet | null>(null);
  protected readonly walletError = signal<string | null>(null);

  protected readonly busy = signal(false);
  protected readonly formError = signal<string | null>(null);

  /**
   * Which settlement is being recorded.
   *
   * A signal rather than a form control, and that is a correctness fix
   * rather than a style choice: `outstanding()` and `settleTotal()` are
   * computed from it, and a `FormControl`'s value is a plain property that
   * signals cannot track. Switching the select left both showing the
   * *previous* kind's runs and the previous total — so the figure on the
   * button could disagree with what the button was about to settle.
   */
  protected readonly settleKind = signal<'payout' | 'remittance' | 'adjustment'>('payout');

  /**
   * The rest of the settle form.
   *
   * Pesos in, centavos on the wire. The office types ₱8,800 and the API is
   * integer centavos throughout — the boundary here is the only place a
   * decimal is allowed anywhere near this.
   */
  protected readonly settleForm = this.fb.group({
    /**
     * Only an adjustment carries a figure now.
     *
     * A payout is the sum of the runs it covers — see `picked` below — so
     * typing one would be a second opinion about what is being handed over.
     * The API refuses an amount on a settlement outright.
     */
    amount: [null as number | null],
    /** How it goes out. A transfer unless somebody says otherwise. */
    method: ['bank_transfer'],
    /**
     * Has it landed already?
     *
     * Ticked for cash across the desk, which has arrived by the time anybody
     * types it. Left alone for a transfer, which takes a day — and until it
     * is confirmed the partner is still owed the money.
     */
    cleared: [false],
    reference: [''],
    note: [''],
  });

  /**
   * Which runs the next payout covers.
   *
   * Empty means all of them, which is what the API does with an empty list and
   * what the "Pay everything outstanding" button sends. Ticking individual
   * rows is the part payment.
   */
  protected readonly picked = signal<Set<string>>(new Set());

  constructor() {
    // A fresh form for each partner opened.
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

  /**
   * Read the wallet again.
   *
   * Ticks do not survive a reload: the rows behind them may have just been
   * settled, and a stale id would be refused on the next press.
   */
  private load(id: string): void {
    this.wallet.set(null);
    this.walletError.set(null);
    this.picked.set(new Set());

    this.truckersApi.wallet(id).subscribe({
      next: (wallet) => {
        this.wallet.set(wallet);

        /**
         * Start on something that can actually be settled.
         *
         * Defaulting to a payout was wrong for a partner who owes rather than
         * is owed: the select would show a kind that is not in its own option
         * list, and the button beneath it would offer to pay nothing.
         */
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

  /**
   * What the balance means, in a sentence.
   *
   * Never a bare signed figure. "−₱1,200" tells somebody at the desk nothing
   * about which way to point a cheque, and the two directions are not symmetric
   * — one is a payable and the other a receivable.
   */
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

  /**
   * What can be settled right now.
   *
   * Driven by **what is outstanding on each side**, never by the net balance,
   * and that distinction was a real bug rather than a refinement. A busy
   * partner ordinarily owes and is owed at the same time — some of the
   * fleet's work, some of their own — and netting them hid one of the two
   * options completely: ₱8,800 unpaid against ₱1,200 of commission nets to
   * +₱7,600, so the form offered "Pay out" and no way at all to collect the
   * commission. The API had always allowed it; only this list refused.
   *
   * The two debts are settled separately, against different runs, so each
   * appears exactly when it has runs waiting.
   */
  protected readonly settleKinds = computed(() => {
    const wallet = this.wallet();

    return [
      ...((wallet?.unpaid_earnings_count ?? 0) > 0
        ? [{ value: 'payout', label: 'Pay the trucker' }]
        : []),
      /**
       * Worded from the desk's side on purpose.
       *
       * It is the *trucker* who pays this — the fleet's cut of a run they
       * billed the customer for themselves — so "Pay commission" on a screen
       * the office is using would read exactly backwards. "Collect" is what
       * the person pressing it is doing.
       */
      ...((wallet?.unremitted_commission_count ?? 0) > 0
        ? [{ value: 'remittance', label: 'Collect commission' }]
        : []),
      { value: 'adjustment', label: 'Adjustment' },
    ];
  });

  /**
   * Confirm a payment has landed.
   *
   * The moment the balance falls. Separate from recording the payment
   * because they are two facts on two different days — the transfer goes out
   * on Monday and clears on Tuesday.
   */
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

  /** Tick or untick one run for the next payout. */
  protected pick(entryId: string): void {
    const next = new Set(this.picked());

    next.has(entryId) ? next.delete(entryId) : next.add(entryId);

    this.picked.set(next);
  }

  /**
   * The outstanding runs of whichever direction is being settled.
   *
   * Earnings for a payout, commissions for a remittance — the two never mix,
   * because money going out and money coming in are not one transaction.
   */
  protected readonly outstanding = computed(() => {
    const wallet = this.wallet();
    const kind = this.settleKind();

    if (wallet === null || kind === 'adjustment') return [];

    const want = kind === 'payout' ? 'earning' : 'commission';

    return wallet.entries.filter((entry) => entry.kind === want && !entry.settled);
  });

  /**
   * Switch which settlement is being recorded.
   *
   * The ticks are dropped with it: they name runs of the *other* kind, and
   * carrying them across would send earning ids to a commission collection,
   * which the API would refuse — after the person had pressed.
   */
  protected pickKind(kind: string): void {
    this.settleKind.set(kind as 'payout' | 'remittance' | 'adjustment');
    this.picked.set(new Set());
  }

  /**
   * What the button is about to hand over.
   *
   * Derived from the ticked rows, or from all of them when none is ticked —
   * exactly what the API will do — so the figure on screen is the figure that
   * gets written.
   */
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
              // Pesos to centavos, rounded rather than truncated: 88.8 * 100
              // is 8879.999… in binary floating point, and `Math.trunc` would
              // quietly be a centavo short.
              amount_cents: Math.round((raw.amount ?? 0) * 100),
            }
          : {
              // Empty means everything outstanding, which is what the API
              // does with an empty list — so there is no separate "pay all".
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

  /** Centavos as pesos. The API never formats money and never sends a float. */
  protected money(cents: number): string {
    return fmt.pesos(cents);
  }

  protected rate(bp: number): string {
    // 12, not 12.00 — a whole percentage is the ordinary case. A fleet rate of
    // 7.5% still reads correctly.
    return `${(bp / 100).toFixed(bp % 100 === 0 ? 0 : 1)}%`;
  }

  /** The API's own words. A 422 here is a real rule, not a form slip. */
  private messageFor(error: HttpErrorResponse): string {
    const errors = error.error?.errors as Record<string, string[]> | undefined;

    if (error.status === 422 && errors) {
      return Object.values(errors)[0]?.[0] ?? 'Check the figures and try again.';
    }

    return error.error?.message ?? 'Could not do that. Check the connection and try again.';
  }
}

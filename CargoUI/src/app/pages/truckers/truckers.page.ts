import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';

import { Trucker, Wallet } from '../../models/trucker/trucker.model';
import { TruckerService } from '../../services/trucker/trucker.service';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { Modal } from '../../shared/modal';
import { EmptyState, ErrorState, SkeletonRows } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';

/**
 * Truckers — the partner roster.
 *
 * The office's side of the third operator this system knows about. A driver is
 * an employee and appears in Drivers Management; a trucker owns their truck,
 * chooses which work to take, and is paid a share of what each run billed
 * rather than a wage. The two rosters are separate screens because almost
 * nothing on one is a question you would ask about the other.
 *
 * ## Three things happen here, and only one of them is routine
 *
 * **Vetting.** A registration lands `pending` and the partner's job board is
 * empty until somebody at this desk has read their licence and approved them.
 * That queue is the sidebar badge, and it is the reason this page opens on
 * `pending` first: somebody is sitting in a waiting room.
 *
 * **The rate.** What the fleet keeps of a partner's runs. It is a commercial
 * term and it is negotiable, so it is editable here — and changing it touches
 * nothing already earned, because every settled run froze its own rate at
 * delivery.
 *
 * **The money.** Each partner has one running account that nets both
 * directions: what the fleet owes them for work it billed, less what they owe
 * the fleet on runs they billed themselves. The drawer shows the balance, the
 * arithmetic behind it, and the settle form.
 *
 * Everything on this page is behind `truckers.manage` except the reading, which
 * is `truckers.view` — the split the drivers module already uses, and for
 * firmer reasons here, since two of the three verbs move money.
 */
@Component({
  selector: 'app-truckers',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    Card,
    Field,
    Icon,
    Modal,
    StatusPill,
    SkeletonRows,
    EmptyState,
    ErrorState,
    ReactiveFormsModule,
  ],
  templateUrl: './truckers.page.html',
})
export class TruckersPage {
  private readonly truckersApi = inject(TruckerService);
  private readonly confirm = inject(Confirm);
  private readonly fb = inject(FormBuilder);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  /** Null while loading — the four list states depend on telling that apart. */
  protected readonly truckers = signal<Trucker[] | null>(null);
  protected readonly loadError = signal<string | null>(null);

  /**
   * Which standing is on screen.
   *
   * Opens on `pending`, deliberately. A registration nobody has read is
   * somebody waiting on this desk with an empty app, and it is the one thing
   * here with a person on the other end of it.
   */
  protected readonly tab = signal<'pending' | 'active' | 'inactive' | 'all'>('pending');

  /* ------------------------------------------------------------ The drawer */

  protected readonly openTrucker = signal<Trucker | null>(null);
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
    this.refresh();

    const settle = this.route.snapshot.queryParamMap.get('settle');

    if (settle !== null) this.openById(settle);
  }

  /**
   * Open one partner's wallet because a link asked for it.
   *
   * Payables sends people here with a figure in mind — "Yvert Soriano,
   * ₱33,123.20" — and landing them on the roster made them find that name
   * again by eye, on a tab that opens on `pending` and therefore does not
   * contain an approved partner at all. The link now names the row and this
   * opens it.
   *
   * Fetched by id rather than looked up in `refresh()`'s page of 100: the
   * roster is paginated and the partner who is owed the most is not
   * necessarily on the first page. It is also one less race — the drawer does
   * not have to wait for the list.
   *
   * The tab goes to `all` so the roster behind the drawer contains the person
   * in front of it. Following their own standing would be more precise and
   * more fragile: a partner on hold is a status the tabs do not each have a
   * home for, and being shown an empty list on close is worse than being shown
   * everyone.
   */
  private openById(id: string): void {
    this.truckersApi.find(id).subscribe({
      next: (trucker) => {
        this.tab.set('all');
        this.open(trucker);
      },
      /**
       * Said plainly rather than silently.
       *
       * Somebody followed a link to a figure they are trying to pay, and a
       * roster that just sits there reads as "nothing owed". The two ways
       * this fails need different words: the partner is gone, or the reader
       * can see what the week costs without being allowed to open a wallet —
       * Payables is `finance.view` and this is `truckers.view`, so that
       * combination is a real one and the server's own wording is the honest
       * answer to it.
       */
      error: (error: HttpErrorResponse) =>
        this.loadError.set(
          error.status === 404
            ? 'That trucker is no longer on the roster.'
            : this.messageFor(error),
        ),
    });
  }

  protected refresh(): void {
    this.truckersApi.list({ per_page: 100 }).subscribe({
      next: (res) => {
        this.truckers.set(res.data);
        this.loadError.set(null);
      },
      error: () => {
        this.truckers.set(null);
        this.loadError.set('Could not load the roster. Check the connection and try again.');
      },
    });
  }

  protected readonly rows = computed(() => {
    const all = this.truckers();

    if (all === null) return null;

    return this.tab() === 'all' ? all : all.filter((t) => t.status === this.tab());
  });

  protected countOf(status: 'pending' | 'active' | 'inactive' | 'all'): number {
    const all = this.truckers() ?? [];

    return status === 'all' ? all.length : all.filter((t) => t.status === status).length;
  }

  /* ------------------------------------------------------------- Deciding */

  /**
   * Approve a registration, from the roster or from inside the drawer.
   *
   * Both, because vetting is a review followed by a decision and the two
   * happen in different places: a desk that already knows the person approves
   * off the list, and one that does not opens them first to read the licence
   * and the truck. Making them close the drawer to find the button would be
   * asking somebody to stop reviewing in order to decide.
   *
   * The open drawer is refreshed rather than closed, so whoever just approved
   * somebody sees the standing change under their hand.
   */
  protected approve(trucker: Trucker): void {
    this.truckersApi.approve(trucker.id).subscribe({
      next: (updated) => {
        this.refresh();

        if (this.openTrucker()?.id === trucker.id) this.openTrucker.set(updated);
      },
      error: (error: HttpErrorResponse) => this.fail(error),
    });
  }

  protected async suspend(trucker: Trucker): Promise<void> {
    const ok = await this.confirm.ask({
      title: `Put ${trucker.name} on hold?`,
      // Said plainly, because both halves surprise people. Work already on
      // them is left alone — a run in transit has a customer waiting at the far
      // end, and cancelling it from here would strand a pallet.
      body: 'They stop seeing jobs straight away. Runs they are already on are left alone, and their wallet is untouched.',
      confirmLabel: 'Put on hold',
      danger: true,
    });

    if (!ok) return;

    this.truckersApi.suspend(trucker.id).subscribe({
      next: (updated) => {
        this.refresh();

        if (this.openTrucker()?.id === trucker.id) this.openTrucker.set(updated);
      },
      error: (error: HttpErrorResponse) => this.fail(error),
    });
  }

  /**
   * Put a failure where the person is looking.
   *
   * A refusal raised from inside the drawer belongs in the drawer: the banner
   * at the top of the page is behind a scrim the person cannot see past, so
   * reporting it there is reporting it to nobody.
   */
  private fail(error: HttpErrorResponse): void {
    const message = this.messageFor(error);

    if (this.openTrucker() !== null) {
      this.formError.set(message);

      return;
    }

    this.loadError.set(message);
  }

  /* --------------------------------------------------------- The partner */

  protected open(trucker: Trucker): void {
    this.openTrucker.set(trucker);
    this.wallet.set(null);
    this.walletError.set(null);
    this.formError.set(null);
    // Ticks do not survive a reload: the rows behind them may have just been
    // settled, and a stale id would be refused on the next press.
    this.picked.set(new Set());

    this.settleForm.reset({
      amount: null,
      method: 'bank_transfer',
      cleared: false,
      reference: '',
      note: '',
    });

    this.truckersApi.wallet(trucker.id).subscribe({
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

  protected close(): void {
    this.openTrucker.set(null);
    this.wallet.set(null);

    /**
     * Drop the deep link on the way out.
     *
     * Otherwise `?settle=` outlives the drawer it opened: a refresh, or a
     * bookmark taken while reading, reopens a wallet somebody deliberately
     * closed — and after the payment has gone through, reopens it showing
     * nothing owed. `replaceUrl` keeps Back pointing at Payables rather than
     * at this same page with the parameter still on it.
     */
    if (this.route.snapshot.queryParamMap.has('settle')) {
      void this.router.navigate([], { relativeTo: this.route, queryParams: {}, replaceUrl: true });
    }
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
      ? { label: 'Owes the fleet', tone: 'text-cr-red', hint: 'Take a remittance, or let it net against their next payout.' }
      : wallet.standing === 'owed_to_trucker'
        ? { label: 'Owed to this trucker', tone: 'text-cr-success', hint: 'Pay this out and record the reference.' }
        : { label: 'Settled', tone: 'text-cr-ink-muted', hint: 'Nothing owed either way.' };
  });

  /**
   * Which settle kinds make sense right now.
   *
   * A payout against nothing owed and a remittance from somebody who owes
   * nothing are both refused by the API, with a sentence. Offering them anyway
   * would be offering a button that cannot work — so the form narrows itself,
   * and an adjustment is always available because a correction is the one thing
   * that is always possible.
   */
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
    const trucker = this.openTrucker();

    if (trucker === null || this.busy()) return;

    this.busy.set(true);
    this.formError.set(null);

    this.truckersApi.confirmPayment(trucker.id, entryId).subscribe({
      next: () => {
        this.busy.set(false);
        this.open(trucker);
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
    const trucker = this.openTrucker();

    if (trucker === null) return;

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
      .settle(trucker.id, {
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
          this.picked.set(new Set());
          this.open(trucker);
        },
        error: (error: HttpErrorResponse) => {
          this.busy.set(false);
          this.formError.set(this.messageFor(error));
        },
      });
  }

  /* ---------------------------------------------------------------- Bits */

  /** Centavos as pesos. The API never formats money and never sends a float. */
  protected money(cents: number): string {
    return fmt.money(cents);
  }

  protected rate(bp: number): string {
    // 12, not 12.00 — a whole percentage is the ordinary case. A fleet rate of
    // 7.5% still reads correctly.
    return `${(bp / 100).toFixed(bp % 100 === 0 ? 0 : 1)}%`;
  }

  protected plate(trucker: Trucker): string {
    const unit = trucker.vehicles?.[0];

    return unit ? `${unit.plate} · ${unit.capacity_kg.toLocaleString()} kg` : 'No truck on file';
  }

  protected initials(name: string): string {
    return name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('');
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

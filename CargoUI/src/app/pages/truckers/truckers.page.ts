import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';

import { Trucker } from '../../models/trucker/trucker.model';
import { TruckerService } from '../../services/trucker/trucker.service';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { Modal } from '../../shared/modal';
import { EmptyState, ErrorState, SkeletonRows } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';
import { WalletPanel } from '../../shared/wallet-panel';

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
  imports: [Card, Icon, Modal, StatusPill, SkeletonRows, EmptyState, ErrorState, WalletPanel],
  templateUrl: './truckers.page.html',
})
export class TruckersPage {
  private readonly truckersApi = inject(TruckerService);
  private readonly confirm = inject(Confirm);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  protected readonly fmt = fmt;

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

  /** A refusal from approving or suspending, shown in the drawer. */
  protected readonly formError = signal<string | null>(null);

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
    this.formError.set(null);
  }

  protected close(): void {
    this.openTrucker.set(null);

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

  /* ---------------------------------------------------------------- Bits */

  protected rate(bp: number): string {
    // 12, not 12.00 — a whole percentage is the ordinary case. A fleet rate of
    // 7.5% still reads correctly.
    return `${(bp / 100).toFixed(bp % 100 === 0 ? 0 : 1)}%`;
  }

  /**
   * The roster's one-line summary of a partner's trucks.
   *
   * The first in full and a count of the rest: a partner may run several, and
   * the drawer lists every one, but a table cell only has room for a hint.
   */
  protected plate(trucker: Trucker): string {
    const units = trucker.vehicles ?? [];
    const unit = units[0];

    if (!unit) return 'No truck on file';

    const first = `${unit.plate} · ${unit.capacity_kg.toLocaleString()} kg`;

    return units.length > 1 ? `${first} +${units.length - 1} more` : first;
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

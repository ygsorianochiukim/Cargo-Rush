import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  effect,
  inject,
  input,
  model,
  output,
  signal,
} from '@angular/core';

import { Trip } from '../../models/trip/trip.model';
import { Trucker } from '../../models/trucker/trucker.model';
import { TruckerService } from '../../services/trucker/trucker.service';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { Modal } from '../../shared/modal';
import { EmptyState, SkeletonRows } from '../../shared/states';

/**
 * Hand a run to a partner trucker.
 *
 * The desk's alternative to the open board, and it exists for the case the
 * board cannot serve: nobody nearby picked the load up, or it is far enough out
 * that sending one of the fleet's own units would cost more than it earns.
 *
 * ## This dialog decides money, and says so
 *
 * A run assigned here stays **the fleet's work**: the fleet quoted it, invoices
 * the customer and collects, and the partner is paid their share out of that.
 * A run the same partner takes off the board themselves is **their** work: they
 * bill the customer and the fleet's cut is charged to their wallet instead.
 *
 * Same percentage, opposite directions. That is not a detail to leave implicit
 * in a dialog whose only button commits the firm to paying a contractor, so the
 * split is spelled out in pesos against the trip's own price before anybody
 * presses Assign.
 *
 * ## Who is offered
 *
 * `truckers/available` rather than the whole roster: vetted, online, and
 * holding a truck that is not in the shop. Capacity is checked here as well so
 * a load too heavy for somebody's unit is visibly ruled out rather than
 * refused by the API after the press — the same rule the job board applies, in
 * the place where a person is choosing.
 */
@Component({
  selector: 'app-assign-trucker',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Modal, Icon, EmptyState, SkeletonRows],
  template: `
    <app-modal
      [(open)]="open"
      title="Assign a trucker"
      [subtitle]="trip() ? trip()!.reference + ' · ' + trip()!.origin + ' → ' + trip()!.destination : ''"
      icon="fleet"
      size="md"
      (closed)="reset()">
      @if (error()) {
        <p class="mb-3 rounded-control bg-cr-red-bg px-3 py-2 text-[13px] font-medium text-cr-red">
          {{ error() }}
        </p>
      }

      <!--
        What this costs, before the button rather than after it.

        Assigning commits the firm to paying somebody outside it, and the figure
        is derived from the trip's own price so it cannot disagree with what the
        wallet will post at delivery.
      -->
      @if (trip(); as t) {
        <div class="mb-4 rounded-control bg-cr-tint px-4 py-3">
          <p class="text-[13px] font-semibold">The fleet bills this one</p>
          <p class="mt-1 text-[12px] text-cr-ink-muted">
            You invoice the customer {{ fmt.money(t.price_cents, t.currency) }} and collect it.
            The trucker is credited their share in their wallet when they hand the load over;
            the fleet keeps its commission.
          </p>
          @if (picked(); as p) {
            <p class="cr-num mt-2 text-[13px]">
              <span class="font-semibold text-cr-success">
                {{ fmt.money(t.price_cents - commissionOn(t.price_cents, p.commission_bp)) }}
              </span>
              <span class="text-cr-ink-muted">
                to {{ p.name }} · {{ rate(p.commission_bp) }} fee
                ({{ fmt.money(commissionOn(t.price_cents, p.commission_bp)) }}) to the fleet
              </span>
            </p>
          }
        </div>
      }

      @if (loading()) {
        <app-skeleton-rows [count]="3" />
      } @else if (available().length === 0) {
        <app-empty-state
          icon="fleet"
          title="Nobody available"
          body="A trucker has to be approved, online, and holding a truck that is not in the shop. Approve or check them on the Truckers page." />
      } @else {
        <ul class="flex flex-col gap-2">
          @for (t of available(); track t.id) {
            <li>
              <button
                type="button"
                class="flex w-full items-center gap-3 rounded-control border px-3 py-2.5 text-left transition-colors"
                [class]="
                  picked()?.id === t.id
                    ? 'border-cr-blue bg-cr-tint'
                    : 'border-cr-line hover:bg-cr-tint'
                "
                [disabled]="!canCarry(t)"
                [class.opacity-40]="!canCarry(t)"
                (click)="picked.set(t)">
                <span
                  class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-cr-tint text-[11px] font-semibold text-cr-blue">
                  {{ initials(t.name) }}
                </span>
                <span class="min-w-0 flex-1">
                  <span class="block text-[13px] font-semibold">{{ t.name }}</span>
                  <span class="cr-num block text-[12px] text-cr-ink-muted">
                    {{ unitOf(t) }}
                    @if (!canCarry(t)) {
                      · too small for this load
                    }
                  </span>
                </span>
                @if (picked()?.id === t.id) {
                  <app-icon name="check" [size]="16" class="text-cr-blue" />
                }
              </button>
            </li>
          }
        </ul>
      }

      <ng-container modal-footer>
        <button
          type="button"
          class="h-10 rounded-control px-4 text-[13px] font-semibold text-cr-ink-muted transition-colors hover:bg-cr-tint"
          (click)="open.set(false)">
          Cancel
        </button>
        <button
          type="button"
          class="h-10 rounded-control bg-cr-blue px-4 text-[13px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-50"
          [disabled]="picked() === null || busy()"
          (click)="assign()">
          {{ busy() ? 'Assigning…' : 'Assign' }}
        </button>
      </ng-container>
    </app-modal>
  `,
})
export class AssignTruckerDialog {
  private readonly truckersApi = inject(TruckerService);

  readonly open = model(false);
  readonly trip = input<Trip | null>(null);
  /** The updated run, so the board can swap the row in without a refetch. */
  readonly assigned = output<Trip>();

  protected readonly fmt = fmt;

  protected readonly available = signal<Trucker[]>([]);
  protected readonly picked = signal<Trucker | null>(null);
  protected readonly loading = signal(false);
  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);

  constructor() {
    // Read each time the dialog opens rather than once on construction: who is
    // online changes minute to minute, and a list fetched when the page loaded
    // would be stale by the time somebody opened this.
    effect(() => {
      if (!this.open()) return;

      this.picked.set(null);
      this.load();
    });
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);

    this.truckersApi.available().subscribe({
      next: (list) => {
        this.available.set(list);
        this.loading.set(false);
      },
      error: () => {
        this.available.set([]);
        this.loading.set(false);
        this.error.set('Could not load the available truckers.');
      },
    });
  }

  protected reset(): void {
    this.picked.set(null);
    this.error.set(null);
  }

  protected assign(): void {
    const trip = this.trip();
    const trucker = this.picked();

    if (trip === null || trucker === null) return;

    this.busy.set(true);
    this.error.set(null);

    this.truckersApi.assign(trip.id, trucker.id).subscribe({
      next: (updated) => {
        this.busy.set(false);
        this.assigned.emit(updated);
        this.open.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.error.set(
          error.error?.message ?? 'Could not assign that run. Check the connection and try again.',
        );
      },
    });
  }

  /** The unit a run would go under — the first that is not in the shop. */
  protected unitOf(trucker: Trucker): string {
    const unit = (trucker.vehicles ?? []).find((v) => v.status === 'available');

    return unit ? `${unit.plate} · ${unit.capacity_kg.toLocaleString()} kg` : 'No truck available';
  }

  /**
   * Could this partner's truck take the load?
   *
   * The same rule the job board and the API apply, checked here so a load that
   * is too heavy is visibly greyed out in the list rather than refused after
   * the press.
   */
  protected canCarry(trucker: Trucker): boolean {
    const trip = this.trip();
    const unit = (trucker.vehicles ?? []).find((v) => v.status === 'available');

    if (trip === null || unit === undefined) return false;

    return unit.capacity_kg >= trip.weight_kg;
  }

  protected commissionOn(cents: number, bp: number): number {
    // Integer arithmetic, truncating, exactly as `WalletService` does it — so
    // the figure quoted here is the figure that will be posted.
    return Math.floor((cents * bp) / 10_000);
  }

  protected rate(bp: number): string {
    return `${(bp / 100).toFixed(bp % 100 === 0 ? 0 : 1)}%`;
  }

  protected initials(name: string): string {
    return name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('');
  }
}

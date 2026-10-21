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
import { Trucker, TruckerVehicle } from '../../models/trucker/trucker.model';
import { TruckerService } from '../../services/trucker/trucker.service';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { Modal } from '../../shared/modal';
import { EmptyState, SkeletonRows } from '../../shared/states';

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

      @if (trip(); as t) {
        @if (t.price_cents === null) {
          <p class="mb-4 rounded-control bg-cr-warning-bg px-4 py-3 text-[13px] text-cr-warning" role="alert">
            This run has no price yet — enter one on the trip.
            @if (t.pricing_note) {
              <span class="mt-1 block text-[12px]">{{ t.pricing_note }}</span>
            }
          </p>
        } @else {
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
                to {{ p.business_name ?? p.name }} · {{ rate(p.commission_bp) }} fee
                ({{ fmt.money(commissionOn(t.price_cents, p.commission_bp)) }}) to the fleet
              </span>
            </p>
          }
        </div>
        }
      }

      @if (loading()) {
        <app-skeleton-rows [count]="3" />
      } @else if (available().length === 0) {
        <app-empty-state
          icon="fleet"
          title="Nobody available"
          body="A trucker has to be approved, online, and holding a truck that is not in the shop. Approve or check them on the Truckers page." />
      } @else {
        <p class="mb-2 text-[12px] text-cr-ink-muted">
          The trucker chooses which of their trucks takes it. Greyed out: none of their trucks
          can carry {{ trip()?.weight_kg?.toLocaleString() }} kg.
        </p>
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
                  {{ initials(t.business_name ?? t.name) }}
                </span>
                <span class="min-w-0 flex-1">
                  <span class="block text-[13px] font-semibold">{{ t.business_name ?? t.name }}</span>
                  @if (t.business_name) {
                    <span class="block text-[12px] text-cr-ink-muted">{{ t.name }}</span>
                  }
                  <span class="cr-num block text-[12px] text-cr-ink-muted">{{ fitOf(t) }}</span>
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
  readonly assigned = output<Trip>();

  protected readonly fmt = fmt;

  protected readonly available = signal<Trucker[]>([]);
  protected readonly picked = signal<Trucker | null>(null);
  protected readonly loading = signal(false);
  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);

  constructor() {
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

  private fitting(trucker: Trucker): TruckerVehicle[] {
    const trip = this.trip();

    if (trip === null) return [];

    return (trucker.vehicles ?? []).filter(
      (v) =>
        v.status === 'available' &&
        v.capacity_kg >= trip.weight_kg &&
        (!trip.truck_category_id || !v.truck_category_id || v.truck_category_id === trip.truck_category_id) &&
        (!trip.truck_size_id || !v.truck_size_id || v.truck_size_id === trip.truck_size_id),
    );
  }

  protected canCarry(trucker: Trucker): boolean {
    return this.fitting(trucker).length > 0;
  }

  protected fitOf(trucker: Trucker): string {
    const onRoad = (trucker.vehicles ?? []).filter((v) => v.status === 'available');
    const fits = this.fitting(trucker).length;
    const kg = (this.trip()?.weight_kg ?? 0).toLocaleString();

    if (onRoad.length === 0) return 'No truck on the road';

    if (fits === 0) {
      const biggest = Math.max(...onRoad.map((v) => v.capacity_kg)).toLocaleString();

      return `No truck can carry ${kg} kg · biggest is ${biggest} kg`;
    }

    return `${fits} of ${onRoad.length} ${onRoad.length === 1 ? 'truck' : 'trucks'} can carry ${kg} kg`;
  }

  protected commissionOn(cents: number, bp: number): number {
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

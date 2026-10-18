import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

import { StatusValue } from '../../models/shared/status.model';
import { Trip } from '../../models/trip/trip.model';
import { IdentityService } from '../../services/identity/identity.service';
import { TripService } from '../../services/trip/trip.service';
import { statusLabel } from '../../shared/status';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { Column, DataTable } from '../../shared/data-table';
import { FilterBar, FilterOption } from '../../shared/filter-bar';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { TripDialog } from '../../shared/trip-dialog';
import { AssignTruckerDialog } from './assign-trucker.dialog';
import { ConfirmRequestDialog } from './confirm-request.dialog';

function tripNumber(reference: string | null | undefined): number {
  const tail = Number((reference ?? '').split('-').pop());

  return Number.isFinite(tail) ? tail : -1;
}

@Component({
  selector: 'app-trips',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [AssignTruckerDialog, Card, ConfirmRequestDialog, DataTable, FilterBar, Icon],
  template: `
    @if (requests().length > 0) {
      <app-card
        heading="Delivery requests"
        icon="shipments"
        [hint]="requests().length + ' waiting'"
        [padded]="false"
        class="mb-4 block">
        <ul class="flex flex-col">
          @for (request of requests(); track request.id) {
            <li
              class="flex flex-wrap items-center gap-3 border-b border-cr-line/70 px-4 py-3 last:border-0">
              <span class="min-w-0 flex-1">
                <span class="flex items-center gap-1.5 text-[13px] font-semibold">
                  {{ request.reference }}
                  <app-icon name="chevron-right" [size]="12" class="text-cr-ink-muted" />
                  <span class="truncate font-normal">
                    {{ request.origin }} → {{ request.destination }}
                  </span>
                </span>
                <span class="mt-0.5 block truncate text-[12px] text-cr-ink-muted">
                  {{ request.customer ?? 'No customer' }} · {{ request.cargo }} ·
                  {{ fmt.kg(request.weight_kg) }} · asked for
                  {{ fmt.dateTime(request.scheduled_at) }}
                </span>
              </span>

              @if (request.price_cents === null) {
                <span class="flex max-w-[260px] flex-col items-end gap-0.5 text-right">
                  <span
                    class="inline-flex items-center gap-1.5 rounded-full bg-cr-warning-bg px-2 py-[3px] text-[10px] font-semibold tracking-[0.06em] text-cr-warning uppercase">
                    <span class="h-1.5 w-1.5 rounded-full bg-cr-warning"></span>
                    Needs a price
                  </span>
                  @if (request.pricing_note) {
                    <span class="text-[12px] text-cr-ink-muted">{{ request.pricing_note }}</span>
                  }
                </span>
              } @else {
                <span class="cr-num text-[13px] font-semibold">
                  {{ fmt.money(request.price_cents, request.currency) }}
                </span>
              }

              <button
                type="button"
                class="h-9 rounded-control bg-cr-blue px-3 text-[13px] font-semibold text-cr-surface
                       transition-colors hover:bg-cr-blue-hover focus:outline-none disabled:cursor-not-allowed disabled:opacity-50
                       focus-visible:ring-2 focus-visible:ring-cr-blue focus-visible:ring-offset-2"
                [attr.aria-label]="
                  'Confirm ' + request.reference + ', ' + request.origin + ' to ' + request.destination
                "
                [disabled]="request.price_cents === null"
                [attr.title]="request.price_cents === null ? unpricedHint : null"
                (click)="confirm(request)">
                Confirm
              </button>

              <button
                type="button"
                class="h-9 rounded-control border border-cr-line px-3 text-[13px] font-semibold
                       text-cr-ink transition-colors hover:bg-cr-tint focus:outline-none disabled:cursor-not-allowed disabled:opacity-50
                       focus-visible:ring-2 focus-visible:ring-cr-blue focus-visible:ring-offset-2"
                [attr.aria-label]="'Give ' + request.reference + ' to a trucker'"
                [disabled]="request.price_cents === null"
                [attr.title]="request.price_cents === null ? unpricedHint : null"
                (click)="assignTrucker(request)">
                Give to a trucker
              </button>
            </li>
          }
        </ul>
      </app-card>
    }

    <app-filter-bar
      [options]="filters()"
      [selected]="filter()"
      (select)="filter.set($any($event))" />

    @if (hasPartnerWork()) {
      <div class="mt-2">
        <app-filter-bar
          label="Filter by who is hauling"
          [options]="haulerFilters()"
          [selected]="haulerFilter()"
          (select)="haulerFilter.set($any($event))" />
      </div>
    }

    <app-card [padded]="false" class="mt-4 block">
      <app-data-table
        [columns]="columns"
        [rows]="rows()"
        [minWidth]="1100"
        rowAction="Edit trip"
        [deletable]="true"
        [rowLabel]="tripLabel"
        emptyIcon="route"
        emptyTitle="No trips match this filter"
        emptyBody="Clear the filter to see every trip on the board."
        (open)="edit($any($event))"
        (remove)="remove($any($event))" />
    </app-card>

    <app-confirm-request
      [(open)]="confirming"
      [trip]="pickedRequest()"
      (confirmed)="onSaved($any($event))" />

    <app-assign-trucker
      [(open)]="assigning"
      [trip]="pickedRequest()"
      (assigned)="onSaved($any($event))" />
  `,
})
export class TripsPage {
  private readonly trips = inject(TripService);
  private readonly confirmDelete = inject(Confirm);
  private readonly tripDialog = inject(TripDialog);
  private readonly identity = inject(IdentityService);

  protected readonly canSeeMoney =
    this.identity.can('finance.view') || this.identity.can('expenses.view');

  private readonly loaded = toSignal(this.trips.list().pipe(map((r) => r.data)), {
    initialValue: null,
  });

  private readonly overrides = signal<Trip[] | null>(null);
  private readonly all = computed(() => this.overrides() ?? this.loaded());

  protected readonly filter = signal<StatusValue | 'all'>('all');

  protected readonly haulerFilter = signal<'all' | 'company' | 'trucker'>('all');

  protected readonly pickedRequest = signal<Trip | null>(null);
  protected readonly confirming = signal(false);
  protected readonly assigning = signal(false);

  protected readonly requests = computed(() =>
    (this.all() ?? [])
      .filter((trip) => trip.status === 'pending')
      .sort((a, b) => tripNumber(b.reference) - tripNumber(a.reference)),
  );

  protected readonly filters = computed<FilterOption[]>(() => {
    const rows = this.all() ?? [];
    const seen = [...new Set(rows.map((r) => r.status))];
    return [
      { value: 'all', label: 'All', count: rows.length },
      ...seen.map((s) => ({
        value: s,
        label: statusLabel(s),
        count: rows.filter((r) => r.status === s).length,
      })),
    ];
  });

  protected readonly hasPartnerWork = computed(() =>
    (this.all() ?? []).some((trip) => trip.hauled_by === 'trucker'),
  );

  protected readonly haulerFilters = computed<FilterOption[]>(() => {
    const rows = this.all() ?? [];

    return [
      { value: 'all', label: 'Anyone', count: rows.length },
      {
        value: 'company',
        label: 'Company',
        count: rows.filter((r) => r.hauled_by === 'company').length,
      },
      {
        value: 'trucker',
        label: 'Truckers',
        count: rows.filter((r) => r.hauled_by === 'trucker').length,
      },
    ];
  });

  protected readonly rows = computed(() => {
    const rows = this.all();
    if (rows === null) return null;

    const status = this.filter();
    const hauler = this.haulerFilter();

    return rows.filter(
      (r) =>
        (status === 'all' || r.status === status) &&
        (hauler === 'all' || r.hauled_by === hauler),
    );
  });

  protected edit(trip: Trip): void {
    this.tripDialog.edit(trip);
  }

  protected confirm(trip: Trip): void {
    this.pickedRequest.set(trip);
    this.confirming.set(true);
  }

  protected assignTrucker(trip: Trip): void {
    this.pickedRequest.set(trip);
    this.assigning.set(true);
  }

  protected onSaved(trip: Trip): void {
    const current = this.all() ?? [];
    const exists = current.some((t) => t.id === trip.id);
    this.overrides.set(exists ? current.map((t) => (t.id === trip.id ? trip : t)) : [trip, ...current]);
  }

  protected async remove(trip: Trip): Promise<void> {
    if (trip.billed_at && (trip.invoice?.paid_cents ?? 0) > 0) {
      await this.confirmDelete.ask({
        title: `${trip.reference} cannot be deleted`,
        body: `${trip.invoice?.number} already has payments against it. Remove the payment first to delete this trip.`,
        confirmLabel: 'OK',
        icon: 'billing',
      });
      return;
    }

    const ok = await this.confirmDelete.ask({
      title: `Delete ${trip.reference}?`,
      body: trip.billed_at
        ? `It has been delivered but not paid. Its income and any trucker settlement come off the books, and ${trip.invoice?.number ?? 'its invoice'} is cancelled.`
        : `This removes the trip and its dispatch record. Delivery logs already filed against it are kept.`,
      confirmLabel: 'Delete trip',
      danger: true,
    });
    if (!ok) return;

    this.trips.remove(trip.id).subscribe({
      next: () => this.overrides.set((this.all() ?? []).filter((t) => t.id !== trip.id)),
      error: (error: HttpErrorResponse) =>
        void this.confirmDelete.ask({
          title: `${trip.reference} cannot be deleted`,
          body: error.error?.message ?? `The server refused that request (${error.status}).`,
          confirmLabel: 'OK',
          icon: 'billing',
        }),
    });
  }

  constructor() {
    this.tripDialog.saved.pipe(takeUntilDestroyed()).subscribe((trip) => this.onSaved(trip));
  }

  protected readonly fmt = fmt;

  protected readonly unpricedHint =
    'This run has no price yet — enter one on the trip.';

  protected readonly tripLabel = (t: Trip) => t.reference;

  protected readonly columns: Column<Trip>[] = [
    { label: 'Reference', kind: 'strong', value: (t) => t.reference },
    { label: 'Route', value: (t) => `${t.origin} → ${t.destination}`, sub: (t) => t.cargo },
    {
      label: 'Driver',
      value: (t) => t.driver_name ?? t.trucker_driver_name ?? t.trucker_name,
      sub: (t) =>
        t.hauled_by === 'trucker'
          ? t.trucker_driver_name
            ? `Driver for ${t.trucker_business_name ?? t.trucker_name ?? 'a trucker'}`
            : (t.trucker_phone ?? 'Partner')
          : t.helpers.length > 0
            ? t.helpers.map((h) => h.name).join(', ')
            : 'No helper',
    },
    { label: 'Vehicle', kind: 'muted', value: (t) => t.vehicle_plate ?? t.trucker_plate },
    {
      label: 'Gross',
      kind: 'num',
      value: (t) =>
        t.price_cents === null || t.status === 'cancelled' ? null : fmt.money(t.price_cents, t.currency),
    },
    ...(this.canSeeMoney
      ? ([
          {
            label: 'Expenses',
            kind: 'num',
            value: (t) => (t.costs_cents === undefined ? null : fmt.money(t.costs_cents, t.currency)),
          },
          {
            label: 'Net',
            kind: 'strong',
            value: (t) => (t.net_cents === undefined ? null : fmt.money(t.net_cents, t.currency)),
          },
        ] satisfies Column<Trip>[])
      : []),
    { label: 'Scheduled', kind: 'num', value: (t) => fmt.dateTime(t.scheduled_at) },
    { label: 'Status', kind: 'status', status: (t) => t.status },
  ];
}

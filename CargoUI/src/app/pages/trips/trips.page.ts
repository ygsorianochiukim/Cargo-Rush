import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

import { StatusValue } from '../../models/shared/status.model';
import { Trip } from '../../models/trip/trip.model';
import { TripService } from '../../services/trip/trip.service';
import { statusLabel } from '../../shared/status';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { Column, DataTable } from '../../shared/data-table';
import { FilterBar, FilterOption } from '../../shared/filter-bar';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { TripDialog } from '../../shared/trip-dialog';
import { ConfirmRequestDialog } from './confirm-request.dialog';

/** Trip Management — DESIGN.md section 5.1. */
@Component({
  selector: 'app-trips',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, ConfirmRequestDialog, DataTable, FilterBar, Icon],
  template: `
    <!-- Delivery requests.
         Its own panel above the board rather than a filter on it, because a
         request is the one row on this page that is waiting on somebody: it
         has no driver, no unit and no agreed time until the desk supplies
         them. Buried in a table of two hundred trips it is a row nobody is
         looking for. -->
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

              <!-- The quote the customer was already shown. Printed here so the
                   desk confirms against the same figure the customer has. -->
              <span class="cr-num text-[13px] font-semibold">
                {{ fmt.money(request.price_cents, request.currency) }}
              </span>

              <button
                type="button"
                class="h-9 rounded-control bg-cr-blue px-3 text-[13px] font-semibold text-cr-surface
                       transition-colors hover:bg-cr-blue-hover focus:outline-none
                       focus-visible:ring-2 focus-visible:ring-cr-blue focus-visible:ring-offset-2"
                [attr.aria-label]="
                  'Confirm ' + request.reference + ', ' + request.origin + ' to ' + request.destination
                "
                (click)="confirm(request)">
                Confirm
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

    <app-card [padded]="false" class="mt-4 block">
      <app-data-table
        [columns]="columns"
        [rows]="rows()"
        [minWidth]="1240"
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
  `,
})
export class TripsPage {
  private readonly trips = inject(TripService);
  private readonly confirmDelete = inject(Confirm);
  private readonly tripDialog = inject(TripDialog);

  private readonly loaded = toSignal(this.trips.list().pipe(map((r) => r.data)), {
    initialValue: null,
  });

  /** Local overlay so create/edit/delete show immediately, before a refetch. */
  private readonly overrides = signal<Trip[] | null>(null);
  private readonly all = computed(() => this.overrides() ?? this.loaded());

  protected readonly filter = signal<StatusValue | 'all'>('all');

  /** The request the confirm dialog is currently pointed at. */
  protected readonly pickedRequest = signal<Trip | null>(null);
  protected readonly confirming = signal(false);

  /**
   * Work waiting on a decision from this desk.
   *
   * `pending` is the whole definition: nobody has looked at it. Oldest first,
   * because a request that has been sitting longest is the one most likely to
   * be a customer wondering whether anybody read it.
   */
  protected readonly requests = computed(() =>
    (this.all() ?? [])
      .filter((trip) => trip.status === 'pending')
      .sort((a, b) => a.scheduled_at.localeCompare(b.scheduled_at)),
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

  protected readonly rows = computed(() => {
    const rows = this.all();
    if (rows === null) return null;
    const f = this.filter();
    return f === 'all' ? rows : rows.filter((r) => r.status === f);
  });

  protected edit(trip: Trip): void {
    this.tripDialog.edit(trip);
  }

  protected confirm(trip: Trip): void {
    this.pickedRequest.set(trip);
    this.confirming.set(true);
  }

  protected onSaved(trip: Trip): void {
    const current = this.all() ?? [];
    const exists = current.some((t) => t.id === trip.id);
    this.overrides.set(exists ? current.map((t) => (t.id === trip.id ? trip : t)) : [trip, ...current]);
  }

  protected async remove(trip: Trip): Promise<void> {
    const ok = await this.confirmDelete.ask({
      title: `Delete ${trip.reference}?`,
      body: `This removes the trip and its dispatch record. Delivery logs already filed against it are kept.`,
      confirmLabel: 'Delete trip',
      danger: true,
    });
    if (!ok) return;

    this.trips.remove(trip.id).subscribe(() => {
      this.overrides.set((this.all() ?? []).filter((t) => t.id !== trip.id));
    });
  }

  constructor() {
    // One dialog instance lives in the shell; this page just reacts to it.
    this.tripDialog.saved.pipe(takeUntilDestroyed()).subscribe((trip) => this.onSaved(trip));
  }

  protected readonly fmt = fmt;

  protected readonly tripLabel = (t: Trip) => t.reference;

  protected readonly columns: Column<Trip>[] = [
    { label: 'Reference', kind: 'strong', value: (t) => t.reference },
    { label: 'Route', value: (t) => `${t.origin} → ${t.destination}`, sub: (t) => t.cargo },
    { label: 'Driver', value: (t) => t.driver_name, sub: (t) => t.helper_name ?? 'No helper' },
    { label: 'Vehicle', kind: 'muted', value: (t) => t.vehicle_plate },
    { label: 'Weight', kind: 'num', value: (t) => fmt.kg(t.weight_kg) },
    // What the haul is charged, quoted from the tariff at booking. `Billed`
    // under it says whether the delivery has already put it on the books, so
    // the two are read together rather than as one ambiguous figure.
    {
      label: 'Price',
      kind: 'num',
      value: (t) => fmt.money(t.price_cents, t.currency),
      sub: (t) => (t.billed_at ? 'Billed' : null),
    },
    { label: 'Scheduled', kind: 'num', value: (t) => fmt.dateTime(t.scheduled_at) },
    { label: 'ETA', kind: 'num', value: (t) => fmt.dateTime(t.eta) },
    { label: 'Status', kind: 'status', status: (t) => t.status },
  ];
}

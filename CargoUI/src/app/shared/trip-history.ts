import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';

import { TripHistoryChange, TripHistoryEntry } from '../models/trip/trip.model';
import { TripService } from '../services/trip/trip.service';
import { fmt } from './format';

const LABELS: Record<string, string> = {
  price_cents: 'Price',
  amount_cents: 'Amount',
  net_amount_cents: 'Net amount',
  vat_cents: 'VAT',
  withholding_cents: 'Withholding',
  customer_id: 'Customer',
  vehicle_id: 'Vehicle',
  driver_id: 'Driver',
  scheduled_at: 'Scheduled',
  billed_at: 'Billed on',
  delivered_at: 'Delivered on',
  paid_on: 'Paid on',
  paid_at: 'Paid on',
  issued_at: 'Issued',
  due_at: 'Due',
  pricing_zone_id: 'Zone',
  weight_kg: 'Weight (kg)',
  distance_total_m: 'Distance (m)',
};

const ACTIONS: Record<TripHistoryEntry['action'], string> = {
  created: 'created',
  updated: 'edited',
  deleted: 'deleted',
  restored: 'restored',
};

@Component({
  selector: 'app-trip-history',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="rounded-control border border-cr-line px-3 py-3">
      <button
        type="button"
        class="flex w-full items-center justify-between text-left text-[13px] font-semibold"
        [attr.aria-expanded]="shown()"
        (click)="toggle()"
      >
        Change history
        <span class="text-[12px] font-normal text-cr-blue">{{ shown() ? 'Hide' : 'Show' }}</span>
      </button>

      @if (shown()) {
        @if (loading()) {
          <p class="mt-2 text-[12px] text-cr-ink-muted">Loading…</p>
        } @else if (failure(); as message) {
          <p class="mt-2 text-[12px] text-cr-red" role="alert">{{ message }}</p>
        } @else if (entries().length === 0) {
          <p class="mt-2 text-[12px] text-cr-ink-muted">No changes recorded yet.</p>
        } @else {
          <ol class="mt-3 flex max-h-[320px] flex-col gap-3 overflow-y-auto pr-1">
            @for (e of entries(); track e.id) {
              <li class="border-l-2 border-cr-line pl-3 text-[12px]">
                <p>
                  <span class="font-semibold">{{ e.subject }}</span>
                  {{ action(e) }} by
                  <span class="font-semibold">{{ e.user ?? 'the system' }}</span>
                </p>
                <p class="cr-num text-cr-ink-muted">
                  {{ when(e.at) }}{{ e.ip_address ? ' · IP ' + e.ip_address : '' }}
                </p>
                @if (e.action === 'updated' && e.changes.length > 0) {
                  <ul class="mt-1 flex flex-col gap-0.5">
                    @for (c of e.changes; track c.field) {
                      <li class="break-words">
                        <span class="text-cr-ink-muted">{{ label(c.field) }}:</span>
                        <span class="cr-num">{{ value(c, c.from) }}</span>
                        →
                        <span class="cr-num font-medium">{{ value(c, c.to) }}</span>
                      </li>
                    }
                  </ul>
                }
              </li>
            }
          </ol>
        }
      }
    </div>
  `,
})
export class TripHistory {
  private readonly trips = inject(TripService);

  readonly tripId = input.required<string>();

  protected readonly shown = signal(false);
  protected readonly loading = signal(false);
  protected readonly failure = signal<string | null>(null);
  protected readonly entries = signal<TripHistoryEntry[]>([]);

  protected toggle(): void {
    this.shown.update((s) => !s);

    if (this.shown()) this.load();
  }

  private load(): void {
    this.loading.set(true);
    this.failure.set(null);

    this.trips.history(this.tripId()).subscribe({
      next: (entries) => {
        this.entries.set(entries);
        this.loading.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.failure.set(error.error?.message ?? `Could not load the history (${error.status}).`);
        this.loading.set(false);
      },
    });
  }

  protected action(e: TripHistoryEntry): string {
    return ACTIONS[e.action] ?? e.action;
  }

  protected when(at: string): string {
    return fmt.dateTime(at);
  }

  protected label(field: string): string {
    if (LABELS[field]) return LABELS[field];

    const words = field.replace(/_(id|cents|bp)$/, '').replace(/_/g, ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
  }

  protected value(c: TripHistoryChange, v: unknown): string {
    if (v === null || v === undefined || v === '') return '—';
    if (c.field.endsWith('_cents') && typeof v === 'number') return fmt.money(v);
    if (typeof v === 'object') return JSON.stringify(v);

    return String(v);
  }
}

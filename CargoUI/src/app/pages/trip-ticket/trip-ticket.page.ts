import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { TripTicket } from '../../models/trip/trip-ticket.model';
import { TripService } from '../../services/trip/trip.service';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { ErrorState, SkeletonRows } from '../../shared/states';

@Component({
  selector: 'app-trip-ticket',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, RouterLink, ErrorState, SkeletonRows],
  templateUrl: './trip-ticket.page.html',
})
export class TripTicketPage {
  private readonly trips = inject(TripService);
  private readonly id = inject(ActivatedRoute).snapshot.paramMap.get('trip') ?? '';

  protected readonly ticket = signal<TripTicket | null>(null);
  protected readonly failure = signal<string | null>(null);

  protected readonly logo = computed(() => this.ticket()?.issuer.logo_url ?? 'brand/logo-mark.png');

  protected readonly route = computed(() => {
    const t = this.ticket()?.trip;

    return t ? [t.origin, t.destination].filter(Boolean).join(' → ') : '';
  });

  protected readonly weight = computed(() => {
    const t = this.ticket()?.trip;

    if (!t) return '';

    return [
      t.weight_kg ? `${t.weight_kg.toLocaleString()} kg` : null,
      t.pieces ? `${t.pieces.toLocaleString()} pcs` : null,
    ]
      .filter(Boolean)
      .join(' · ');
  });

  protected readonly legs = computed(() => {
    const t = this.ticket()?.trip;

    return [
      { label: 'Origin / Departure', place: t?.origin ?? '' },
      { label: 'First Drop-off', place: t?.destination ?? '' },
      { label: 'Last Drop-off', place: '' },
      { label: 'Final Return Hub', place: '' },
    ];
  });

  protected readonly expenses = [
    'Fuel Refill 1',
    'Fuel Refill 2',
    'Ticket Fees Total',
    'Incidental (Parking/Scales)',
  ];

  constructor() {
    this.trips.ticket(this.id).subscribe({
      next: (ticket) => this.ticket.set(ticket),
      error: () =>
        this.failure.set('Could not load this trip ticket. Check the connection and try again.'),
    });
  }

  protected date(value: string | null): string {
    if (!value) return '';

    return new Date(`${value}T00:00:00`).toLocaleDateString('en-US', {
      month: 'long',
      day: 'numeric',
      year: 'numeric',
    });
  }

  protected longDate(value: string | null): string {
    if (!value) return '';

    return new Date(`${value}T00:00:00`).toLocaleDateString('en-US', {
      weekday: 'long',
      month: 'long',
      day: 'numeric',
      year: 'numeric',
    });
  }

  protected amount(cents: number | null): string {
    return cents ? fmt.pesos(cents).replace('₱', '') : '';
  }

  protected stamp(value: string): string {
    return new Date(value).toLocaleString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    });
  }

  protected print(): void {
    window.print();
  }
}

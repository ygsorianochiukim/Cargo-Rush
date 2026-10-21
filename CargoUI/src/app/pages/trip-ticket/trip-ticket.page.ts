import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { TripTicket } from '../../models/trip/trip-ticket.model';
import { TripService } from '../../services/trip/trip.service';
import { fmt } from '../../shared/format';
import { FileDelivery, deliverFile } from '../../shared/save-file';
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
  protected readonly saving = signal(false);
  protected readonly pdfError = signal<string | null>(null);

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
      { label: 'Origin / Departure', place: t?.origin ?? '', at: t?.start_off_at ?? null },
      { label: 'First Drop-off', place: t?.destination ?? '', at: null },
      { label: 'Last Drop-off', place: '', at: null },
      { label: 'Final Return Hub', place: '', at: null },
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
    this.savePdf('print');
  }

  protected savePdf(mode: FileDelivery = 'save'): void {
    const reference = this.ticket()?.trip.reference ?? this.id;

    this.saving.set(true);
    this.pdfError.set(null);

    this.trips.ticketPdf(this.id).subscribe({
      next: (blob) => {
        deliverFile(mode, blob, `trip-ticket-${reference}.pdf`);
        this.saving.set(false);
      },
      error: () => {
        this.saving.set(false);
        this.pdfError.set('Could not prepare the PDF. Check the connection and try again.');
      },
    });
  }
}

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';

import { GeoPoint } from '../../models/geo/geo.model';
import { CompanyService } from '../../services/identity/company.service';
import { Card } from '../../shared/card';
import { Icon } from '../../shared/icon';
import { MapPicker } from '../../shared/map-picker';

/**
 * Where this company's yard is — the pin customers find it by.
 *
 * The registration form asks for it, which covers every company that signs up
 * from now on. This is for the ones that did not: without a pin a haulier does
 * not appear on the carrier list shippers choose from in the app, and there
 * would be no way at all to get on it.
 *
 * It sits beside the company's mark on Access Control for the same reason that
 * card does — this is already the administrator's screen, and `company.manage`
 * is already the permission gating it.
 *
 * **The pin is not the address.** The address is what a letter needs and is
 * edited as text; this is what a distance is measured to and a marker drawn at.
 * Saving moves both, because somebody correcting where they are usually means
 * both — and the address box is filled from the lookup only when it is empty, so
 * a hand-written "Km 9, Sasa" is never replaced by a geocoder's guess.
 */
@Component({
  selector: 'app-yard-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Icon, MapPicker],
  template: `
    <app-card heading="Your yard" icon="map-pin" hint="How customers in the app find you">
      @if (loading()) {
        <p class="text-[13px] text-cr-ink-muted">Reading the company…</p>
      } @else {
        <div class="flex flex-col gap-3">
          <div
            class="flex items-start gap-2 rounded-control px-3 py-2"
            [class]="pin() ? 'bg-cr-tint' : 'bg-cr-line/40'"
          >
            <app-icon
              name="map-pin"
              [size]="16"
              class="mt-0.5 flex-none"
              [class]="pin() ? 'text-cr-blue' : 'text-cr-ink-muted'"
            />
            <p class="text-[13px]">
              @if (pin()) {
                <span class="font-semibold">Customers near you can see you.</span>
                They pick a carrier from the ones closest to their load, and this is the point that
                distance is measured from.
              } @else {
                <span class="font-semibold">You are not on the carrier list yet.</span>
                Customers in the app choose a haulier from the ones nearest their load. Drop a pin
                on your yard and you appear on that list.
              }
            </p>
          </div>

          <label class="flex flex-col gap-1">
            <span class="cr-meta">ADDRESS</span>
            <input
              type="text"
              [value]="address()"
              (input)="address.set($any($event.target).value)"
              placeholder="Km 9, Sasa, Davao City"
              class="h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none"
            />
          </label>

          <app-map-picker [value]="pin()" (changed)="pinned($event)" />

          @if (failure(); as message) {
            <p role="alert" class="text-[12px] font-medium text-cr-red">{{ message }}</p>
          }

          @if (saved()) {
            <p class="text-[12px] font-medium text-cr-success" aria-live="polite">
              Saved. The carrier list will show you here.
            </p>
          }

          <div class="flex flex-wrap items-center gap-2">
            <button
              type="button"
              class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
              [disabled]="busy()"
              (click)="save()"
            >
              {{ busy() ? 'Saving…' : 'Save location' }}
            </button>

            @if (pin()) {
              <!--
                Taking the pin down is the only way off the list, and it is
                deliberately an act rather than a switch to find.
              -->
              <button
                type="button"
                class="h-10 rounded-control border border-cr-line px-4 text-[14px] font-semibold text-cr-ink-muted transition-colors hover:bg-cr-tint disabled:opacity-60"
                [disabled]="busy()"
                (click)="clear()"
              >
                Take me off the list
              </button>
            }
          </div>
        </div>
      }
    </app-card>
  `,
})
export class YardCard {
  private readonly companyApi = inject(CompanyService);

  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly saved = signal(false);
  protected readonly failure = signal<string | null>(null);

  /**
   * The pin, as one value.
   *
   * Two coordinates that only mean anything together — the same shape the trip
   * form holds a location in, and for the same reason: a latitude that outlived
   * a change of place is the bug this cannot have.
   */
  protected readonly pin = signal<GeoPoint | null>(null);

  protected readonly address = signal('');

  constructor() {
    // Fetched rather than read off `me`: the coordinates are not on the account
    // resource, because a driver's handset has no use for them.
    this.companyApi.show().subscribe({
      next: (company) => {
        this.address.set(company.address ?? '');

        if (company.latitude !== null && company.longitude !== null) {
          this.pin.set({
            place: company.address ?? '',
            lat: company.latitude,
            lng: company.longitude,
          });
        }

        this.loading.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.loading.set(false);
        this.failure.set(this.messageFor(error));
      },
    });
  }

  /**
   * The map reporting a pin, or the pin being cleared.
   *
   * The looked-up name fills the address box only when it is empty. Somebody
   * who wrote "Km 9, Sasa" knows their own yard better than a geocoder does.
   */
  protected pinned(point: GeoPoint | null): void {
    this.pin.set(point);
    this.saved.set(false);

    if (point?.place && !this.address().trim()) {
      this.address.set(point.place);
    }
  }

  protected save(): void {
    const pin = this.pin();

    this.write({
      address: this.address().trim() || null,
      // A pair or neither. The API refuses half a coordinate, and there is no
      // half a place to send it.
      latitude: pin?.lat ?? null,
      longitude: pin?.lng ?? null,
    });
  }

  /** Off the list: the pin goes, the address stays. */
  protected clear(): void {
    this.pin.set(null);
    this.write({ latitude: null, longitude: null });
  }

  private write(attributes: Record<string, unknown>): void {
    this.busy.set(true);
    this.failure.set(null);
    this.saved.set(false);

    this.companyApi.updateProfile(attributes).subscribe({
      next: (company) => {
        this.busy.set(false);
        this.saved.set(true);
        this.address.set(company.address ?? '');

        this.pin.set(
          company.latitude === null || company.longitude === null
            ? null
            : { place: company.address ?? '', lat: company.latitude, lng: company.longitude },
        );
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.failure.set(this.messageFor(error));
      },
    });
  }

  /**
   * The server's own words for a 422 — it owns the rules about what a
   * coordinate may be, and restating them here would be two rules to keep in
   * step.
   */
  private messageFor(error: HttpErrorResponse): string {
    if (error.status === 422) {
      const errors = error.error?.errors as Record<string, string[]> | undefined;

      return (
        (errors && Object.values(errors)[0]?.[0]) ??
        error.error?.message ??
        'That location was not accepted.'
      );
    }

    if (error.status === 0) return 'Cannot reach the server.';
    if (error.status === 403) return 'This account cannot change the company location.';

    return error.error?.message ?? `The server refused that request (${error.status}).`;
  }
}

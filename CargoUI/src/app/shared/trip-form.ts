import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { takeUntilDestroyed, toObservable, toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { catchError, debounceTime, map, of, switchMap } from 'rxjs';

import { Driver } from '../models/driver/driver.model';
import { QuoteBreakdown } from '../models/pricing/pricing.model';
import { IdentityService } from '../services/identity/identity.service';
import { PricingService } from '../services/pricing/pricing.service';
import { fmt } from './format';
import { StatusValue } from '../models/shared/status.model';
import { TripPayload } from '../models/trip/trip.model';
import { Vehicle } from '../models/vehicle/vehicle.model';
import { DriverService } from '../services/driver/driver.service';
import { TripService } from '../services/trip/trip.service';
import { VehicleService } from '../services/vehicle/vehicle.service';
import { statusLabel } from './status';
import { TripLocation } from '../models/geo/geo.model';
import { Field } from './field';
import { Icon } from './icon';
import { HelperPicker } from './helper-picker';
import { LocationField } from './location-field';
import { Modal } from './modal';
import { TripDialog } from './trip-dialog';

const BLANK_LOCATION: TripLocation = { place: '', lat: null, lng: null };

function straightLineMetres(lat1: number, lng1: number, lat2: number, lng2: number): number {
  const rad = (deg: number) => (deg * Math.PI) / 180;
  const dLat = rad(lat2 - lat1);
  const dLng = rad(lng2 - lng1);
  const a =
    Math.sin(dLat / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLng / 2) ** 2;

  return Math.round(6_371_000 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
}

const STATUSES: StatusValue[] = ['scheduled', 'assigned', 'pending', 'delivered', 'cancelled'];

@Component({
  selector: 'app-trip-form',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Modal, Field, HelperPicker, Icon, LocationField, ReactiveFormsModule],
  template: `
    <app-modal
      [(open)]="open"
      [title]="editing() ? 'Edit trip' : 'New trip'"
      [subtitle]="editing() ? trip()!.reference : 'Create a trip and assign a driver and vehicle'"
      icon="route"
      size="lg"
      [locked]="saving()"
      (closed)="reset()"
    >
      <form [formGroup]="form" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @if (billed()) {
          <p
            class="rounded-control bg-cr-tint px-3 py-2 text-[12px] text-cr-ink-muted sm:col-span-2"
          >
            Delivered and billed. The vehicle and status are locked because this trip's income and
            invoice are already on the books; everything else can still be corrected.
          </p>
        }

        <app-location-field
          label="Origin"
          required
          [value]="origin()"
          [error]="errorFor('origin')"
          placeholder="e.g. Pagadian"
          hint="Pin it on the map to record the coordinates."
          (changed)="origin.set($event)"
          (touched)="form.get('origin')?.markAsTouched()"
        />

        <app-location-field
          label="Destination"
          required
          [value]="destination()"
          [error]="errorFor('destination')"
          placeholder="e.g. Ozamis"
          hint="Pinning both ends fills in the distance."
          (changed)="destination.set($event)"
          (touched)="form.get('destination')?.markAsTouched()"
        />

        <app-field
          label="Cargo"
          required
          class="sm:col-span-2"
          [error]="errorFor('cargo')"
          hint="What is being moved, and how much of it."
        >
          <input
            formControlName="cargo"
            [class]="inputClass"
            placeholder="e.g. Dry goods, 12 pallets"
          />
        </app-field>

        <app-field label="Weight (kg)" required [error]="errorFor('weight_kg')">
          <input
            type="number"
            min="1"
            formControlName="weight_kg"
            [class]="inputClass + ' cr-num'"
            placeholder="e.g. 3200"
          />
        </app-field>

        <app-field label="Vehicle" required [error]="errorFor('vehicle_id')">
          <select formControlName="vehicle_id" [class]="inputClass">
            <option value="">Select a vehicle…</option>
            @for (v of vehicles(); track v.id) {
              <option [value]="v.id">{{ v.plate }} — {{ v.model }}</option>
            }
          </select>
        </app-field>

        <app-field label="Driver" required [error]="errorFor('driver_id')">
          <select formControlName="driver_id" [class]="inputClass">
            <option value="">Select a driver…</option>
            @for (d of drivers(); track d.id) {
              <option [value]="d.id">{{ d.name }}</option>
            }
          </select>
        </app-field>

        <app-helper-picker
          hint="Optional — add as many as the load needs."
          [drivers]="drivers()"
          [driverId]="driverId()"
          [(value)]="helpers"
        />

        <app-field label="Scheduled" required [error]="errorFor('scheduled_at')">
          <input type="datetime-local" formControlName="scheduled_at" [class]="inputClass" />
        </app-field>

        <app-field label="Status">
          <select formControlName="status" [class]="inputClass">
            @for (s of statuses; track s) {
              <option [value]="s">{{ label(s) }}</option>
            }
          </select>
        </app-field>

        @if (enteringPast() && trip()?.status !== 'delivered') {
          <p class="rounded-control bg-cr-tint px-3 py-2 text-[12px] text-cr-ink-muted">
            For a past trip. It's billed and recorded on the day it was delivered.
          </p>
          <app-field label="Delivered on" hint="Blank uses the scheduled time.">
            <input type="datetime-local" formControlName="delivered_at" [class]="inputClass" />
          </app-field>
          <app-field label="Received by">
            <input
              type="text"
              formControlName="receiver_name"
              placeholder="Optional"
              [class]="inputClass"
            />
          </app-field>
        }
        <div class="rounded-control border border-cr-line px-3 py-3 sm:col-span-2">
          <div class="flex flex-wrap items-center gap-2">
            <p class="text-[13px] font-semibold">Price</p>
            @if (manuallyPriced()) {
              <span
                class="inline-flex items-center rounded-full bg-cr-tint px-2 py-[3px] text-[10px] font-semibold tracking-[0.06em] text-cr-blue uppercase"
              >
                Manual price
              </span>
            }
          </div>

          @if (preview(); as q) {
            @if (q.needs_zone || q.cents === null) {
              <p class="mt-2 flex flex-wrap items-center gap-2 text-[13px]" role="status">
                <span
                  class="inline-flex items-center gap-1.5 rounded-full bg-cr-warning-bg px-2 py-[3px] text-[10px] font-semibold tracking-[0.06em] text-cr-warning uppercase"
                >
                  <span class="h-1.5 w-1.5 rounded-full bg-cr-warning"></span>
                  Needs a zone
                </span>
                <span>{{ q.reason }}</span>
              </p>
              <p class="mt-1 text-[12px] text-cr-ink-muted">
                It can be saved as a request, but not scheduled, dispatched or billed until a zone
                line covers it or a price is entered.
              </p>
            } @else {
              <p class="mt-2 text-[13px]">
                <span class="cr-num font-semibold">{{ money(q.cents, q.currency) }}</span>
                <span class="text-cr-ink-muted">
                  · Zone {{ q.zone?.code }} · {{ q.bracket?.label }} · {{ q.km }} km{{
                    estimated() ? ' (estimate)' : ''
                  }}
                </span>
              </p>
            }
          } @else if (trip(); as t) {
            @if (t.price_cents === null) {
              <p class="mt-2 text-[13px] text-cr-warning" role="status">
                Needs a zone{{ t.pricing_note ? ' — ' + t.pricing_note : '' }}
              </p>
            } @else {
              <p class="cr-num mt-2 text-[13px] font-semibold">
                {{ money(t.price_cents, t.currency) }}
              </p>
            }
          } @else {
            <p class="mt-2 text-[12px] text-cr-ink-muted">
              Priced off the zone card when it is saved.
            </p>
          }

          @if (canPrice()) {
            <app-field
              label="Enter a price (₱)"
              class="mt-3 block max-w-[240px]"
              [hint]="
                manuallyPriced()
                  ? 'Clear it to price off the zone card again.'
                  : 'Leave blank to use the zone card.'
              "
            >
              <input
                type="number"
                min="0"
                step="0.01"
                formControlName="price"
                [class]="inputClass + ' cr-num'"
                placeholder="From the zone card"
              />
            </app-field>
          } @else if (!editing() || trip()?.price_cents === null) {
            <p class="mt-2 text-[12px] text-cr-ink-muted">
              Only someone who manages the Pricing card can enter a price by hand.
            </p>
          }
        </div>

        @if (failure(); as message) {
          <p class="text-[13px] font-medium text-cr-red sm:col-span-2" role="alert">
            {{ message }}
          </p>
        }
      </form>

      <ng-container modal-footer>
        @if (editing()) {
          <button
            type="button"
            class="mr-auto inline-flex h-10 items-center gap-1.5 rounded-control border border-cr-line px-4 text-[14px] font-semibold text-cr-blue transition-colors hover:bg-cr-tint disabled:opacity-50"
            [disabled]="saving()"
            (click)="printTicket()"
          >
            <app-icon name="clipboard" [size]="16" />
            Print trip ticket
          </button>
        }
        <button
          type="button"
          class="h-10 rounded-control px-4 text-[14px] font-semibold text-cr-ink transition-colors hover:bg-cr-tint disabled:opacity-50"
          [disabled]="saving()"
          (click)="open.set(false)"
        >
          Cancel
        </button>
        <button
          type="button"
          class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
          [disabled]="saving()"
          (click)="submit()"
        >
          {{ saving() ? 'Saving…' : editing() ? 'Save changes' : 'Create trip' }}
        </button>
      </ng-container>
    </app-modal>
  `,
})
export class TripForm {
  private readonly tripApi = inject(TripService);
  private readonly router = inject(Router);
  private readonly vehiclesApi = inject(VehicleService);
  private readonly driversApi = inject(DriverService);
  private readonly fb = inject(FormBuilder);
  private readonly identity = inject(IdentityService);
  private readonly pricingApi = inject(PricingService);

  private readonly dialog = inject(TripDialog);

  protected readonly open = this.dialog.open;
  protected readonly trip = this.dialog.trip;

  protected readonly saving = signal(false);
  protected readonly editing = computed(() => this.trip() !== null);

  protected printTicket(): void {
    const trip = this.trip();

    if (trip === null) return;

    this.open.set(false);
    void this.router.navigate(['/trips', trip.id, 'ticket']);
  }
  protected readonly billed = computed(() => !!this.trip()?.billed_at);
  protected readonly failure = signal<string | null>(null);
  protected readonly statuses = STATUSES;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly vehicles = signal<Vehicle[]>([]);
  protected readonly drivers = signal<Driver[]>([]);

  protected readonly origin = signal<TripLocation>(BLANK_LOCATION);
  protected readonly destination = signal<TripLocation>(BLANK_LOCATION);

  protected readonly form = this.fb.nonNullable.group({
    origin: ['', Validators.required],
    destination: ['', Validators.required],
    cargo: ['', Validators.required],
    weight_kg: [0, [Validators.required, Validators.min(1)]],
    vehicle_id: ['', Validators.required],
    driver_id: ['', Validators.required],
    scheduled_at: ['', Validators.required],
    status: ['scheduled' as StatusValue],
    delivered_at: [''],
    receiver_name: [''],
    price: [''],
  });

  protected readonly canPrice = computed(() => this.identity.can('pricing.manage'));

  protected readonly manuallyPriced = computed(() => !!this.trip()?.manually_priced);

  protected readonly preview = signal<QuoteBreakdown | null>(null);

  protected readonly estimated = signal(false);

  private readonly weight = toSignal(this.form.controls.weight_kg.valueChanges, {
    initialValue: this.form.controls.weight_kg.value,
  });

  private readonly previewDistance = computed(() => {
    const t = this.trip();
    const o = this.origin();
    const d = this.destination();
    const unmoved =
      t !== null &&
      o.lat === t.origin_lat &&
      o.lng === t.origin_lng &&
      d.lat === t.destination_lat &&
      d.lng === t.destination_lng;

    if (unmoved && t.distance_total_m > 0) return { metres: t.distance_total_m, estimate: false };
    if (o.lat === null || o.lng === null || d.lat === null || d.lng === null) {
      return { metres: 0, estimate: false };
    }

    return { metres: straightLineMetres(o.lat, o.lng, d.lat, d.lng), estimate: true };
  });

  protected readonly enteringPast = toSignal(
    this.form.controls.status.valueChanges.pipe(map((s) => s === 'delivered')),
    { initialValue: false },
  );

  protected readonly helpers = signal<string[]>([]);

  protected readonly driverId = toSignal(this.form.controls.driver_id.valueChanges, {
    initialValue: this.form.controls.driver_id.value,
  });

  constructor() {
    effect(() => this.form.get('origin')?.setValue(this.origin().place));
    effect(() => this.form.get('destination')?.setValue(this.destination().place));

    this.vehiclesApi.list().subscribe((res) => this.vehicles.set(res.data));
    this.driversApi.list().subscribe((res) => this.drivers.set(res.data));

    toObservable(
      computed(() => ({
        open: this.open(),
        distance: this.previewDistance(),
        weight: Number(this.weight()) || 0,
        category: this.trip()?.truck_category_id ?? null,
      })),
    )
      .pipe(
        debounceTime(300),
        switchMap((ask) => {
          if (!ask.open || !this.identity.can('pricing.view')) return of(null);

          this.estimated.set(ask.distance.estimate);

          return this.pricingApi
            .quote({
              distance_km: ask.distance.metres / 1000,
              weight_kg: ask.weight,
              truck_category_id: ask.category,
            })
            .pipe(catchError(() => of(null)));
        }),
        takeUntilDestroyed(),
      )
      .subscribe((quote) => this.preview.set(quote));

    effect(() => {
      if (!this.open()) return;
      const t = this.trip();
      this.origin.set({
        place: t?.origin ?? '',
        lat: t?.origin_lat ?? null,
        lng: t?.origin_lng ?? null,
      });

      this.destination.set({
        place: t?.destination ?? '',
        lat: t?.destination_lat ?? null,
        lng: t?.destination_lng ?? null,
      });

      this.form.reset({
        origin: t?.origin ?? '',
        destination: t?.destination ?? '',
        cargo: t?.cargo ?? '',
        weight_kg: t?.weight_kg ?? 0,
        vehicle_id: t?.vehicle_id ?? '',
        driver_id: t?.driver_id ?? '',
        scheduled_at: t ? t.scheduled_at.slice(0, 16) : '',
        status: t?.status ?? 'scheduled',
        delivered_at: '',
        receiver_name: '',
        price: t?.manually_priced && t.price_cents !== null ? String(t.price_cents / 100) : '',
      });

      this.helpers.set(t?.helper_ids ?? []);
      this.preview.set(null);
      this.failure.set(null);

      const locked = !!t?.billed_at;
      for (const name of ['vehicle_id', 'status'] as const) {
        const control = this.form.controls[name];
        if (locked) control.disable();
        else control.enable();
      }
    });
  }

  protected label(s: StatusValue) {
    return statusLabel(s);
  }

  protected money(cents: number, currency?: string): string {
    return fmt.money(cents, currency);
  }

  private pricePayload(raw: string): { price_cents?: number | null } {
    const control = this.form.controls.price;

    if (!this.canPrice() || !control.dirty) return {};

    const typed = String(raw ?? '').trim();

    if (typed === '') return this.manuallyPriced() ? { price_cents: null } : {};

    return { price_cents: Math.round(Number(typed) * 100) };
  }

  protected errorFor(name: string): string | null {
    const c = this.form.get(name);
    if (!c || c.valid || !(c.touched || c.dirty)) return null;
    if (c.hasError('required')) return 'This field is required.';
    if (c.hasError('min')) return 'Must be greater than zero.';
    return 'Check this value.';
  }

  protected reset(): void {
    this.saving.set(false);
    this.form.markAsPristine();
    this.form.markAsUntouched();
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.saving.set(true);
    const raw = this.form.getRawValue();
    const existing = this.trip();

    const origin = this.origin();
    const destination = this.destination();

    const payload: TripPayload = {
      origin: origin.place,
      origin_lat: origin.lat,
      origin_lng: origin.lng,
      destination: destination.place,
      destination_lat: destination.lat,
      destination_lng: destination.lng,
      cargo: raw.cargo,
      weight_kg: Number(raw.weight_kg),
      driver_id: raw.driver_id || null,
      helper_ids: this.helpers().filter((id) => id !== raw.driver_id),
      vehicle_id: raw.vehicle_id || null,
      status: raw.status,
      scheduled_at: new Date(raw.scheduled_at).toISOString(),
      ...(raw.status === 'delivered' && existing?.status !== 'delivered'
        ? {
            delivered_at: raw.delivered_at ? new Date(raw.delivered_at).toISOString() : null,
            receiver_name: raw.receiver_name || null,
          }
        : {}),
      eta: existing?.eta ?? null,
      ...this.pricePayload(raw.price),
    };

    const request = existing
      ? this.tripApi.update(existing.id, payload)
      : this.tripApi.create(payload);

    request.subscribe({
      next: (saved) => {
        this.saving.set(false);
        this.dialog.announceSaved(saved);
        this.open.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.saving.set(false);
        this.failure.set(
          error.error?.message ?? `The server refused that request (${error.status}).`,
        );
      },
    });
  }
}

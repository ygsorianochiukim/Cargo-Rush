import { ChangeDetectionStrategy, Component, computed, effect, inject, input, model, output, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import { Driver } from '../../models/driver/driver.model';
import { PricingZone } from '../../models/pricing/pricing.model';
import { Trip } from '../../models/trip/trip.model';
import { Vehicle } from '../../models/vehicle/vehicle.model';
import { DriverService } from '../../services/driver/driver.service';
import { IdentityService } from '../../services/identity/identity.service';
import { PricingService } from '../../services/pricing/pricing.service';
import { TripService } from '../../services/trip/trip.service';
import { VehicleService } from '../../services/vehicle/vehicle.service';
import { Field } from '../../shared/field';
import { HelperPicker } from '../../shared/helper-picker';
import { fmt } from '../../shared/format';
import { Modal } from '../../shared/modal';

@Component({
  selector: 'app-confirm-request',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Modal, Field, HelperPicker, ReactiveFormsModule],
  template: `
    <app-modal
      [(open)]="open"
      title="Confirm request"
      [subtitle]="subtitle()"
      icon="check"
      size="md"
      [locked]="saving()"
      (closed)="reset()">
      @if (trip(); as request) {
        <dl class="mb-4 grid grid-cols-2 gap-x-4 gap-y-2 rounded-card bg-cr-tint p-3">
          <div class="col-span-2">
            <dt class="cr-meta">Route</dt>
            <dd class="text-[13px] font-semibold">
              {{ request.origin }} → {{ request.destination }}
            </dd>
          </div>
          <div>
            <dt class="cr-meta">Cargo</dt>
            <dd class="text-[13px]">{{ request.cargo }}</dd>
          </div>
          <div>
            <dt class="cr-meta">Customer</dt>
            <dd class="text-[13px]">{{ request.customer ?? '—' }}</dd>
          </div>
          <div>
            <dt class="cr-meta">Asked for</dt>
            <dd class="cr-num text-[13px]">{{ fmt.dateTime(request.scheduled_at) }}</dd>
          </div>
          <div>
            <dt class="cr-meta">Distance</dt>
            <dd class="cr-num text-[13px]">
              @if (request.distance_total_m > 0) {
                {{ fmt.km(kilometres(request.distance_total_m)) }}
                <span
                  class="ml-1 text-[12px]"
                  [class.text-cr-warning]="request.distance_source === 'estimate'"
                  [class.text-cr-ink-muted]="request.distance_source !== 'estimate'">
                  {{ distanceNote(request.distance_source) }}
                </span>
              } @else {
                <span class="text-cr-warning">Not pinned — quoted in the lowest band</span>
              }
            </dd>
          </div>
          <div>
            <dt class="cr-meta">Quoted</dt>
            <dd class="cr-num text-[13px] font-semibold">
              @if (request.price_cents === null) {
                <span class="text-cr-warning">Needs a zone</span>
                @if (request.pricing_note) {
                  <span class="block text-[12px] font-normal text-cr-ink-muted">{{ request.pricing_note }}</span>
                }
              } @else {
                {{ fmt.money(request.price_cents, request.currency) }}
              }
            </dd>
          </div>
        </dl>
      }

      <form [formGroup]="form" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
          [drivers]="helperChoices()"
          [driverId]="driverId()"
          [(value)]="helpers" />

        <app-field label="Vehicle" required [error]="errorFor('vehicle_id')">
          <select formControlName="vehicle_id" [class]="inputClass">
            <option value="">Select a vehicle…</option>
            @for (v of vehicles(); track v.id) {
              <option [value]="v.id">{{ v.plate }} — {{ v.model }}</option>
            }
          </select>
        </app-field>

        <app-field
          label="Scheduled"
          required
          hint="Defaults to the time the customer asked for."
          [error]="errorFor('scheduled_at')">
          <input type="datetime-local" formControlName="scheduled_at" [class]="inputClass" />
        </app-field>

        <app-field
          label="Weight (kg)"
          class="sm:col-span-2"
          hint="Correcting the customer's estimate re-quotes the haul."
          [error]="errorFor('weight_kg')">
          <input
            type="number"
            min="1"
            formControlName="weight_kg"
            [class]="inputClass + ' cr-num'" />
        </app-field>

        @if (zones().length > 0) {
          <app-field
            label="Zone"
            class="sm:col-span-2"
            [hint]="
              form.controls.zone_id.value
                ? 'Picked by hand — priced off this zone whatever the distance says.'
                : 'Automatic from the distance. Pick the right one if the kilometres were misread.'
            ">
            <select formControlName="zone_id" [class]="inputClass">
              <option value="">Automatic (by distance)</option>
              @for (z of zones(); track z.id) {
                <option [value]="z.id">{{ z.code }} — {{ z.name }} · {{ z.band }}</option>
              }
            </select>
          </app-field>
        }
      </form>

      @if (error(); as message) {
        <p class="mt-3 text-[13px] font-medium text-cr-red" role="alert">{{ message }}</p>
      }

      <ng-container modal-footer>
        <button
          type="button"
          class="h-10 rounded-control px-4 text-[14px] font-semibold text-cr-ink transition-colors hover:bg-cr-tint disabled:opacity-50"
          [disabled]="saving()"
          (click)="open.set(false)">
          Cancel
        </button>
        <button
          type="button"
          class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
          [disabled]="saving()"
          (click)="submit()">
          {{ saving() ? 'Confirming…' : 'Confirm and assign' }}
        </button>
      </ng-container>
    </app-modal>
  `,
})
export class ConfirmRequestDialog {
  private readonly tripApi = inject(TripService);
  private readonly vehiclesApi = inject(VehicleService);
  private readonly driversApi = inject(DriverService);
  private readonly pricingApi = inject(PricingService);
  private readonly identity = inject(IdentityService);
  private readonly fb = inject(FormBuilder);

  readonly open = model(false);
  readonly trip = input<Trip | null>(null);

  readonly confirmed = output<Trip>();

  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly vehicles = signal<Vehicle[]>([]);
  private readonly crew = signal<Driver[]>([]);
  protected readonly zones = signal<PricingZone[]>([]);

  protected readonly drivers = computed(() => this.crew().filter((d) => d.role === 'driver'));
  protected readonly helperChoices = computed(() => {
    const chosen = new Set(this.helpers());

    return this.crew().filter((d) => d.role === 'helper' || chosen.has(d.id));
  });

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly form = this.fb.nonNullable.group({
    driver_id: ['', Validators.required],
    vehicle_id: ['', Validators.required],
    scheduled_at: ['', Validators.required],
    weight_kg: [0, [Validators.required, Validators.min(1)]],
    zone_id: [''],
  });

  protected readonly helpers = signal<string[]>([]);

  protected readonly driverId = toSignal(this.form.controls.driver_id.valueChanges, {
    initialValue: this.form.controls.driver_id.value,
  });

  protected kilometres(metres: number): number {
    return Math.ceil(metres / 1000);
  }

  protected distanceNote(source: Trip['distance_source']): string {
    switch (source) {
      case 'road':
        return 'by road';
      case 'estimate':
        return 'estimated — check the route';
      case 'manual':
        return 'entered by the desk';
      default:
        return '';
    }
  }

  protected subtitle(): string {
    const request = this.trip();

    return request === null ? 'Assign a crew and a unit' : request.reference;
  }

  constructor() {
    this.vehiclesApi.list().subscribe((res) => this.vehicles.set(res.data));
    this.driversApi.crew().subscribe((res) => this.crew.set(res.data));

    if (this.identity.can('pricing.view')) {
      this.pricingApi
        .list({ per_page: 100, status: 'active' })
        .subscribe((res) => this.zones.set(res.data));
    }

    effect(() => {
      if (!this.open()) return;

      const request = this.trip();

      this.error.set(null);
      this.form.reset({
        driver_id: request?.driver_id ?? '',
        vehicle_id: request?.vehicle_id ?? '',
        scheduled_at: request ? request.scheduled_at.slice(0, 16) : '',
        weight_kg: request?.weight_kg ?? 0,
        zone_id: request?.zone_pinned ? (request.pricing_zone_id ?? '') : '',
      });

      this.helpers.set(request?.helper_ids ?? []);
    });
  }

  protected errorFor(name: string): string | null {
    const control = this.form.get(name);
    if (!control || control.valid || !(control.touched || control.dirty)) return null;
    if (control.hasError('required')) return 'This field is required.';
    if (control.hasError('min')) return 'Must be greater than zero.';
    return 'Check this value.';
  }

  protected reset(): void {
    this.saving.set(false);
    this.error.set(null);
    this.form.markAsPristine();
    this.form.markAsUntouched();
  }

  protected submit(): void {
    const request = this.trip();

    if (request === null) return;

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.saving.set(true);
    this.error.set(null);

    const raw = this.form.getRawValue();

    this.tripApi
      .confirm(request.id, {
        driver_id: raw.driver_id,
        helper_ids: this.helpers().filter((id) => id !== raw.driver_id),
        vehicle_id: raw.vehicle_id,
        scheduled_at: new Date(raw.scheduled_at).toISOString(),
        weight_kg: Number(raw.weight_kg),
        ...(this.form.controls.zone_id.dirty ? { pricing_zone_id: raw.zone_id || null } : {}),
      })
      .subscribe({
        next: (assigned) => {
          this.saving.set(false);
          this.confirmed.emit(assigned);
          this.open.set(false);
        },
        error: (failure: { error?: { message?: string } }) => {
          this.saving.set(false);
          this.error.set(failure.error?.message ?? 'That request could not be confirmed.');
        },
      });
  }
}

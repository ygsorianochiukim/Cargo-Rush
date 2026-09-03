import { ChangeDetectionStrategy, Component, computed, effect, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import { Driver } from '../models/driver/driver.model';
import { StatusValue } from '../models/shared/status.model';
import { TripPayload } from '../models/trip/trip.model';
import { Vehicle } from '../models/vehicle/vehicle.model';
import { DriverService } from '../services/driver/driver.service';
import { TripService } from '../services/trip/trip.service';
import { VehicleService } from '../services/vehicle/vehicle.service';
import { statusLabel } from './status';
import { TripLocation } from '../models/geo/geo.model';
import { Field } from './field';
import { LocationField } from './location-field';
import { Modal } from './modal';
import { TripDialog } from './trip-dialog';

/** An empty place: no name, no pin. */
const BLANK_LOCATION: TripLocation = { place: '', lat: null, lng: null };

/**
 * The statuses the office owns, and only those.
 *
 * `in_transit` and `delivered` are absent deliberately, and the API agrees:
 * each is reached by a driver doing something (leaving on the run, handing it
 * over) and each writes more than a column — a dispatch record, a delivery log
 * with its proof, the driver's credit, the day's income, the customer's
 * invoice. Offering them here would be offering a choice the API answers 422
 * to.
 *
 * `pending` on this list means what it means everywhere: a request nobody has
 * decided about yet. Moving one to `assigned` is `Confirm`, on Trip
 * Management, because that transition needs a driver, a unit and a time.
 */
const STATUSES: StatusValue[] = ['scheduled', 'assigned', 'pending', 'cancelled'];

/**
 * The create/edit trip dialog. One component serves both — pass `trip` to edit,
 * leave it null to create — so the form, validation and labels exist once.
 * Mounted in the shell for the global "New trip" button and reused per-row on
 * the Trip Management page.
 */
@Component({
  selector: 'app-trip-form',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Modal, Field, LocationField, ReactiveFormsModule],
  template: `
    <app-modal
      [(open)]="open"
      [title]="editing() ? 'Edit trip' : 'New trip'"
      [subtitle]="editing() ? trip()!.reference : 'Create a trip and assign a driver and vehicle'"
      icon="route"
      size="lg"
      [locked]="saving()"
      (closed)="reset()">
      <form [formGroup]="form" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <app-location-field
          label="Origin"
          required
          [value]="origin()"
          [error]="errorFor('origin')"
          placeholder="e.g. Pagadian"
          hint="Pin it on the map to record the coordinates."
          (changed)="origin.set($event)"
          (touched)="form.get('origin')?.markAsTouched()" />

        <app-location-field
          label="Destination"
          required
          [value]="destination()"
          [error]="errorFor('destination')"
          placeholder="e.g. Ozamis"
          hint="Pinning both ends fills in the distance."
          (changed)="destination.set($event)"
          (touched)="form.get('destination')?.markAsTouched()" />

        <app-field
          label="Cargo"
          required
          class="sm:col-span-2"
          [error]="errorFor('cargo')"
          hint="What is being moved, and how much of it.">
          <input formControlName="cargo" [class]="inputClass" placeholder="e.g. Dry goods, 12 pallets" />
        </app-field>

        <app-field label="Weight (kg)" required [error]="errorFor('weight_kg')">
          <input
            type="number"
            min="1"
            formControlName="weight_kg"
            [class]="inputClass + ' cr-num'"
            placeholder="e.g. 3200" />
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

        <app-field
          label="Helper"
          hint="Optional second crew member."
          [error]="errorFor('helper_id')">
          <select formControlName="helper_id" [class]="inputClass">
            <option value="">None</option>
            @for (d of drivers(); track d.id) {
              <option [value]="d.id">{{ d.name }}</option>
            }
          </select>
        </app-field>

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
      </form>

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
          {{ saving() ? 'Saving…' : editing() ? 'Save changes' : 'Create trip' }}
        </button>
      </ng-container>
    </app-modal>
  `,
})
export class TripForm {
  private readonly tripApi = inject(TripService);
  private readonly vehiclesApi = inject(VehicleService);
  private readonly driversApi = inject(DriverService);
  private readonly fb = inject(FormBuilder);

  private readonly dialog = inject(TripDialog);

  /** State lives in the service so any page can drive this one instance. */
  protected readonly open = this.dialog.open;
  protected readonly trip = this.dialog.trip;

  protected readonly saving = signal(false);
  protected readonly editing = computed(() => this.trip() !== null);
  protected readonly statuses = STATUSES;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly vehicles = signal<Vehicle[]>([]);
  protected readonly drivers = signal<Driver[]>([]);

  /**
   * The two places, each a name plus optional coordinates.
   *
   * Held beside the form rather than in it: a location is three values that
   * are only meaningful together, and three sibling controls would let a
   * latitude survive a change of place.
   */
  protected readonly origin = signal<TripLocation>(BLANK_LOCATION);
  protected readonly destination = signal<TripLocation>(BLANK_LOCATION);

  protected readonly form = this.fb.nonNullable.group({
    origin: ['', Validators.required],
    destination: ['', Validators.required],
    cargo: ['', Validators.required],
    weight_kg: [0, [Validators.required, Validators.min(1)]],
    vehicle_id: ['', Validators.required],
    driver_id: ['', Validators.required],
    helper_id: [''],
    scheduled_at: ['', Validators.required],
    status: ['scheduled' as StatusValue],
  });

  constructor() {
    // The name drives `required`; the signal carries the whole location.
    effect(() => this.form.get('origin')?.setValue(this.origin().place));
    effect(() => this.form.get('destination')?.setValue(this.destination().place));

    this.vehiclesApi.list().subscribe((res) => this.vehicles.set(res.data));
    this.driversApi.list().subscribe((res) => this.drivers.set(res.data));

    // Load the row being edited whenever the dialog opens.
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
        helper_id: t?.helper_id ?? '',
        scheduled_at: t ? t.scheduled_at.slice(0, 16) : '',
        status: t?.status ?? 'scheduled',
      });
    });
  }

  protected label(s: StatusValue) {
    return statusLabel(s);
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

    // No id and no reference: the API assigns both, so neither is sent.
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
      helper_id: raw.helper_id || null,
      vehicle_id: raw.vehicle_id || null,
      status: raw.status,
      scheduled_at: new Date(raw.scheduled_at).toISOString(),
      eta: existing?.eta ?? null,
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
      error: () => this.saving.set(false),
    });
  }
}

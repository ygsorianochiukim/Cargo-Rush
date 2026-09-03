import { ChangeDetectionStrategy, Component, effect, inject, input, model, output, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import { Driver } from '../../models/driver/driver.model';
import { Trip } from '../../models/trip/trip.model';
import { Vehicle } from '../../models/vehicle/vehicle.model';
import { DriverService } from '../../services/driver/driver.service';
import { TripService } from '../../services/trip/trip.service';
import { VehicleService } from '../../services/vehicle/vehicle.service';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Modal } from '../../shared/modal';

/**
 * Confirming a delivery request — the tracking desk's one action on it.
 *
 * A customer's request arrives with the load and the two ends and nothing
 * else, so this form is exactly the four things only the office knows: who
 * drives it, who rides along, which unit, and when. The status is not on the
 * form, because `assigned` is what follows from naming those — a dropdown
 * beside them would let somebody write it without them, and produce a run a
 * driver is told to start and has no vehicle for.
 *
 * Not the create/edit dialog with fields hidden. That form asks for the route
 * and the cargo, which are the customer's to state and are already answered;
 * re-presenting them as editable invites the desk to quietly rewrite what was
 * asked for. The one thing it can correct is the weight, because the price is
 * re-quoted from it and an estimate off by a tonne would bill the wrong
 * amount.
 */
@Component({
  selector: 'app-confirm-request',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Modal, Field, ReactiveFormsModule],
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
        <!-- What was asked for. Read-only: this is the customer's statement of
             the job, not a field for the desk to revise. -->
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
            <dt class="cr-meta">Quoted</dt>
            <dd class="cr-num text-[13px] font-semibold">
              {{ fmt.money(request.price_cents, request.currency) }}
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

        <app-field label="Helper" hint="Optional second crew member." [error]="errorFor('helper_id')">
          <select formControlName="helper_id" [class]="inputClass">
            <option value="">None</option>
            @for (d of drivers(); track d.id) {
              <option [value]="d.id">{{ d.name }}</option>
            }
          </select>
        </app-field>

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
  private readonly fb = inject(FormBuilder);

  readonly open = model(false);
  /** The request being confirmed. Null closes the form's meaning, not the modal. */
  readonly trip = input<Trip | null>(null);

  /** Emitted with the assigned trip, so the page can merge it into its list. */
  readonly confirmed = output<Trip>();

  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly vehicles = signal<Vehicle[]>([]);
  protected readonly drivers = signal<Driver[]>([]);

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly form = this.fb.nonNullable.group({
    driver_id: ['', Validators.required],
    helper_id: [''],
    vehicle_id: ['', Validators.required],
    scheduled_at: ['', Validators.required],
    weight_kg: [0, [Validators.required, Validators.min(1)]],
  });

  protected subtitle(): string {
    const request = this.trip();

    return request === null ? 'Assign a crew and a unit' : request.reference;
  }

  constructor() {
    this.vehiclesApi.list().subscribe((res) => this.vehicles.set(res.data));
    this.driversApi.list().subscribe((res) => this.drivers.set(res.data));

    // Seeded from the request each time the dialog opens. The schedule starts
    // at what the customer asked for, because agreeing to it is the common
    // case and re-typing it would invite a different answer by accident.
    effect(() => {
      if (!this.open()) return;

      const request = this.trip();

      this.error.set(null);
      this.form.reset({
        driver_id: request?.driver_id ?? '',
        helper_id: request?.helper_id ?? '',
        vehicle_id: request?.vehicle_id ?? '',
        scheduled_at: request ? request.scheduled_at.slice(0, 16) : '',
        weight_kg: request?.weight_kg ?? 0,
      });
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
        // Cleared rather than omitted: a request confirmed without a helper
        // has to end up with none, not with whatever was there before.
        helper_id: raw.helper_id || null,
        vehicle_id: raw.vehicle_id,
        scheduled_at: new Date(raw.scheduled_at).toISOString(),
        weight_kg: Number(raw.weight_kg),
      })
      .subscribe({
        next: (assigned) => {
          this.saving.set(false);
          this.confirmed.emit(assigned);
          this.open.set(false);
        },
        error: (failure: { error?: { message?: string } }) => {
          this.saving.set(false);
          // The API's own wording — it knows why it refused, and this screen
          // would only be guessing.
          this.error.set(failure.error?.message ?? 'That request could not be confirmed.');
        },
      });
  }
}

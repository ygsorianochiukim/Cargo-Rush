import { inject } from '@angular/core';

import { Vehicle } from '../../models/vehicle/vehicle.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { DriverService } from '../driver/driver.service';
import { TruckerService } from '../trucker/trucker.service';
import { VehicleService } from './vehicle.service';

/** Vehicle Management — registration, capacity, status, odometer. */
/**
 * Which of the four arrangements is on the form right now.
 *
 * Read off the values rather than off a signal, because that is what `showWhen`
 * is handed — and kept here as two named questions rather than four string
 * comparisons scattered through the field list, so "does this one pay a share"
 * is asked the same way every time it is asked.
 *
 * They mirror `VehicleArrangement::isHired()` and `sharesRevenue()` on the API.
 * Two copies of a rule is one place for them to drift, and the reason this is
 * tolerable is that the API is still the one enforcing it: getting these wrong
 * hides a field somebody needed, and the save then fails with the API's own
 * message rather than storing something incoherent.
 */
function isHired(values: Record<string, unknown>): boolean {
  return values['arrangement'] !== 'owned' && values['arrangement'] != null;
}

/**
 * Pesos to centavos, and per cent to basis points — the same ×100 either way.
 *
 * Rounded, because `49.99 * 100` is 4998.999999999999 in binary floating point
 * and a truncation there would quietly bill a peso short every month.
 */
function toCents(value: number | null): number | null {
  return value === null ? null : Math.round(value * 100);
}

function sharesRevenue(values: Record<string, unknown>): boolean {
  return values['arrangement'] === 'rented_share' || values['arrangement'] === 'subcontracted';
}

export function vehicleSpec(
): RecordSpec<Vehicle> {
  const vehicles = inject(VehicleService);
  const drivers = inject(DriverService);
  const truckers = inject(TruckerService);

  // Fetched once when the spec is built, so opening the dialog does not wait
  // on a request before it can draw its driver list.
  const roster: { value: string; label: string }[] = [];
  drivers.list().subscribe((res) => {
    roster.length = 0;
    roster.push(...res.data.map((d) => ({ value: d.id, label: d.name })));
  });

  /**
   * Who a hired truck's share can be credited to.
   *
   * The partner roster, because that is where the money goes: an owner's
   * account behaves exactly like a trucker's — accruing per run, netting,
   * settled a run at a time — so they are one list and one wallet rather than
   * two. Somebody who only supplies a truck is added on the Truckers page
   * first, with no licence.
   */
  const partners: { value: string; label: string }[] = [];
  truckers.list({ per_page: 100 }).subscribe((res) => {
    partners.length = 0;
    partners.push(...res.data.map((t) => ({ value: t.id, label: t.name })));
  });

  return {
    noun: 'vehicle',
    icon: 'fleet',

    fields: [
      { key: 'plate', label: 'Plate number', kind: 'text', required: true, placeholder: 'NCR 4412' },
      { key: 'model', label: 'Model', kind: 'text', required: true, placeholder: 'Isuzu Elf 4W' },
      {
        key: 'registration_no',
        label: 'Registration number',
        kind: 'text',
        required: true,
        placeholder: 'LTO-2024-44120',
      },
      { key: 'capacity_kg', label: 'Capacity (kg)', kind: 'number', required: true, min: 0, max: 60000 },
      { key: 'odometer_km', label: 'Odometer (km)', kind: 'number', min: 0 },
      {
        key: 'next_service_km',
        label: 'Next service at (km)',
        kind: 'number',
        min: 0,
        hint: 'Must be at or beyond the current odometer.',
      },
      {
        key: 'driver_id',
        label: 'Assigned driver (optional)',
        kind: 'select',
        options: () => roster,
        /**
         * Said in the label rather than only in the hint.
         *
         * A truck arrives on the fleet before anybody is put on it — bought on
         * a Friday, crewed on the Monday — and a select sitting between four
         * starred fields reads as another one whether or not it carries a star.
         * The absence of a mark is not something anybody notices; the word is.
         */
        hint: 'Who currently holds the keys. Leave it empty until somebody does.',
      },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['available', 'active', 'maintenance', 'inactive']),
      },

      /**
       * How the truck is paid for.
       *
       * Every unit dispatches identically; this decides only where the money
       * goes when a run closes. Leaving it alone means the fleet owns it,
       * which is what adding a truck has always meant.
       */
      {
        key: 'arrangement',
        label: 'Arrangement',
        kind: 'select',
        options: () => [
          { value: 'owned', label: 'Owned by the fleet' },
          { value: 'rented', label: 'Rented — flat monthly fee' },
          { value: 'rented_share', label: 'Rented — share of what it earns' },
          { value: 'subcontracted', label: 'Sub-contracted' },
        ],
        hint: 'Owned keeps every peso. The other three pay somebody.',
      },
      /*
       * The terms, and only the ones the arrangement actually has.
       *
       * Every one of these used to be on the form all the time, so adding a
       * truck the fleet owns outright asked for its monthly rent, the
       * percentage the fleet keeps of it and who to pay that to — three
       * questions with no answer, on the commonest case of the four. Worse than
       * clutter: a rent typed onto an owned truck is a figure the rent command
       * will happily bill somebody for.
       *
       * `showWhen` drops a hidden field's validators as well as its control, so
       * the cross-field rules on the API — rent required when it is rented, a
       * payee required when it shares — line up with what was on screen rather
       * than contradicting it.
       */
      {
        key: 'wheels',
        label: 'Wheels',
        kind: 'number',
        min: 4,
        max: 22,
        showWhen: (values) => isHired(values),
        hint: 'What the desk means by a six- or ten-wheeler.',
      },
      /*
       * The owner, asked once.
       *
       * A share arrangement asked twice: "Owner" as free text, and "Paid to"
       * as a partner picker — two fields for one person, and the second is the
       * one that carries the money. Somebody typing a name into the first and
       * picking nobody in the second gets a truck that shares its earnings with
       * an account that does not exist.
       *
       * So a share truck is asked only for the partner, below. These two are
       * for a **flat-rented** truck, where the landlord is somebody the fleet
       * writes a cheque to every month and need not be a partner on the system
       * at all — there is nothing to pick from, so free text is the honest
       * control.
       */
      {
        key: 'owner_name',
        label: 'Owner',
        kind: 'text',
        showWhen: (values) => values['arrangement'] === 'rented',
        hint: 'Who the fleet pays the rent to.',
      },
      {
        key: 'owner_contact',
        label: 'Owner contact',
        kind: 'text',
        showWhen: (values) => values['arrangement'] === 'rented',
      },
      {
        key: 'rent_cents',
        label: 'Monthly rent (₱)',
        kind: 'number',
        min: 0,
        // Flat rent only. A share truck earns its owner a cut of what it hauls
        // and owes nothing in a month it never turned a wheel, which is the
        // whole difference between the two arrangements.
        showWhen: (values) => values['arrangement'] === 'rented',
        hint: 'Billed on the first of each month, whether it runs or not.',
      },
      {
        key: 'share_bp',
        label: 'Fleet keeps (%)',
        kind: 'number',
        min: 0,
        max: 50,
        showWhen: (values) => sharesRevenue(values),
        hint: '15 for a rented ten-wheeler, 12 for a sub-contractor.',
      },
      {
        key: 'owner_trucker_id',
        // Named for who they are rather than for what happens to them. "Paid
        // to" beside an "Owner" field read as two different people; there is
        // only ever one, and on a share truck they are a partner on the system
        // because that is what the wallet credits.
        label: 'Owner',
        kind: 'select',
        options: () => partners,
        showWhen: (values) => sharesRevenue(values),
        hint: 'The share is credited to their wallet. They need a trucker record first.',
      },
    ],

    title: (vehicle) => vehicle.plate,

    toForm: (vehicle) => ({
      plate: vehicle.plate,
      model: vehicle.model,
      registration_no: vehicle.registration_no,
      capacity_kg: vehicle.capacity_kg,
      odometer_km: vehicle.odometer_km,
      next_service_km: vehicle.next_service_km,
      driver_id: vehicle.driver_id ?? '',
      status: vehicle.status,
      arrangement: vehicle.arrangement ?? 'owned',
      wheels: vehicle.wheels ?? '',
      owner_name: vehicle.owner_name ?? '',
      owner_contact: vehicle.owner_contact ?? '',
      // Centavos on the wire, pesos in the form — the same boundary every
      // other money field in this app converts at.
      rent_cents: vehicle.rent_cents === null ? '' : vehicle.rent_cents / 100,
      share_bp: vehicle.share_bp === null ? '' : vehicle.share_bp / 100,
      owner_trucker_id: vehicle.owner_trucker_id ?? '',
    }),

    toPayload: (values) => {
      /**
       * A term the arrangement does not have is not sent, whatever is in the
       * control.
       *
       * Hiding a field leaves its **value** behind — the form submits
       * `getRawValue()`, which knows nothing about visibility. So a truck moved
       * from rented to owned would keep the ₱50,000 somebody typed before they
       * changed their mind, and `cargo:truck-rent` would go on billing it every
       * month for a unit the fleet owns outright. The field disappearing off
       * the screen is exactly what makes that impossible to notice.
       *
       * Cleared here rather than in the shared form, and deliberately: blanking
       * every hidden control by default would throw away real data on the
       * specs that hide a field somebody has already filled in. Which terms an
       * arrangement has is this form's own knowledge.
       */
      const arrangement = (values['arrangement'] || 'owned') as string;
      const hired = arrangement !== 'owned';
      const shares = arrangement === 'rented_share' || arrangement === 'subcontracted';
      const rents = arrangement === 'rented';

      const number = (key: string): number | null =>
        values[key] === '' || values[key] == null ? null : Number(values[key]);

      return {
        plate: values['plate'],
        model: values['model'],
        registration_no: values['registration_no'],
        capacity_kg: Number(values['capacity_kg'] ?? 0),
        odometer_km: Number(values['odometer_km'] ?? 0),
        next_service_km: Number(values['next_service_km'] ?? 0),
        // An empty select means "nobody", which is a null, not an empty string.
        driver_id: values['driver_id'] || null,
        status: values['status'] || 'available',

        arrangement: arrangement as never,

        /**
         * Blank stays blank.
         *
         * `Number('')` is 0, and a zero here would mean "this truck has no
         * wheels, no rent and the fleet keeps nothing" rather than "nobody
         * said" — which on the rent field would silently bill nothing every
         * month. Null is the honest answer and the column is nullable for it.
         */
        wheels: hired ? number('wheels') : null,
        // Free text only where it is asked for. A share truck's owner is the
        // partner below, and the fleet list already prefers their name over
        // this column — so writing a second copy of it here would be a name
        // that goes stale the day they change theirs.
        owner_name: rents ? values['owner_name'] || null : null,
        owner_contact: rents ? values['owner_contact'] || null : null,

        // Money on the wire is centavos, and a percentage is basis points.
        rent_cents: rents ? toCents(number('rent_cents')) : null,
        share_bp: shares ? toCents(number('share_bp')) : null,
        owner_trucker_id: shares ? values['owner_trucker_id'] || null : null,
      };
    },

    save: (payload, id) =>
      id ? vehicles.update(id, payload as never) : vehicles.create(payload as never),

    remove: (id) => vehicles.remove(id),
  };
}

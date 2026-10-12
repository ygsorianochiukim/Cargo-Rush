import { inject } from '@angular/core';

import { Vehicle } from '../../models/vehicle/vehicle.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { DriverService } from '../driver/driver.service';
import { RateCardService } from '../pricing/pricing.service';
import { TruckerService } from '../trucker/trucker.service';
import { VehicleService } from './vehicle.service';

function isHired(values: Record<string, unknown>): boolean {
  return values['arrangement'] !== 'owned' && values['arrangement'] != null;
}

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
  const rateCard = inject(RateCardService);

  const roster: { value: string; label: string }[] = [];
  drivers.list().subscribe((res) => {
    roster.length = 0;
    roster.push(...res.data.map((d) => ({ value: d.id, label: d.name })));
  });

  const partners: { value: string; label: string }[] = [];
  truckers.list({ per_page: 100 }).subscribe((res) => {
    partners.length = 0;
    partners.push(...res.data.map((t) => ({ value: t.id, label: t.name })));
  });

  const sizes: { value: string; label: string }[] = [];
  rateCard.truckCategories(true).subscribe({
    next: (res) => {
      sizes.length = 0;
      sizes.push(
        ...res.data.map((c) => ({
          value: c.id,
          label: c.cbm ? `${c.name} · ${c.cbm} cbm` : c.name,
        })),
      );
    },
    error: () => (sizes.length = 0),
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
      {
        key: 'truck_category_id',
        label: 'Truck size',
        kind: 'select',
        options: () => sizes,
        hint: 'Leave empty for a 28 cbm truck. A bigger size is priced ÷ 28 × its cbm.',
      },
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
        hint: 'Who currently holds the keys. Leave it empty until somebody does.',
      },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['available', 'active', 'maintenance', 'inactive']),
      },

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
      {
        key: 'wheels',
        label: 'Wheels',
        kind: 'number',
        min: 4,
        max: 22,
        showWhen: (values) => isHired(values),
        hint: '10 sets the truck size to 10 Wheeler when it is empty.',
      },
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
      truck_category_id: vehicle.truck_category_id ?? '',
      odometer_km: vehicle.odometer_km,
      next_service_km: vehicle.next_service_km,
      driver_id: vehicle.driver_id ?? '',
      status: vehicle.status,
      arrangement: vehicle.arrangement ?? 'owned',
      wheels: vehicle.wheels ?? '',
      owner_name: vehicle.owner_name ?? '',
      owner_contact: vehicle.owner_contact ?? '',
      rent_cents: vehicle.rent_cents === null ? '' : vehicle.rent_cents / 100,
      share_bp: vehicle.share_bp === null ? '' : vehicle.share_bp / 100,
      owner_trucker_id: vehicle.owner_trucker_id ?? '',
    }),

    toPayload: (values) => {
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
        truck_category_id: values['truck_category_id'] || null,
        odometer_km: Number(values['odometer_km'] ?? 0),
        next_service_km: Number(values['next_service_km'] ?? 0),
        driver_id: values['driver_id'] || null,
        status: values['status'] || 'available',

        arrangement: arrangement as never,

        wheels: hired ? number('wheels') : null,
        owner_name: rents ? values['owner_name'] || null : null,
        owner_contact: rents ? values['owner_contact'] || null : null,

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

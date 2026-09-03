import { inject } from '@angular/core';

import { Vehicle } from '../../models/vehicle/vehicle.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { DriverService } from '../driver/driver.service';
import { VehicleService } from './vehicle.service';

/** Vehicle Management — registration, capacity, status, odometer. */
export function vehicleSpec(): RecordSpec<Vehicle> {
  const vehicles = inject(VehicleService);
  const drivers = inject(DriverService);

  // Fetched once when the spec is built, so opening the dialog does not wait
  // on a request before it can draw its driver list.
  const roster: { value: string; label: string }[] = [];
  drivers.list().subscribe((res) => {
    roster.length = 0;
    roster.push(...res.data.map((d) => ({ value: d.id, label: d.name })));
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
        label: 'Assigned driver',
        kind: 'select',
        options: () => roster,
        hint: 'Who currently holds the keys. Leave empty if unassigned.',
      },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['available', 'active', 'maintenance', 'inactive']),
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
    }),

    toPayload: (values) => ({
      plate: values['plate'],
      model: values['model'],
      registration_no: values['registration_no'],
      capacity_kg: Number(values['capacity_kg'] ?? 0),
      odometer_km: Number(values['odometer_km'] ?? 0),
      next_service_km: Number(values['next_service_km'] ?? 0),
      // An empty select means "nobody", which is a null, not an empty string.
      driver_id: values['driver_id'] || null,
      status: values['status'] || 'available',
    }),

    save: (payload, id) =>
      id ? vehicles.update(id, payload as never) : vehicles.create(payload as never),

    remove: (id) => vehicles.remove(id),
  };
}

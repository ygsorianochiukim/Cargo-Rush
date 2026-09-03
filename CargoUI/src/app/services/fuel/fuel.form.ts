import { inject } from '@angular/core';

import { FuelRecord } from '../../models/fuel/fuel.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { DriverService } from '../driver/driver.service';
import { VehicleService } from '../vehicle/vehicle.service';
import { FuelService } from './fuel.service';

/**
 * Fuel Expense Monitoring — one fill-up.
 *
 * The amount is typed in pesos and sent as centavos: nobody is going to enter
 * 780000 for ₱7,800.00, and the API rejects a float outright rather than
 * rounding it somewhere out of sight.
 */
export function fuelSpec(): RecordSpec<FuelRecord> {
  const fuel = inject(FuelService);
  const drivers = inject(DriverService);
  const vehicles = inject(VehicleService);

  const roster: { value: string; label: string }[] = [];
  const fleet: { value: string; label: string }[] = [];

  drivers.list().subscribe((res) => {
    roster.length = 0;
    roster.push(...res.data.map((d) => ({ value: d.id, label: d.name })));
  });

  vehicles.list().subscribe((res) => {
    fleet.length = 0;
    fleet.push(...res.data.map((v) => ({ value: v.id, label: `${v.plate} — ${v.model}` })));
  });

  return {
    noun: 'fuel record',
    icon: 'fuel',

    fields: [
      { key: 'vehicle_id', label: 'Vehicle', kind: 'select', required: true, options: () => fleet },
      { key: 'driver_id', label: 'Driver', kind: 'select', options: () => roster },
      { key: 'litres', label: 'Litres', kind: 'number', required: true, min: 0, max: 2000 },
      { key: 'amount', label: 'Amount (₱)', kind: 'money', required: true },
      {
        key: 'odometer_km',
        label: 'Odometer (km)',
        kind: 'number',
        required: true,
        min: 0,
        hint: 'Moves the vehicle forward if this reading is higher.',
      },
      { key: 'receipt_no', label: 'Receipt number', kind: 'text', required: true, placeholder: 'RC-99120' },
      { key: 'logged_at', label: 'Filled at', kind: 'datetime', required: true },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['active', 'pending', 'cancelled']),
        hint: 'Pending counts as an open request; cancelled is not spend.',
      },
    ],

    title: (record) => `${record.receipt_no} · ${record.vehicle_plate ?? 'unassigned'}`,

    toForm: (record) => ({
      vehicle_id: record.vehicle_id ?? '',
      driver_id: record.driver_id ?? '',
      litres: record.litres,
      amount: record.amount_cents / 100,
      odometer_km: record.odometer_km,
      receipt_no: record.receipt_no,
      logged_at: record.logged_at?.slice(0, 16) ?? '',
      status: record.status,
    }),

    toPayload: (values) => ({
      vehicle_id: values['vehicle_id'],
      driver_id: values['driver_id'] || null,
      litres: Number(values['litres'] ?? 0),
      amount_cents: Math.round(Number(values['amount'] ?? 0) * 100),
      currency: 'PHP',
      odometer_km: Number(values['odometer_km'] ?? 0),
      receipt_no: values['receipt_no'],
      logged_at: new Date(String(values['logged_at'])).toISOString(),
      status: values['status'] || 'active',
    }),

    save: (payload, id) => (id ? fuel.update(id, payload as never) : fuel.create(payload as never)),

    remove: (id) => fuel.remove(id),
  };
}

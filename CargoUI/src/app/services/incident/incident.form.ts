import { inject } from '@angular/core';

import { Incident } from '../../models/incident/incident.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { DriverService } from '../driver/driver.service';
import { VehicleService } from '../vehicle/vehicle.service';
import { IncidentService } from './incident.service';

/**
 * Incident Management.
 *
 * The reference is absent because the API assigns it, the same way it assigns
 * a trip's. Reporting one also raises a notification, API-side.
 */
export function incidentSpec(): RecordSpec<Incident> {
  const incidents = inject(IncidentService);
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
    fleet.push(...res.data.map((v) => ({ value: v.id, label: v.plate })));
  });

  return {
    noun: 'incident',
    icon: 'incident',

    fields: [
      { key: 'kind', label: 'What happened', kind: 'text', required: true, placeholder: 'Tyre blowout' },
      { key: 'place', label: 'Where', kind: 'text', required: true, placeholder: 'SLEX km 58' },
      {
        key: 'occurred_at',
        label: 'When',
        kind: 'datetime',
        required: true,
        hint: 'Cannot be in the future.',
      },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['pending', 'active', 'maintenance', 'delivered', 'cancelled']),
      },
      { key: 'driver_id', label: 'Driver', kind: 'select', options: () => roster },
      { key: 'vehicle_id', label: 'Vehicle', kind: 'select', options: () => fleet },
      { key: 'notes', label: 'Notes', kind: 'textarea', wide: true },
    ],

    title: (incident) => `${incident.reference} · ${incident.kind}`,

    toForm: (incident) => ({
      kind: incident.kind,
      place: incident.place,
      // `datetime-local` wants no timezone and no seconds.
      occurred_at: incident.occurred_at?.slice(0, 16) ?? '',
      status: incident.status,
      driver_id: incident.driver_id ?? '',
      vehicle_id: incident.vehicle_id ?? '',
      notes: incident.notes ?? '',
    }),

    toPayload: (values) => ({
      kind: values['kind'],
      place: values['place'],
      occurred_at: new Date(String(values['occurred_at'])).toISOString(),
      status: values['status'] || 'pending',
      driver_id: values['driver_id'] || null,
      vehicle_id: values['vehicle_id'] || null,
      notes: values['notes'] || null,
    }),

    save: (payload, id) =>
      id ? incidents.update(id, payload as never) : incidents.report(payload as never),

    remove: (id) => incidents.remove(id),
  };
}

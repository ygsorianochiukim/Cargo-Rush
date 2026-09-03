import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { map } from 'rxjs';

import { Incident } from '../../models/incident/incident.model';
import { incidentSpec } from '../../services/incident/incident.form';
import { IncidentService } from '../../services/incident/incident.service';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { fmt } from '../../shared/format';
import { ListToolbar } from '../../shared/list-toolbar';
import { recordList } from '../../shared/record-list';
import { ErrorState } from '../../shared/states';

/** Incident Management — DESIGN.md section 5.1. */
@Component({
  selector: 'app-incidents',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, ListToolbar, ErrorState],
  template: `
    <app-list-toolbar
      [count]="list.rows()?.length ?? null"
      singular="incident"
      plural="incidents"
      actionLabel="Report incident"
      (add)="list.create()" />

    <app-card
      heading="Incident records"
      icon="incident"
      hint="Time, place and history"
      [padded]="false">
      @if (list.error(); as message) {
        <app-error-state [message]="message" (retry)="list.refresh()" />
      } @else {
        <app-data-table
          [columns]="columns"
          [rows]="list.rows()"
          [minWidth]="880"
          rowAction="Edit incident"
          [deletable]="true"
          [rowLabel]="label"
          emptyIcon="incident"
          emptyTitle="No incidents on record"
          emptyBody="A clean sheet. Report one here when something goes wrong on the road."
          (open)="list.edit($any($event))"
          (remove)="list.remove($any($event))" />
      }
    </app-card>
  `,
})
export class IncidentsPage {
  private readonly incidentsApi = inject(IncidentService);
  private readonly spec = incidentSpec();

  protected readonly list = recordList<Incident>(this.spec, () =>
    this.incidentsApi.list().pipe(map((res) => res.data)),
  );

  protected readonly label = (i: Incident) => i.reference;

  protected readonly columns: Column<Incident>[] = [
    { label: 'Reference', kind: 'strong', value: (i) => i.reference },
    { label: 'Incident', value: (i) => i.kind, sub: (i) => i.place },
    { label: 'Driver', value: (i) => i.driver_name },
    { label: 'Vehicle', kind: 'muted', value: (i) => i.vehicle_plate },
    { label: 'Occurred', kind: 'num', value: (i) => fmt.dateTime(i.occurred_at) },
    { label: 'Status', kind: 'status', status: (i) => i.status },
  ];
}

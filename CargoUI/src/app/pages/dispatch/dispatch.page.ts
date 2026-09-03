import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

import { DispatchService } from '../../services/dispatch/dispatch.service';
import { DispatchRecord } from '../../models/dispatch/dispatch.model';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { fmt } from '../../shared/format';

/** Dispatch Monitoring — DESIGN.md section 5.1. */
@Component({
  selector: 'app-dispatch',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable],
  template: `
    <app-card heading="Dispatch records" icon="dispatch" hint="Time and location" [padded]="false">
      <app-data-table
        [columns]="columns"
        [rows]="rows()"
        [minWidth]="880"
        emptyIcon="dispatch"
        emptyTitle="Nothing dispatched yet"
        emptyBody="Units appear here the moment they leave a depot bay." />
    </app-card>
  `,
})
export class DispatchPage {
  private readonly dispatchApi = inject(DispatchService);

  protected readonly rows = toSignal(this.dispatchApi.list().pipe(map((r) => r.data)), {
    initialValue: null,
  });

  protected readonly columns: Column<DispatchRecord>[] = [
    { label: 'Reference', kind: 'strong', value: (d) => d.reference },
    { label: 'Vehicle', kind: 'muted', value: (d) => d.vehicle_plate },
    { label: 'Dispatched from', value: (d) => d.location },
    { label: 'Dispatched at', kind: 'num', value: (d) => fmt.dateTime(d.dispatched_at) },
    { label: 'Arrived at', kind: 'num', value: (d) => fmt.dateTime(d.arrived_at) },
    { label: 'Status', kind: 'status', status: (d) => d.status },
  ];
}

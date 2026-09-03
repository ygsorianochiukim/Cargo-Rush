import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

import { DeliveryService } from '../../services/delivery/delivery.service';
import { DeliveryLog } from '../../models/delivery/delivery.model';
import { StatusValue } from '../../models/shared/status.model';
import { statusLabel } from '../../shared/status';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { FilterBar, FilterOption } from '../../shared/filter-bar';
import { fmt } from '../../shared/format';

/** Delivery Logs — DESIGN.md section 5.1. */
@Component({
  selector: 'app-delivery-logs',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, FilterBar],
  template: `
    <app-filter-bar
      [options]="filters()"
      [selected]="filter()"
      (select)="filter.set($any($event))" />

    <app-card [padded]="false" class="mt-4 block">
      <app-data-table
        [columns]="columns"
        [rows]="rows()"
        [minWidth]="920"
        rowAction="Open delivery log"
        emptyIcon="clipboard"
        emptyTitle="No delivery logs match this filter"
        emptyBody="Logs appear here once a trip is dispatched." />
    </app-card>
  `,
})
export class DeliveryLogsPage {
  private readonly deliveryApi = inject(DeliveryService);

  private readonly all = toSignal(this.deliveryApi.list().pipe(map((r) => r.data)), {
    initialValue: null,
  });

  protected readonly filter = signal<StatusValue | 'all'>('all');

  protected readonly filters = computed<FilterOption[]>(() => {
    const rows = this.all() ?? [];
    const seen = [...new Set(rows.map((r) => r.status))];
    return [
      { value: 'all', label: 'All', count: rows.length },
      ...seen.map((s) => ({
        value: s,
        label: statusLabel(s),
        count: rows.filter((r) => r.status === s).length,
      })),
    ];
  });

  protected readonly rows = computed(() => {
    const rows = this.all();
    if (rows === null) return null;
    const f = this.filter();
    return f === 'all' ? rows : rows.filter((r) => r.status === f);
  });

  protected readonly columns: Column<DeliveryLog>[] = [
    { label: 'Reference', kind: 'strong', value: (l) => l.reference },
    { label: 'Customer', value: (l) => l.customer },
    { label: 'Driver', value: (l) => l.driver_name, sub: (l) => l.helper_name ?? 'No helper' },
    { label: 'Delivered', kind: 'num', value: (l) => fmt.dateTime(l.delivered_at) },
    { label: 'Proof of delivery', kind: 'muted', value: (l) => l.pod_ref ?? 'Not uploaded' },
    { label: 'Status', kind: 'status', status: (l) => l.status },
  ];
}

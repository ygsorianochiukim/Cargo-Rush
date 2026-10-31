import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { map, merge, tap } from 'rxjs';

import { MaintenanceJob } from '../../models/vehicle/vehicle.model';
import { maintenanceSpec } from '../../services/maintenance/maintenance.form';
import { MaintenanceService } from '../../services/maintenance/maintenance.service';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { fmt } from '../../shared/format';
import { ListToolbar } from '../../shared/list-toolbar';
import { RecordDialog } from '../../shared/record-dialog';
import { recordList } from '../../shared/record-list';
import { ErrorState } from '../../shared/states';

@Component({
  selector: 'app-maintenance',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, ListToolbar, ErrorState],
  template: `
    <div class="mb-4 grid gap-4 sm:grid-cols-2">
      <app-card>
        <p class="cr-meta">Spent on servicing</p>
        <p class="cr-num mt-2 text-[28px] leading-none font-semibold">{{ spent() }}</p>
        <p class="mt-1 text-[12px] text-cr-ink-muted">
          What the garages have billed, across every unit listed below.
        </p>
      </app-card>

      <app-card>
        <p class="cr-meta">Awaiting a figure</p>
        <p class="cr-num mt-2 text-[28px] leading-none font-semibold">{{ uncosted() }}</p>
        <p class="mt-1 text-[12px] text-cr-ink-muted">
          Jobs booked or done that nobody has put a cost on yet.
        </p>
      </app-card>
    </div>

    <app-list-toolbar
      [count]="list.rows()?.length ?? null"
      singular="service"
      plural="services"
      actionLabel="New service"
      (add)="list.create()" />

    <app-card [padded]="false">
      @if (list.error(); as message) {
        <app-error-state [message]="message" (retry)="list.refresh()" />
      } @else {
        <app-data-table
          [columns]="columns"
          [rows]="list.rows()"
          [minWidth]="1000"
          searchPlaceholder="Search by work, plate or reference…"
          rowAction="Edit service"
          [deletable]="true"
          [rowLabel]="label"
          emptyIcon="fleet"
          emptyTitle="No servicing filed yet"
          emptyBody="File what the workshop and the garages have done. The cost lands on that truck's own sheet, so Profitability shows what the unit really earned."
          (open)="list.edit($any($event))"
          (remove)="list.remove($any($event))" />
      }
    </app-card>
  `,
})
export class MaintenancePage {
  private readonly jobsApi = inject(MaintenanceService);
  private readonly dialog = inject(RecordDialog);
  private readonly spec = maintenanceSpec();

  private readonly costedTotal = signal<number | null>(null);

  protected readonly list = recordList<MaintenanceJob>(this.spec, () =>
    this.jobsApi.list().pipe(
      tap((res) => this.costedTotal.set((res.meta?.['costed_total_cents'] as number) ?? null)),
      map((res) => res.data),
    ),
  );

  constructor() {
    merge(this.dialog.savedFor(this.spec), this.dialog.deletedFor(this.spec))
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.list.refresh());
  }

  protected readonly spent = computed(() => {
    const total = this.costedTotal();

    return total === null ? '—' : fmt.pesos(total);
  });

  protected readonly uncosted = computed(() => {
    const rows = this.list.rows();

    if (rows === null) return '—';

    return String(rows.filter((job) => job.cost_cents === null).length);
  });

  protected readonly label = (job: MaintenanceJob) =>
    `${job.kind} on ${job.vehicle_plate ?? 'this unit'}`;

  protected readonly columns: Column<MaintenanceJob>[] = [
    {
      label: 'Truck',
      kind: 'strong',
      value: (job) => job.vehicle_plate,
      sub: (job) => job.kind,
    },
    {
      label: 'Due',
      kind: 'num',
      value: (job) => fmt.date(job.due_at),
      date: (job) => job.due_at,
    },
    {
      label: 'Done',
      kind: 'num',
      value: (job) => (job.completed_on === null ? null : fmt.date(job.completed_on)),
    },
    { label: 'Garage', value: (job) => job.supplier_name ?? null, sub: (job) => job.reference },
    {
      label: 'Cost',
      kind: 'num',
      value: (job) => (job.cost_cents === null ? null : fmt.pesos(job.cost_cents)),
    },
    {
      label: 'On the sheet',
      kind: 'muted',
      value: (job) =>
        job.cost_cents === null ? '—' : job.on_the_sheet ? 'Charged' : 'Needs a date done',
    },
    { label: 'Status', kind: 'status', status: (job) => job.status },
  ];
}

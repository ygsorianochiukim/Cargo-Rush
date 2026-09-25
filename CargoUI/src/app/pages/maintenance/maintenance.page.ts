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

/**
 * Truck Maintenance — what keeping the fleet on the road costs.
 *
 * Beside Other Expenses rather than under Fleet, because the two answer the
 * same question from the same desk: what did the firm spend, and on what. What
 * separates them is where the money lands, and that is the whole reason this is
 * a screen of its own.
 *
 *   **A service belongs to one truck.** It lands in that unit's Maintenance
 *   column on the daily sheet, which Profitability and the Quarterly Summary
 *   have always counted per unit. A truck that ate a gearbox should read as the
 *   truck that ate a gearbox.
 *
 *   **An expense belongs to the period.** Rice, tarpaulins, tolls, the office
 *   rent. Charging those to a unit would make the truck that happened to be out
 *   that day look unprofitable for the office's electricity.
 *
 * Filing an oil change on Other Expenses used to do the second to the first: the
 * fleet's service history sat inside a list of meals, where neither could be
 * found, and the money never reached the truck.
 *
 * ## Two figures at the top, and they are not the same number
 *
 * **Spent** is what the fleet has actually been charged — the jobs with a
 * figure on them. **Awaiting a figure** counts the jobs done or booked that
 * nobody has costed yet, which is the work sitting in an unopened envelope from
 * the garage. A screen that showed only the first would say the fleet had spent
 * nothing in a month where three trucks were in the workshop.
 */
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

  /**
   * The costed total, as the API totalled it.
   *
   * Taken from the response's meta rather than summed from the rows: the sum
   * belongs to the filter, and a client can only ever add up the page it was
   * handed. Kept in a signal the list refresh updates, so it moves with the
   * table rather than going stale behind it.
   */
  private readonly costedTotal = signal<number | null>(null);

  protected readonly list = recordList<MaintenanceJob>(this.spec, () =>
    this.jobsApi.list().pipe(
      tap((res) => this.costedTotal.set((res.meta?.['costed_total_cents'] as number) ?? null)),
      map((res) => res.data),
    ),
  );

  /**
   * Refetch after a save, which most list pages here deliberately do not.
   *
   * `recordList` patches the saved row into the table and skips the round trip,
   * because the API already handed the row back. That is right for a list and
   * wrong for a figure the list does not contain: the total above is the
   * server's answer about the whole filter, and putting a ₱12,500 clutch job on
   * the table without moving it would leave the two disagreeing on screen.
   *
   * It costs one request per save and does not flicker — `refresh()` replaces
   * the rows on success rather than blanking them first.
   */
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
    },
    {
      /**
       * The day the work was done, which is the day the money is charged to —
       * not the day it was due. A service booked for the 12th and done on the
       * 19th is the 19th's cost, and printing only the due date would leave
       * somebody looking for it in the wrong week of the sheet.
       */
      label: 'Done',
      kind: 'num',
      value: (job) => (job.completed_on === null ? null : fmt.date(job.completed_on)),
    },
    { label: 'Garage', value: (job) => job.supplier_name ?? null, sub: (job) => job.reference },
    {
      /**
       * Null prints as an em dash, and that is the point of keeping it null.
       *
       * "Not costed yet" and "cost nothing" are different facts — the second is
       * a warranty job — and ₱0 for both would hide the envelope nobody has
       * opened.
       */
      label: 'Cost',
      kind: 'num',
      value: (job) => (job.cost_cents === null ? null : fmt.pesos(job.cost_cents)),
    },
    {
      /**
       * Whether the figure has reached the unit's sheet.
       *
       * A costed job with no date done is charged to nothing, because there is
       * no day to charge it to. Saying so here is what stops somebody hunting
       * through Profitability for a number that was never going to be there.
       */
      label: 'On the sheet',
      kind: 'muted',
      value: (job) =>
        job.cost_cents === null ? '—' : job.on_the_sheet ? 'Charged' : 'Needs a date done',
    },
    { label: 'Status', kind: 'status', status: (job) => job.status },
  ];
}

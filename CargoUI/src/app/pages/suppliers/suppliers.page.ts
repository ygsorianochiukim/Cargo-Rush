import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { map } from 'rxjs';

import { Supplier } from '../../models/supplier/supplier.model';
import { supplierSpec } from '../../services/supplier/supplier.form';
import { SupplierService } from '../../services/supplier/supplier.service';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { fmt } from '../../shared/format';
import { ListToolbar } from '../../shared/list-toolbar';
import { recordList } from '../../shared/record-list';
import { ErrorState } from '../../shared/states';

/**
 * Suppliers — who the fleet buys from.
 *
 * The mirror of Customer Management, and it exists because `payee` was free
 * text. The same garage came out as "Davao Lubes", "Davao lubes & parts" and
 * "DAVAO LUBES" over a year, and "what do we spend there" could not be asked —
 * not answered badly, asked. A record with an id fixes the spelling and makes
 * the question possible.
 *
 * ## The three columns of spend
 *
 * An expense, a service and a bill all point at a supplier, and the total is
 * kept split across them rather than added into one figure. What kind of spend
 * it was is what tells a garage from a chandler: a garage's money is servicing,
 * a chandler's is consumables, and one number would hide that.
 *
 * ## Inactive rather than deleted
 *
 * An office that has stopped buying from somebody wants them out of the pickers
 * and their history kept. That is `status: inactive`, which is why the delete is
 * there but rarely the right button — and why deleting is a soft delete, so the
 * rows already filed against them still resolve.
 */
@Component({
  selector: 'app-suppliers',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, ListToolbar, ErrorState],
  template: `
    <app-list-toolbar
      [count]="list.rows()?.length ?? null"
      singular="supplier"
      plural="suppliers"
      actionLabel="New supplier"
      (add)="list.create()" />

    <app-card [padded]="false">
      @if (list.error(); as message) {
        <app-error-state [message]="message" (retry)="list.refresh()" />
      } @else {
        <app-data-table
          [columns]="columns"
          [rows]="list.rows()"
          [minWidth]="900"
          searchPlaceholder="Search suppliers…"
          rowAction="Edit supplier"
          [deletable]="true"
          [rowLabel]="label"
          emptyIcon="customers"
          emptyTitle="No suppliers yet"
          emptyBody="Add the garages, chandlers and canteens the fleet buys from. An expense, a service or a bill can then name one."
          (open)="list.edit($any($event))"
          (remove)="list.remove($any($event))" />
      }
    </app-card>
  `,
})
export class SuppliersPage {
  private readonly suppliersApi = inject(SupplierService);
  private readonly spec = supplierSpec();

  protected readonly list = recordList<Supplier>(this.spec, () =>
    this.suppliersApi.list().pipe(map((res) => res.data)),
  );

  protected readonly label = (s: Supplier) => s.name;

  protected readonly columns: Column<Supplier>[] = [
    { label: 'Supplier', kind: 'strong', value: (s) => s.name, sub: (s) => s.supplies },
    { label: 'Contact', value: (s) => s.contact },
    {
      label: 'Expenses',
      kind: 'num',
      value: (s) => (s.expense_spend_cents === null ? null : fmt.pesos(s.expense_spend_cents)),
    },
    {
      label: 'Servicing',
      kind: 'num',
      value: (s) => (s.service_spend_cents === null ? null : fmt.pesos(s.service_spend_cents)),
    },
    {
      label: 'Billed',
      kind: 'num',
      value: (s) => (s.billed_cents === null ? null : fmt.pesos(s.billed_cents)),
    },
    {
      label: 'Total spend',
      kind: 'strong',
      value: (s) => (s.spend_cents === null ? null : fmt.pesos(s.spend_cents)),
    },
    { label: 'Status', kind: 'status', status: (s) => s.status },
  ];
}

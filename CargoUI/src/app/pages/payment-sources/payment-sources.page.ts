import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { map } from 'rxjs';

import { PaymentSource } from '../../models/accounting/payment-source.model';
import { paymentSourceSpec } from '../../services/accounting/payment-source.form';
import { PaymentSourceService } from '../../services/accounting/payment-source.service';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { fmt } from '../../shared/format';
import { ListToolbar } from '../../shared/list-toolbar';
import { recordList } from '../../shared/record-list';
import { ErrorState } from '../../shared/states';

@Component({
  selector: 'app-payment-sources',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, ListToolbar, ErrorState],
  template: `
    <app-list-toolbar
      [count]="list.rows()?.length ?? null"
      singular="wallet or card"
      plural="wallets and cards"
      actionLabel="New wallet or card"
      (add)="list.create()"
    />

    <app-card [padded]="false">
      @if (list.error(); as message) {
        <app-error-state [message]="message" (retry)="list.refresh()" />
      } @else {
        <app-data-table
          [columns]="columns"
          [rows]="list.rows()"
          [minWidth]="860"
          searchPlaceholder="Search wallets and cards…"
          rowAction="Edit"
          [deletable]="true"
          [rowLabel]="label"
          emptyIcon="wallet"
          emptyTitle="No wallets or cards yet"
          emptyBody="Add cash, each company credit card and each fuel card. Expenses and fuel fills can then say what paid for them."
          (open)="list.edit($any($event))"
          (remove)="list.remove($any($event))"
        />
      }
    </app-card>
  `,
})
export class PaymentSourcesPage {
  private readonly sourcesApi = inject(PaymentSourceService);
  private readonly spec = paymentSourceSpec();

  protected readonly list = recordList<PaymentSource>(this.spec, () =>
    this.sourcesApi.list().pipe(map((res) => res.data)),
  );

  protected readonly label = (s: PaymentSource) => s.label;

  private readonly money = (cents: number | null) => (cents === null ? null : fmt.pesos(cents));

  protected readonly columns: Column<PaymentSource>[] = [
    { label: 'Name', kind: 'strong', value: (s) => s.label, sub: (s) => s.note },
    { label: 'Type', value: (s) => s.kind_label, sub: (s) => s.provider },
    { label: 'Expenses', kind: 'num', value: (s) => this.money(s.expense_spend_cents) },
    { label: 'Fuel', kind: 'num', value: (s) => this.money(s.fuel_spend_cents) },
    { label: 'Total paid', kind: 'strong', value: (s) => this.money(s.spend_cents) },
    { label: 'Status', kind: 'status', status: (s) => s.status },
  ];
}

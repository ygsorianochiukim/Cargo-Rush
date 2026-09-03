import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

import { Customer } from '../../models/customer/customer.model';
import { customerSpec } from '../../services/customer/customer.form';
import { CustomerService } from '../../services/customer/customer.service';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { ListToolbar } from '../../shared/list-toolbar';
import { RecordDialog } from '../../shared/record-dialog';
import { recordList } from '../../shared/record-list';
import { ErrorState } from '../../shared/states';

/** Customer Management — DESIGN.md section 5.1. */
@Component({
  selector: 'app-customers',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, Icon, ListToolbar, ErrorState],
  template: `
    <app-list-toolbar
      [count]="list.rows()?.length ?? null"
      singular="customer"
      plural="customers"
      actionLabel="New customer"
      (add)="list.create()" />

    <!-- The starting password, once. The API says it in the reply to the form
         and never again, so if this is dismissed unread the desk has to look it
         up in the install's configuration. -->
    @if (created(); as login) {
      <div
        class="mb-4 flex items-start gap-3 rounded-card bg-cr-surface p-4 shadow-card
               ring-1 ring-cr-success/40"
        role="status">
        <app-icon name="profile" [size]="20" class="mt-0.5 flex-none text-cr-success" />

        <div class="min-w-0 flex-1">
          <p class="text-[14px] font-semibold">{{ login.name }} can now sign in</p>
          <p class="mt-1 text-[13px] text-cr-ink-muted">
            Address <span class="font-semibold text-cr-ink">{{ login.login_email }}</span>, starting
            password <span class="font-semibold text-cr-ink">{{ login.default_password }}</span>.
            Pass these on, and ask them to change the password once they are in.
          </p>
        </div>

        <button
          type="button"
          class="flex h-8 flex-none items-center rounded-control px-2 text-[13px]
                 font-semibold text-cr-ink-muted transition-colors hover:bg-cr-tint"
          (click)="created.set(null)">
          Dismiss
        </button>
      </div>
    }

    <app-card [padded]="false">
      @if (list.error(); as message) {
        <app-error-state [message]="message" (retry)="list.refresh()" />
      } @else {
        <app-data-table
          [columns]="columns"
          [rows]="list.rows()"
          [minWidth]="1000"
          rowAction="Edit customer"
          [deletable]="true"
          [rowLabel]="label"
          emptyIcon="customers"
          emptyTitle="No customers yet"
          emptyBody="Add the accounts you haul for. A trip can name one, and billing works from it."
          (open)="list.edit($any($event))"
          (remove)="list.remove($any($event))" />
      }
    </app-card>
  `,
})
export class CustomersPage {
  private readonly customersApi = inject(CustomerService);
  private readonly dialog = inject(RecordDialog);
  private readonly spec = customerSpec();

  protected readonly list = recordList<Customer>(this.spec, () =>
    this.customersApi.list().pipe(map((res) => res.data)),
  );

  /** The customer whose login was just created, while the notice is up. */
  protected readonly created = signal<Customer | null>(null);

  constructor() {
    // Only a save that actually made an account carries a password, so this
    // stays quiet for an edit, and for a customer the office gave no address.
    this.dialog
      .savedFor(this.spec)
      .pipe(takeUntilDestroyed())
      .subscribe((saved) => {
        if (saved.default_password) this.created.set(saved);
      });
  }

  protected readonly label = (c: Customer) => c.name;

  protected readonly columns: Column<Customer>[] = [
    { label: 'Customer', kind: 'strong', value: (c) => c.name, sub: (c) => c.contact },
    { label: 'Trips', kind: 'num', value: (c) => c.trips_total },
    {
      label: 'Outstanding',
      kind: 'num',
      value: (c) =>
        c.outstanding_cents === 0 ? 'Settled' : fmt.money(c.outstanding_cents, c.currency),
    },
    // Whether the firm can file its own requests. Invisible from the office
    // otherwise, and the first thing to check when one says it cannot get in.
    { label: 'Portal login', value: (c) => c.login_email ?? 'None' },
    { label: 'Feedback', kind: 'num', value: (c) => `${c.rating.toFixed(1)} / 5` },
    { label: 'Status', kind: 'status', status: (c) => c.status },
  ];
}

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import { PaymentSourceService } from '../../services/accounting/payment-source.service';
import { DeliveryService } from '../../services/delivery/delivery.service';
import { IdentityService } from '../../services/identity/identity.service';
import { DeliveryLog } from '../../models/delivery/delivery.model';
import { StatusValue } from '../../models/shared/status.model';
import { statusLabel } from '../../shared/status';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { Field } from '../../shared/field';
import { FilterBar, FilterOption } from '../../shared/filter-bar';
import { fmt } from '../../shared/format';
import { Modal } from '../../shared/modal';

@Component({
  selector: 'app-delivery-logs',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, Field, FilterBar, Modal, ReactiveFormsModule],
  template: `
    <app-filter-bar
      [options]="filters()"
      [selected]="filter()"
      (select)="filter.set($any($event))" />

    <app-card [padded]="false" class="mt-4 block">
      <app-data-table
        [columns]="columns"
        [rows]="rows()"
        [minWidth]="1080"
        [rowAction]="canEncode() ? 'Encode fuel' : null"
        emptyIcon="clipboard"
        emptyTitle="No delivery logs match this filter"
        emptyBody="Logs appear here once a trip is dispatched."
        (open)="openFuel($event)" />
    </app-card>

    <app-modal
      [(open)]="fuelOpen"
      title="Fuel for this trip"
      [subtitle]="editing()?.reference ?? undefined"
      icon="fuel"
      size="sm"
      [locked]="saving()">
      <form [formGroup]="form" class="space-y-4">
        <app-field label="Fuel consumed (litres)" [error]="errorFor('litres')">
          <input
            type="number"
            min="0"
            step="0.01"
            formControlName="litres"
            placeholder="0.00"
            [class]="inputClass + ' cr-num'" />
        </app-field>
        <app-field label="Fuel expense (₱)" [error]="errorFor('cost')">
          <input
            type="number"
            min="0"
            step="0.01"
            formControlName="cost"
            placeholder="0.00"
            [class]="inputClass + ' cr-num'" />
        </app-field>
        <app-field label="Paid with">
          <select formControlName="source" [class]="inputClass">
            <option value="">Select…</option>
            @for (option of wallets(); track option.value) {
              <option [value]="option.value">{{ option.label }}</option>
            }
          </select>
        </app-field>
      </form>

      @if (failure(); as message) {
        <p class="mt-3 text-[12px] text-cr-red" role="alert">{{ message }}</p>
      }

      <ng-container modal-footer>
        <button
          type="button"
          class="h-10 rounded-control px-4 text-[14px] font-semibold text-cr-ink transition-colors hover:bg-cr-tint disabled:opacity-50"
          [disabled]="saving()"
          (click)="fuelOpen.set(false)">
          Cancel
        </button>
        <button
          type="button"
          class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
          [disabled]="saving()"
          (click)="saveFuel()">
          {{ saving() ? 'Saving…' : 'Save fuel' }}
        </button>
      </ng-container>
    </app-modal>
  `,
})
export class DeliveryLogsPage {
  private readonly deliveryApi = inject(DeliveryService);
  private readonly identity = inject(IdentityService);
  private readonly fb = inject(FormBuilder);
  private readonly sources = inject(PaymentSourceService);

  private readonly all = signal<DeliveryLog[] | null>(null);

  protected readonly filter = signal<StatusValue | 'all'>('all');

  protected readonly canEncode = computed(() => this.identity.can('delivery.write'));

  protected readonly fuelOpen = signal(false);
  protected readonly editing = signal<DeliveryLog | null>(null);
  protected readonly saving = signal(false);
  protected readonly failure = signal<string | null>(null);

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly form = this.fb.nonNullable.group({
    litres: ['', Validators.min(0)],
    cost: ['', Validators.min(0)],
    source: [''],
  });

  protected readonly wallets = signal<{ value: string; label: string }[]>([]);

  constructor() {
    this.deliveryApi.list().subscribe((r) => this.all.set(r.data));

    if (this.canEncode()) {
      this.sources.options().subscribe({ next: (rows) => this.wallets.set(rows), error: () => {} });
    }
  }

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
    { label: 'Driver', value: (l) => l.driver_name, sub: (l) => (l.helper_names.length > 0 ? l.helper_names.join(', ') : 'No helper') },
    {
      label: 'Delivered',
      kind: 'num',
      value: (l) => fmt.dateTime(l.delivered_at),
      date: (l) => l.delivered_at,
    },
    { label: 'Proof of delivery', kind: 'muted', value: (l) => l.pod_ref ?? 'Not uploaded' },
    { label: 'Fuel used', kind: 'num', value: (l) => (l.fuel_litres === null ? '—' : `${l.fuel_litres} L`) },
    {
      label: 'Fuel expense',
      kind: 'num',
      value: (l) => (l.fuel_cost_cents === null ? '—' : fmt.money(l.fuel_cost_cents, l.currency ?? undefined)),
      sub: (l) => l.payment_source_label,
    },
    { label: 'Status', kind: 'status', status: (l) => l.status },
  ];

  protected openFuel(log: DeliveryLog): void {
    if (!this.canEncode()) return;

    this.editing.set(log);
    this.failure.set(
      log.status === 'delivered' ? null : 'Fuel can be encoded once this trip has been delivered.',
    );
    this.form.reset({
      litres: log.fuel_litres === null ? '' : String(log.fuel_litres),
      cost: log.fuel_cost_cents === null ? '' : String(log.fuel_cost_cents / 100),
      source: log.payment_source_id ?? '',
    });
    this.fuelOpen.set(true);
  }

  protected errorFor(name: 'litres' | 'cost'): string | null {
    const c = this.form.controls[name];
    if (c.valid || !(c.touched || c.dirty)) return null;
    return 'Cannot be negative.';
  }

  protected saveFuel(): void {
    const log = this.editing();
    if (log === null) return;

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const raw = this.form.getRawValue();
    const litres = String(raw.litres ?? '').trim();
    const cost = String(raw.cost ?? '').trim();

    this.saving.set(true);
    this.failure.set(null);

    this.deliveryApi
      .encodeFuel(log.id, {
        fuel_litres: litres === '' ? null : Number(litres),
        fuel_cost_cents: cost === '' ? null : Math.round(Number(cost) * 100),
        payment_source_id: raw.source || null,
      })
      .subscribe({
        next: (saved) => {
          this.saving.set(false);
          this.all.update((rows) => rows?.map((r) => (r.id === saved.id ? saved : r)) ?? null);
          this.fuelOpen.set(false);
        },
        error: (error: HttpErrorResponse) => {
          this.saving.set(false);
          this.failure.set(
            error.error?.errors?.fuel_litres?.[0] ??
              error.error?.message ??
              `The server refused that request (${error.status}).`,
          );
        },
      });
  }
}

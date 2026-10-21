import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { map } from 'rxjs';

import { Asset, AssetTurnover, Custodian } from '../../models/asset/asset.model';
import { StatusValue } from '../../models/shared/status.model';
import { assetSpec } from '../../services/asset/asset.form';
import { AssetService } from '../../services/asset/asset.service';
import { IdentityService } from '../../services/identity/identity.service';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { fmt } from '../../shared/format';
import { ListToolbar } from '../../shared/list-toolbar';
import { Modal } from '../../shared/modal';
import { recordList } from '../../shared/record-list';
import { ErrorState } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';
import { AssetTurnoverDialog } from './asset-turnover-dialog';

const FILTERS: { key: StatusValue | 'all'; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'assigned', label: 'Issued' },
  { key: 'available', label: 'In stock' },
  { key: 'maintenance', label: 'Under repair' },
  { key: 'inactive', label: 'Retired / lost' },
];

const CONDITION_LABEL: Record<string, string> = { good: 'Good', fair: 'Fair', damaged: 'Damaged' };

@Component({
  selector: 'app-assets',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, ListToolbar, ErrorState, Modal, StatusPill, AssetTurnoverDialog],
  template: `
    <app-list-toolbar
      [count]="shown()?.length ?? null"
      singular="asset"
      plural="assets"
      actionLabel="New asset"
      (add)="list.create()"
    />

    <div class="mb-4 flex flex-wrap gap-2" role="tablist" aria-label="Filter by status">
      @for (f of filters; track f.key) {
        <button
          type="button"
          role="tab"
          class="h-9 rounded-control border px-3 text-[13px] font-semibold transition-colors"
          [class]="
            f.key === filter()
              ? 'border-cr-blue bg-cr-tint text-cr-blue'
              : 'border-cr-line text-cr-ink-muted hover:bg-cr-tint'
          "
          [attr.aria-selected]="f.key === filter()"
          (click)="filter.set(f.key)"
        >
          {{ f.label }} <span class="cr-num ml-1">{{ counts()[f.key] ?? 0 }}</span>
        </button>
      }
    </div>

    <app-card [padded]="false">
      @if (list.error(); as message) {
        <app-error-state [message]="message" (retry)="list.refresh()" />
      } @else {
        <app-data-table
          [columns]="columns"
          [rows]="shown()"
          [minWidth]="960"
          searchPlaceholder="Search tag, item, serial or location…"
          rowAction="Open asset"
          [deletable]="canManage"
          [rowLabel]="label"
          emptyIcon="clipboard"
          emptyTitle="No assets yet"
          emptyBody="Register the laptops, radios, tools and furniture the company owns. Each one records when it was bought and who holds it now."
          (open)="openAsset($any($event))"
          (remove)="list.remove($any($event))"
        />
      }
    </app-card>

    @if (selected(); as asset) {
      <app-modal
        [open]="true"
        [title]="asset.name"
        [subtitle]="asset.tag ?? 'No tag'"
        icon="clipboard"
        size="md"
        (closed)="selected.set(null)"
      >
        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-[13px]">
          <div>
            <dt class="cr-meta">HELD BY</dt>
            <dd class="mt-0.5 font-semibold">{{ asset.custodian_name ?? 'Nobody — in stock' }}</dd>
            @if (asset.assigned_on) {
              <dd class="text-cr-ink-muted">since {{ date(asset.assigned_on) }}</dd>
            }
          </div>
          <div>
            <dt class="cr-meta">STATUS</dt>
            <dd class="mt-1"><app-status-pill [status]="asset.status" /></dd>
          </div>
          <div>
            <dt class="cr-meta">PURCHASED</dt>
            <dd class="mt-0.5">{{ asset.purchased_on ? date(asset.purchased_on) : '—' }}</dd>
            @if (asset.cost_cents !== null) {
              <dd class="cr-num text-cr-ink-muted">{{ money(asset.cost_cents) }}</dd>
            }
          </div>
          <div>
            <dt class="cr-meta">BOUGHT FROM</dt>
            <dd class="mt-0.5">{{ asset.supplier_name ?? '—' }}</dd>
          </div>
          <div>
            <dt class="cr-meta">CATEGORY</dt>
            <dd class="mt-0.5">{{ asset.category_name ?? '—' }}</dd>
          </div>
          <div>
            <dt class="cr-meta">CONDITION</dt>
            <dd class="mt-0.5">{{ condition(asset.condition) }}</dd>
          </div>
          <div>
            <dt class="cr-meta">SERIAL NO.</dt>
            <dd class="mt-0.5">{{ asset.serial_no ?? '—' }}</dd>
          </div>
          <div>
            <dt class="cr-meta">LOCATION</dt>
            <dd class="mt-0.5">{{ asset.location ?? '—' }}</dd>
          </div>
          @if (asset.note) {
            <div class="col-span-2">
              <dt class="cr-meta">NOTE</dt>
              <dd class="mt-0.5">{{ asset.note }}</dd>
            </div>
          }
        </dl>

        <h3 class="cr-meta mt-6">TURNOVER HISTORY</h3>
        @if (history() === null) {
          <p class="mt-2 text-[13px] text-cr-ink-muted">Loading…</p>
        } @else if (history()!.length === 0) {
          <p class="mt-2 text-[13px] text-cr-ink-muted">
            Never handed over. It has stayed in stock since it was registered.
          </p>
        } @else {
          <ol class="mt-2 divide-y divide-cr-line rounded-control border border-cr-line">
            @for (row of history(); track row.id) {
              <li class="px-3 py-2 text-[13px]">
                <div class="flex items-baseline justify-between gap-3">
                  <span class="font-semibold">
                    {{ row.from_name ?? 'Stock' }} → {{ row.to_name ?? 'Stock' }}
                  </span>
                  <span class="cr-num flex-none text-cr-ink-muted">{{
                    date(row.turned_over_on)
                  }}</span>
                </div>
                <p class="text-[12px] text-cr-ink-muted">
                  {{ condition(row.condition) }} condition
                  @if (row.recorded_by_name) {
                    · recorded by {{ row.recorded_by_name }}
                  }
                </p>
                @if (row.note) {
                  <p class="mt-0.5 text-[12px]">{{ row.note }}</p>
                }
              </li>
            }
          </ol>
        }

        @if (canManage) {
          <ng-container modal-footer>
            <button
              type="button"
              class="h-10 rounded-control px-4 text-[14px] font-semibold text-cr-ink transition-colors hover:bg-cr-tint"
              (click)="edit(asset)"
            >
              Edit details
            </button>
            <button
              type="button"
              class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover"
              (click)="turning.set(asset)"
            >
              {{ asset.custodian_id ? 'Turn over / return' : 'Issue to someone' }}
            </button>
          </ng-container>
        }
      </app-modal>
    }

    @if (turning(); as asset) {
      <app-asset-turnover-dialog
        [asset]="asset"
        [people]="people()"
        (saved)="turnedOver($event)"
        (closed)="turning.set(null)"
      />
    }
  `,
})
export class AssetsPage {
  private readonly assetsApi = inject(AssetService);
  private readonly identity = inject(IdentityService);
  private readonly spec = assetSpec();

  protected readonly canManage = this.identity.has('assets.manage');
  protected readonly filters = FILTERS;

  protected readonly list = recordList<Asset>(this.spec, () =>
    this.assetsApi.list({ per_page: 100 }).pipe(map((res) => res.data)),
  );

  protected readonly filter = signal<StatusValue | 'all'>('all');
  protected readonly selected = signal<Asset | null>(null);
  protected readonly turning = signal<Asset | null>(null);
  protected readonly history = signal<AssetTurnover[] | null>(null);
  protected readonly people = signal<Custodian[]>([]);

  protected readonly shown = computed(() => {
    const rows = this.list.rows();
    const filter = this.filter();

    return rows === null || filter === 'all' ? rows : rows.filter((a) => a.status === filter);
  });

  protected readonly counts = computed(() => {
    const counts: Partial<Record<string, number>> = { all: 0 };

    for (const asset of this.list.rows() ?? []) {
      counts['all'] = (counts['all'] ?? 0) + 1;
      counts[asset.status] = (counts[asset.status] ?? 0) + 1;
    }

    return counts;
  });

  protected readonly label = (a: Asset) => (a.tag ? `${a.tag} · ${a.name}` : a.name);

  protected readonly columns: Column<Asset>[] = [
    { label: 'Item', kind: 'strong', value: (a) => a.name, sub: (a) => a.tag },
    { label: 'Category', value: (a) => a.category_name },
    {
      label: 'Held by',
      value: (a) => a.custodian_name ?? 'In stock',
      sub: (a) => (a.assigned_on ? `since ${fmt.date(a.assigned_on)}` : a.location),
    },
    {
      label: 'Purchased',
      value: (a) => (a.purchased_on ? fmt.date(a.purchased_on) : null),
      sub: (a) => a.supplier_name,
    },
    {
      label: 'Cost',
      kind: 'num',
      value: (a) => (a.cost_cents === null ? null : fmt.pesos(a.cost_cents)),
    },
    { label: 'Condition', value: (a) => CONDITION_LABEL[a.condition] ?? a.condition },
    { label: 'Status', kind: 'status', status: (a) => a.status },
  ];

  constructor() {
    if (this.canManage) {
      this.assetsApi.custodians().subscribe((rows) => this.people.set(rows));
    }
  }

  protected openAsset(asset: Asset): void {
    this.selected.set(asset);
    this.history.set(null);
    this.assetsApi.turnovers(asset.id).subscribe({
      next: (rows) => this.history.set(rows),
      error: () => this.history.set([]),
    });
  }

  protected edit(asset: Asset): void {
    this.selected.set(null);
    this.list.edit(asset);
  }

  protected turnedOver(asset: Asset): void {
    this.turning.set(null);
    this.list.refresh();
    this.openAsset(asset);
  }

  protected date(value: string): string {
    return fmt.date(value);
  }

  protected money(cents: number): string {
    return fmt.pesos(cents);
  }

  protected condition(value: string): string {
    return CONDITION_LABEL[value] ?? value;
  }
}

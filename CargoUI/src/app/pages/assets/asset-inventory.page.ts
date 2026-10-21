import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';

import { AssetCategoryLine, AssetReport } from '../../models/asset/asset.model';
import { AssetService } from '../../services/asset/asset.service';
import { BarRow, BarRows } from '../../shared/bar-rows';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { fmt } from '../../shared/format';
import { ErrorState } from '../../shared/states';
import { AssetStats } from './asset-stats';

const CONDITION_LABEL: Record<string, string> = { good: 'Good', fair: 'Fair', damaged: 'Damaged' };

@Component({
  selector: 'app-asset-inventory',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [AssetStats, BarRows, Card, DataTable, ErrorState],
  template: `
    @if (failed()) {
      <app-card>
        <app-error-state
          message="Could not load the inventory. Check the connection and try again."
          (retry)="load()"
        />
      </app-card>
    } @else if (report(); as r) {
      <app-asset-stats [totals]="r.totals" />

      <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
          <app-card heading="Stock by category" icon="shipments" [padded]="false">
            <app-data-table
              [columns]="columns"
              [rows]="r.by_category"
              [minWidth]="720"
              [searchable]="false"
              [paginated]="false"
              emptyIcon="shipments"
              emptyTitle="Nothing on the register yet"
              emptyBody="Register assets on Asset List and the stock count builds itself here."
            />
          </app-card>
        </div>

        <app-card heading="Condition" icon="gauge" hint="Every item on the register">
          <app-bar-rows [bars]="conditionBars()" align="top" />
        </app-card>
      </div>
    } @else {
      <p class="cr-meta">Loading inventory…</p>
    }
  `,
})
export class AssetInventoryPage {
  private readonly assets = inject(AssetService);

  protected readonly report = signal<AssetReport | null>(null);
  protected readonly failed = signal(false);

  protected readonly columns: Column<AssetCategoryLine>[] = [
    { label: 'Category', kind: 'strong', value: (c) => c.name },
    { label: 'Items', kind: 'num', value: (c) => c.count },
    { label: 'Issued', kind: 'num', value: (c) => c.assigned },
    { label: 'In stock', kind: 'num', value: (c) => c.available },
    { label: 'Under repair', kind: 'num', value: (c) => c.maintenance },
    { label: 'Retired / lost', kind: 'num', value: (c) => c.inactive },
    { label: 'Value', kind: 'strong', value: (c) => fmt.pesos(c.cost_cents) },
  ];

  protected readonly conditionBars = computed<BarRow[]>(() => {
    const r = this.report();
    if (!r) return [];

    const top = Math.max(1, ...r.by_condition.map((b) => b.count));

    return r.by_condition.map((b) => ({
      key: b.key,
      label: CONDITION_LABEL[b.key] ?? b.key,
      value: `${b.count} item${b.count === 1 ? '' : 's'}`,
      pct: (b.count / top) * 100,
      tone: b.key === 'good' ? 'income' : 'expense',
      rows: [{ label: 'Value', value: fmt.pesos(b.cost_cents) }],
    }));
  });

  constructor() {
    this.load();
  }

  protected load(): void {
    this.failed.set(false);
    this.assets.report().subscribe({
      next: (r) => this.report.set(r),
      error: () => this.failed.set(true),
    });
  }
}

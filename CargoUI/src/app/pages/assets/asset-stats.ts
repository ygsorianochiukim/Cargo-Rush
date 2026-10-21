import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

import { AssetTally } from '../../models/asset/asset.model';
import { fmt } from '../../shared/format';

@Component({
  selector: 'app-asset-stats',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
      @for (tile of tiles(); track tile.label) {
        <div class="rounded-card bg-cr-surface p-4 shadow-card ring-1 ring-cr-line/60">
          <p class="cr-meta">{{ tile.label }}</p>
          <p class="cr-num mt-2 text-[24px] leading-none font-semibold" [class]="tile.tone">
            {{ tile.value }}
          </p>
          <p class="mt-1 text-[12px] text-cr-ink-muted">{{ tile.sub }}</p>
        </div>
      }
    </div>
  `,
})
export class AssetStats {
  readonly totals = input.required<AssetTally>();

  protected readonly tiles = computed(() => {
    const t = this.totals();
    const share = (n: number) =>
      t.count === 0 ? '—' : `${Math.round((n / t.count) * 100)}% of items`;

    return [
      { label: 'ITEMS', value: String(t.count), sub: 'on the register', tone: '' },
      { label: 'TOTAL VALUE', value: fmt.pesos(t.cost_cents), sub: 'purchase cost', tone: '' },
      { label: 'ISSUED', value: String(t.assigned), sub: share(t.assigned), tone: 'text-cr-blue' },
      {
        label: 'IN STOCK',
        value: String(t.available),
        sub: share(t.available),
        tone: 'text-cr-success',
      },
      {
        label: 'UNDER REPAIR',
        value: String(t.maintenance),
        sub: share(t.maintenance),
        tone: 'text-cr-warning',
      },
      {
        label: 'RETIRED / LOST',
        value: String(t.inactive),
        sub: share(t.inactive),
        tone: 'text-cr-red',
      },
    ];
  });
}

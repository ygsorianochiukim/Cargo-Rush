import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';

import { AssetReport } from '../../models/asset/asset.model';
import { AssetService } from '../../services/asset/asset.service';
import { BarRow, BarRows } from '../../shared/bar-rows';
import { Card } from '../../shared/card';
import { fmt } from '../../shared/format';
import { FileDelivery, deliverFile } from '../../shared/save-file';
import { ErrorState } from '../../shared/states';
import { AssetStats } from './asset-stats';

const CONDITION_LABEL: Record<string, string> = { good: 'Good', fair: 'Fair', damaged: 'Damaged' };

@Component({
  selector: 'app-asset-report',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [AssetStats, BarRows, Card, ErrorState],
  template: `
    <div class="mb-4 flex items-center justify-between gap-3">
      <p class="text-[13px] text-cr-ink-muted">
        As of {{ today }}. Retired and lost items are included at cost.
      </p>
      <div class="flex flex-none items-center gap-2 print:hidden">
        <button
          type="button"
          class="h-10 rounded-control border border-cr-line bg-cr-surface px-4 text-[14px] font-semibold hover:bg-cr-tint"
          (click)="print()"
        >
          Print
        </button>
        <button
          type="button"
          class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
          [disabled]="saving()"
          (click)="savePdf()"
        >
          {{ saving() ? 'Preparing PDF…' : 'Save as PDF' }}
        </button>
      </div>
    </div>

    @if (pdfError(); as message) {
      <p role="alert" class="mb-3 text-[12px] font-medium text-cr-red print:hidden">
        {{ message }}
      </p>
    }

    @if (failed()) {
      <app-card>
        <app-error-state message="Could not load the report." (retry)="load()" />
      </app-card>
    } @else if (report(); as r) {
      <app-asset-stats [totals]="r.totals" />

      <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <app-card heading="Value by category" icon="tag">
          @if (categoryBars().length === 0) {
            <p class="cr-meta">Nothing on the register yet.</p>
          } @else {
            <app-bar-rows [bars]="categoryBars()" align="top" />
          }
        </app-card>

        <app-card heading="Age of assets" icon="calendar" hint="Years since purchase">
          <app-bar-rows [bars]="ageBars()" align="top" />
        </app-card>

        <app-card heading="Purchases by year" icon="trend" [padded]="false">
          <table class="w-full text-[13px]">
            <thead>
              <tr class="border-b border-cr-line text-left">
                <th class="cr-meta px-4 py-2 font-normal">YEAR</th>
                <th class="cr-meta px-4 py-2 text-right font-normal">ITEMS</th>
                <th class="cr-meta px-4 py-2 text-right font-normal">SPENT</th>
              </tr>
            </thead>
            <tbody>
              @for (y of r.by_year; track y.year) {
                <tr class="border-b border-cr-line last:border-0">
                  <td class="cr-num px-4 py-2 font-semibold">{{ y.year }}</td>
                  <td class="cr-num px-4 py-2 text-right">{{ y.count }}</td>
                  <td class="cr-num px-4 py-2 text-right">{{ money(y.cost_cents) }}</td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="3" class="px-4 py-3 text-cr-ink-muted">
                    No purchase dates recorded.
                  </td>
                </tr>
              }
            </tbody>
          </table>
        </app-card>

        <app-card heading="Condition" icon="gauge">
          <app-bar-rows [bars]="conditionBars()" align="top" />
        </app-card>

        <div class="lg:col-span-2">
          <app-card heading="Accountability by custodian" icon="profile" [padded]="false">
            <table class="w-full text-[13px]">
              <thead>
                <tr class="border-b border-cr-line text-left">
                  <th class="cr-meta px-4 py-2 font-normal">CUSTODIAN</th>
                  <th class="cr-meta px-4 py-2 font-normal">POSITION</th>
                  <th class="cr-meta px-4 py-2 text-right font-normal">ITEMS</th>
                  <th class="cr-meta px-4 py-2 text-right font-normal">DAMAGED</th>
                  <th class="cr-meta px-4 py-2 text-right font-normal">VALUE HELD</th>
                </tr>
              </thead>
              <tbody>
                @for (p of r.by_custodian; track p.employee_id) {
                  <tr class="border-b border-cr-line last:border-0">
                    <td class="px-4 py-2 font-semibold">{{ p.name }}</td>
                    <td class="px-4 py-2 text-cr-ink-muted">{{ p.position ?? '—' }}</td>
                    <td class="cr-num px-4 py-2 text-right">{{ p.count }}</td>
                    <td class="cr-num px-4 py-2 text-right" [class.text-cr-red]="p.damaged > 0">
                      {{ p.damaged }}
                    </td>
                    <td class="cr-num px-4 py-2 text-right">{{ money(p.cost_cents) }}</td>
                  </tr>
                } @empty {
                  <tr>
                    <td colspan="5" class="px-4 py-3 text-cr-ink-muted">Nobody holds an asset.</td>
                  </tr>
                }
              </tbody>
            </table>
          </app-card>
        </div>
      </div>
    } @else {
      <p class="cr-meta">Loading report…</p>
    }
  `,
})
export class AssetReportPage {
  private readonly assets = inject(AssetService);

  protected readonly today = fmt.date(new Date().toLocaleDateString('en-CA'));
  protected readonly report = signal<AssetReport | null>(null);
  protected readonly failed = signal(false);
  protected readonly saving = signal(false);
  protected readonly pdfError = signal<string | null>(null);

  protected readonly categoryBars = computed<BarRow[]>(() => {
    const rows = this.report()?.by_category ?? [];
    const top = Math.max(1, ...rows.map((c) => c.cost_cents));

    return rows.map((c) => ({
      key: c.category_id ?? 'none',
      label: c.name,
      value: fmt.pesos(c.cost_cents),
      pct: (c.cost_cents / top) * 100,
      tone: 'income',
      rows: [
        { label: 'Items', value: String(c.count) },
        { label: 'Issued', value: String(c.assigned) },
        { label: 'In stock', value: String(c.available) },
      ],
    }));
  });

  protected readonly ageBars = computed<BarRow[]>(() => this.bars(this.report()?.by_age ?? []));

  protected readonly conditionBars = computed<BarRow[]>(() =>
    this.bars(
      (this.report()?.by_condition ?? []).map((b) => ({
        ...b,
        label: CONDITION_LABEL[b.key] ?? b.key,
      })),
    ),
  );

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

  private bars(
    rows: { key: string; label?: string; count: number; cost_cents: number }[],
  ): BarRow[] {
    const top = Math.max(1, ...rows.map((b) => b.count));

    return rows.map((b) => ({
      key: b.key,
      label: b.label ?? b.key,
      value: `${b.count} item${b.count === 1 ? '' : 's'}`,
      pct: (b.count / top) * 100,
      tone: b.key === 'damaged' || b.key === 'over-5' ? 'expense' : 'income',
      rows: [{ label: 'Value', value: fmt.pesos(b.cost_cents) }],
    }));
  }

  protected money(cents: number): string {
    return fmt.pesos(cents);
  }

  protected print(): void {
    this.savePdf('print');
  }

  protected savePdf(mode: FileDelivery = 'save'): void {
    this.saving.set(true);
    this.pdfError.set(null);

    this.assets.reportPdf().subscribe({
      next: (blob) => {
        deliverFile(mode, blob, `asset-report-${new Date().toLocaleDateString('en-CA')}.pdf`);
        this.saving.set(false);
      },
      error: () => {
        this.saving.set(false);
        this.pdfError.set('Could not prepare the PDF. Check the connection and try again.');
      },
    });
  }
}

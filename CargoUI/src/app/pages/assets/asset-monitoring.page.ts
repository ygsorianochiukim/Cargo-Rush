import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';

import { Asset, AssetCustodianLine, AssetReport, Custodian } from '../../models/asset/asset.model';
import { AssetService } from '../../services/asset/asset.service';
import { IdentityService } from '../../services/identity/identity.service';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { fmt } from '../../shared/format';
import { Modal } from '../../shared/modal';
import { ErrorState } from '../../shared/states';
import { AssetTurnoverDialog } from './asset-turnover-dialog';

const CONDITION_LABEL: Record<string, string> = { good: 'Good', fair: 'Fair', damaged: 'Damaged' };

@Component({
  selector: 'app-asset-monitoring',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [AssetTurnoverDialog, Card, DataTable, ErrorState, Modal],
  template: `
    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
      @for (tile of tiles(); track tile.label) {
        <div class="rounded-card bg-cr-surface p-4 shadow-card ring-1 ring-cr-line/60">
          <p class="cr-meta">{{ tile.label }}</p>
          <p class="cr-num mt-2 text-[24px] leading-none font-semibold">{{ tile.value }}</p>
        </div>
      }
    </div>

    <app-card heading="Who holds what" icon="profile" [padded]="false">
      @if (failed()) {
        <app-error-state message="Could not load custodians." (retry)="load()" />
      } @else {
        <app-data-table
          [columns]="columns"
          [rows]="report()?.by_custodian ?? null"
          [minWidth]="820"
          searchPlaceholder="Search people…"
          rowAction="See their assets"
          emptyIcon="profile"
          emptyTitle="Nobody holds an asset yet"
          emptyBody="Issue an asset to someone from Asset List or Asset Re-assignment and they appear here."
          (open)="openPerson($any($event))"
        />
      }
    </app-card>

    @if (person(); as p) {
      <app-modal
        [open]="true"
        [title]="p.name"
        [subtitle]="
          (p.position ?? 'Custodian') + ' · ' + p.count + ' item' + (p.count === 1 ? '' : 's')
        "
        icon="profile"
        size="lg"
        (closed)="person.set(null)"
      >
        @if (held() === null) {
          <p class="text-[13px] text-cr-ink-muted">Loading…</p>
        } @else if (held()!.length === 0) {
          <p class="text-[13px] text-cr-ink-muted">They no longer hold anything.</p>
        } @else {
          <ul class="divide-y divide-cr-line rounded-control border border-cr-line">
            @for (a of held(); track a.id) {
              <li class="flex items-center gap-3 px-3 py-2 text-[13px]">
                <div class="min-w-0 flex-1">
                  <p class="font-semibold">{{ a.tag ? a.tag + ' · ' : '' }}{{ a.name }}</p>
                  <p class="text-[12px] text-cr-ink-muted">
                    {{ a.category_name ?? 'Uncategorised' }} · {{ condition(a.condition) }}
                    @if (a.assigned_on) {
                      · since {{ date(a.assigned_on) }}
                    }
                  </p>
                </div>
                <span class="cr-num flex-none">{{
                  a.cost_cents === null ? '—' : money(a.cost_cents)
                }}</span>
                @if (canManage) {
                  <button
                    type="button"
                    class="h-8 flex-none rounded-control border border-cr-line px-3 text-[12px] font-semibold hover:bg-cr-tint"
                    (click)="turning.set(a)"
                  >
                    Re-assign
                  </button>
                }
              </li>
            }
          </ul>
        }
      </app-modal>
    }

    @if (turning(); as asset) {
      <app-asset-turnover-dialog
        [asset]="asset"
        [people]="people()"
        (saved)="turnedOver()"
        (closed)="turning.set(null)"
      />
    }
  `,
})
export class AssetMonitoringPage {
  private readonly assets = inject(AssetService);
  private readonly identity = inject(IdentityService);

  protected readonly canManage = this.identity.has('assets.manage');

  protected readonly report = signal<AssetReport | null>(null);
  protected readonly failed = signal(false);
  protected readonly person = signal<AssetCustodianLine | null>(null);
  protected readonly held = signal<Asset[] | null>(null);
  protected readonly turning = signal<Asset | null>(null);
  protected readonly people = signal<Custodian[]>([]);

  protected readonly tiles = computed(() => {
    const r = this.report();
    const people = r?.by_custodian ?? [];

    return [
      { label: 'CUSTODIANS', value: r ? String(people.length) : '—' },
      { label: 'ITEMS ISSUED', value: r ? String(r.totals.assigned) : '—' },
      {
        label: 'VALUE ISSUED',
        value: r ? fmt.pesos(people.reduce((sum, p) => sum + p.cost_cents, 0)) : '—',
      },
      {
        label: 'DAMAGED, STILL HELD',
        value: r ? String(people.reduce((sum, p) => sum + p.damaged, 0)) : '—',
      },
    ];
  });

  protected readonly columns: Column<AssetCustodianLine>[] = [
    { label: 'Custodian', kind: 'strong', value: (p) => p.name, sub: (p) => p.position },
    { label: 'Department', value: (p) => p.department },
    { label: 'Items held', kind: 'num', value: (p) => p.count },
    { label: 'Value held', kind: 'num', value: (p) => fmt.pesos(p.cost_cents) },
    { label: 'Damaged', kind: 'num', value: (p) => p.damaged },
    {
      label: 'Holding since',
      value: (p) => (p.held_since ? fmt.date(p.held_since) : null),
      date: (p) => p.held_since,
    },
  ];

  constructor() {
    this.load();

    if (this.canManage) {
      this.assets.custodians().subscribe((rows) => this.people.set(rows));
    }
  }

  protected load(): void {
    this.failed.set(false);
    this.assets.report().subscribe({
      next: (r) => this.report.set(r),
      error: () => this.failed.set(true),
    });
  }

  protected openPerson(line: AssetCustodianLine): void {
    this.person.set(line);
    this.loadHeld(line.employee_id);
  }

  protected turnedOver(): void {
    this.turning.set(null);
    this.load();

    const p = this.person();
    if (p) this.loadHeld(p.employee_id);
  }

  private loadHeld(employeeId: string): void {
    this.held.set(null);
    this.assets
      .list({ custodian_id: employeeId, per_page: 100 } as never)
      .subscribe({ next: (res) => this.held.set(res.data), error: () => this.held.set([]) });
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

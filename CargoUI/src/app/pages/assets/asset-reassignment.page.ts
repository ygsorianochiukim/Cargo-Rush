import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';

import { Asset, AssetLogRow, Custodian } from '../../models/asset/asset.model';
import { AssetService } from '../../services/asset/asset.service';
import { IdentityService } from '../../services/identity/identity.service';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { ErrorState } from '../../shared/states';
import { AssetTurnoverDialog } from './asset-turnover-dialog';

const CONTROL =
  'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] focus:border-cr-blue focus:outline-none';

const CONDITION_LABEL: Record<string, string> = { good: 'Good', fair: 'Fair', damaged: 'Damaged' };

@Component({
  selector: 'app-asset-reassignment',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [AssetTurnoverDialog, Card, DataTable, ErrorState, Field],
  template: `
    @if (canManage) {
      <app-card
        heading="New re-assignment"
        icon="route"
        hint="Hand an asset to someone, or take it back"
      >
        <div class="flex flex-wrap items-end gap-3">
          <div class="min-w-[260px] flex-1">
            <app-field label="ASSET">
              <select
                [class]="control"
                [value]="picked()"
                (change)="picked.set($any($event.target).value)"
              >
                <option value="">Choose an asset…</option>
                @for (a of assets(); track a.id) {
                  <option [value]="a.id">
                    {{ a.tag ? a.tag + ' · ' : '' }}{{ a.name }} —
                    {{ a.custodian_name ?? 'In stock' }}
                  </option>
                }
              </select>
            </app-field>
          </div>
          <button
            type="button"
            class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
            [disabled]="!picked()"
            (click)="start()"
          >
            Re-assign
          </button>
        </div>
      </app-card>
    }

    <div class="mt-4">
      <app-card heading="Re-assignment history" icon="clipboard" [padded]="false">
        <div class="flex flex-wrap items-end gap-3 border-b border-cr-line px-4 py-3">
          <app-field label="FROM">
            <input
              type="date"
              [class]="control"
              [value]="from()"
              (change)="setFrom($any($event.target).value)"
            />
          </app-field>
          <app-field label="TO">
            <input
              type="date"
              [class]="control"
              [value]="to()"
              (change)="setTo($any($event.target).value)"
            />
          </app-field>
          <div class="min-w-[220px]">
            <app-field label="PERSON">
              <select
                [class]="control"
                [value]="employee()"
                (change)="setEmployee($any($event.target).value)"
              >
                <option value="">Everyone</option>
                @for (p of people(); track p.value) {
                  <option [value]="p.value">{{ p.label }}</option>
                }
              </select>
            </app-field>
          </div>
        </div>

        @if (failed()) {
          <app-error-state message="Could not load the history." (retry)="loadLog()" />
        } @else {
          <app-data-table
            [columns]="columns"
            [rows]="log()"
            [minWidth]="960"
            searchPlaceholder="Search asset or person…"
            emptyIcon="route"
            emptyTitle="No re-assignments in this range"
            emptyBody="Every hand-over and return is recorded here with the date, condition and who recorded it."
          />
        }
      </app-card>
    </div>

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
export class AssetReassignmentPage {
  private readonly api = inject(AssetService);
  private readonly identity = inject(IdentityService);

  protected readonly canManage = this.identity.has('assets.manage');
  protected readonly control = CONTROL;

  protected readonly log = signal<AssetLogRow[] | null>(null);
  protected readonly failed = signal(false);
  protected readonly assets = signal<Asset[]>([]);
  protected readonly people = signal<Custodian[]>([]);
  protected readonly picked = signal('');
  protected readonly turning = signal<Asset | null>(null);

  protected readonly from = signal('');
  protected readonly to = signal('');
  protected readonly employee = signal('');

  private readonly byId = computed(() => new Map(this.assets().map((a) => [a.id, a])));

  protected readonly columns: Column<AssetLogRow>[] = [
    { label: 'Date', value: (r) => fmt.date(r.turned_over_on) },
    { label: 'Asset', kind: 'strong', value: (r) => r.asset_name, sub: (r) => r.asset_tag },
    { label: 'From', value: (r) => r.from_name ?? 'Stock' },
    { label: 'To', value: (r) => r.to_name ?? 'Stock' },
    { label: 'Condition', value: (r) => CONDITION_LABEL[r.condition] ?? r.condition },
    { label: 'Status after', kind: 'status', status: (r) => r.status },
    { label: 'Note', kind: 'muted', value: (r) => r.note, sub: (r) => r.recorded_by_name },
  ];

  constructor() {
    this.loadLog();
    this.loadAssets();
    this.api.custodians().subscribe((rows) => this.people.set(rows));
  }

  protected loadLog(): void {
    this.failed.set(false);
    this.log.set(null);
    this.api
      .log({
        from: this.from() || undefined,
        to: this.to() || undefined,
        employee_id: this.employee() || undefined,
      })
      .subscribe({ next: (rows) => this.log.set(rows), error: () => this.failed.set(true) });
  }

  private loadAssets(): void {
    if (!this.canManage) return;

    this.api
      .list({ per_page: 100, status: ['assigned', 'available', 'maintenance'] })
      .subscribe((res) => this.assets.set(res.data));
  }

  protected setFrom(value: string): void {
    this.from.set(value);
    this.loadLog();
  }

  protected setTo(value: string): void {
    this.to.set(value);
    this.loadLog();
  }

  protected setEmployee(value: string): void {
    this.employee.set(value);
    this.loadLog();
  }

  protected start(): void {
    const asset = this.byId().get(this.picked());
    if (asset) this.turning.set(asset);
  }

  protected turnedOver(): void {
    this.turning.set(null);
    this.picked.set('');
    this.loadLog();
    this.loadAssets();
  }
}

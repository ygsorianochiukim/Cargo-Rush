import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  OnInit,
  output,
  signal,
} from '@angular/core';

import { Asset, AssetCondition, Custodian } from '../../models/asset/asset.model';
import { ASSET_CONDITIONS, ASSET_SHELF_STATUSES } from '../../services/asset/asset.form';
import { AssetService } from '../../services/asset/asset.service';
import { Field } from '../../shared/field';
import { Modal } from '../../shared/modal';

const CONTROL =
  'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] focus:border-cr-blue focus:outline-none';

@Component({
  selector: 'app-asset-turnover-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Field, Modal],
  template: `
    <app-modal
      [open]="true"
      title="Turn over"
      [subtitle]="asset().tag ? asset().tag + ' · ' + asset().name : asset().name"
      icon="clipboard"
      size="sm"
      [locked]="busy()"
      (closed)="closed.emit()"
    >
      <div class="rounded-control bg-cr-tint px-4 py-3">
        <p class="cr-meta">NOW HELD BY</p>
        <p class="mt-1 text-[15px] font-semibold">
          {{ asset().custodian_name ?? 'Nobody — in stock' }}
        </p>
      </div>

      <div class="mt-4 space-y-3">
        <app-field
          label="HAND OVER TO"
          hint="Choose “Return to stock” when it comes back to the office."
        >
          <select [class]="control" [value]="to()" (change)="to.set($any($event.target).value)">
            @if (asset().custodian_id) {
              <option value="">Return to stock</option>
            } @else {
              <option value="" disabled>Choose a person…</option>
            }
            @for (person of people(); track person.value) {
              @if (person.value !== asset().custodian_id) {
                <option [value]="person.value">
                  {{ person.position ? person.label + ' · ' + person.position : person.label }}
                </option>
              }
            }
          </select>
        </app-field>

        <app-field label="DATE" [required]="true">
          <input
            type="date"
            [class]="control"
            [max]="today"
            [value]="on()"
            (input)="on.set($any($event.target).value)"
          />
        </app-field>

        <app-field label="CONDITION">
          <select
            [class]="control"
            [value]="condition()"
            (change)="condition.set($any($event.target).value)"
          >
            @for (c of conditions; track c.value) {
              <option [value]="c.value">{{ c.label }}</option>
            }
          </select>
        </app-field>

        @if (returning()) {
          <app-field label="AFTER RETURN">
            <select
              [class]="control"
              [value]="status()"
              (change)="status.set($any($event.target).value)"
            >
              @for (s of shelf; track s.value) {
                <option [value]="s.value">{{ s.label }}</option>
              }
            </select>
          </app-field>
        }

        <app-field label="NOTE" hint="Optional — what was handed over with it, any damage.">
          <input
            type="text"
            maxlength="255"
            [class]="control"
            [value]="note()"
            (input)="note.set($any($event.target).value)"
          />
        </app-field>
      </div>

      @if (failure(); as message) {
        <p role="alert" class="mt-3 text-[12px] font-medium text-cr-red">{{ message }}</p>
      }

      <ng-container modal-footer>
        <button
          type="button"
          class="h-10 rounded-control px-4 text-[14px] font-semibold text-cr-ink transition-colors hover:bg-cr-tint disabled:opacity-60"
          [disabled]="busy()"
          (click)="closed.emit()"
        >
          Cancel
        </button>
        <button
          type="button"
          class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
          [disabled]="busy() || !on() || (!asset().custodian_id && !to())"
          (click)="confirm()"
        >
          {{ busy() ? 'Saving…' : returning() ? 'Record return' : 'Record turnover' }}
        </button>
      </ng-container>
    </app-modal>
  `,
})
export class AssetTurnoverDialog implements OnInit {
  private readonly assets = inject(AssetService);

  readonly asset = input.required<Asset>();
  readonly people = input.required<Custodian[]>();

  readonly saved = output<Asset>();
  readonly closed = output<void>();

  protected readonly control = CONTROL;
  protected readonly conditions = ASSET_CONDITIONS;
  protected readonly shelf = ASSET_SHELF_STATUSES;
  protected readonly today = new Date().toLocaleDateString('en-CA');

  protected readonly to = signal('');
  protected readonly on = signal(this.today);
  protected readonly condition = signal<AssetCondition>('good');
  protected readonly status = signal('available');
  protected readonly note = signal('');
  protected readonly busy = signal(false);
  protected readonly failure = signal<string | null>(null);

  protected readonly returning = computed(() => !!this.asset().custodian_id && !this.to());

  ngOnInit(): void {
    this.condition.set(this.asset().condition);
  }

  protected confirm(): void {
    this.busy.set(true);
    this.failure.set(null);

    this.assets
      .turnover(this.asset().id, {
        to_employee_id: this.to() || null,
        turned_over_on: this.on(),
        condition: this.condition(),
        ...(this.returning() ? { status: this.status() } : {}),
        note: this.note().trim() || null,
      })
      .subscribe({
        next: (asset) => {
          this.busy.set(false);
          this.saved.emit(asset);
        },
        error: (error: HttpErrorResponse) => {
          this.busy.set(false);

          const errors = error.error?.errors as Record<string, string[]> | undefined;

          this.failure.set(
            error.status === 403
              ? 'You can see the register but not record turnovers.'
              : ((errors && Object.values(errors)[0]?.[0]) ??
                  error.error?.message ??
                  'Could not save that. Check the connection and try again.'),
          );
        },
      });
  }
}

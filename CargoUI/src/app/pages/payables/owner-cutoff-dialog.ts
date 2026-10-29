import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';

import { OwnerCutoff, OwnerTransaction, PayableLine } from '../../models/finance/payables.model';
import { FinanceService } from '../../services/finance/finance.service';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Modal } from '../../shared/modal';
import { SkeletonRows } from '../../shared/states';
import { OwnerHistoryList } from '../owner-pay-report/owner-history-list';
import { costWord, cutoffState, percent } from '../owner-pay-report/owner-pay';

@Component({
  selector: 'app-owner-cutoff-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Field, Modal, OwnerHistoryList, SkeletonRows],
  template: `
    <app-modal
      [open]="true"
      [title]="line().name"
      [subtitle]="'Cutoff ' + (line().period_label ?? '')"
      icon="fleet"
      size="lg"
      [locked]="busy()"
      (closed)="closed.emit()"
    >
      <div class="flex flex-wrap items-center gap-2" role="tablist">
        @for (
          option of [
            { key: 'cutoff', label: 'This cutoff' },
            { key: 'history', label: 'Transaction history' },
          ];
          track option.key
        ) {
          <button
            type="button"
            role="tab"
            [attr.aria-selected]="tab() === option.key"
            class="inline-flex h-9 items-center gap-2 rounded-full border px-3.5 text-[13px] transition-colors"
            [class]="
              tab() === option.key
                ? 'border-cr-blue bg-cr-tint font-semibold text-cr-blue'
                : 'border-cr-line bg-cr-surface text-cr-ink hover:bg-cr-tint'
            "
            (click)="tab.set($any(option.key))"
          >
            {{ option.label }}
          </button>
        }

        @if (cutoff(); as c) {
          <span
            class="ml-auto rounded-full px-2.5 py-1 text-[10px] font-semibold tracking-[0.06em] uppercase"
            [class]="state(c).tone"
          >
            {{ state(c).label }}
          </span>
        }
      </div>

      @if (tab() === 'history') {
        <div class="mt-4">
          <app-owner-history-list [truckerId]="line().trucker_id ?? ''" />
        </div>
      } @else if (cutoff(); as c) {
        <div class="mt-4 grid gap-3 sm:grid-cols-5">
          <div class="rounded-control bg-cr-tint px-3 py-3">
            <p class="cr-meta">INCOME</p>
            <p class="cr-num mt-1 text-[16px] font-bold">{{ money(c.gross_cents) }}</p>
            <p class="text-[11px] text-cr-ink-muted">
              {{ c.runs_count }} {{ c.runs_count === 1 ? 'run' : 'runs' }}
            </p>
          </div>
          <div class="rounded-control bg-cr-tint px-3 py-3">
            <p class="cr-meta">COMPANY SHARE</p>
            <p class="cr-num mt-1 text-[16px] font-bold text-cr-ink-muted">
              − {{ money(c.commission_cents) }}
            </p>
            <p class="text-[11px] text-cr-ink-muted">{{ fleetKeeps(c) }}</p>
          </div>
          <div class="rounded-control bg-cr-tint px-3 py-3">
            <p class="cr-meta">THEIR SHARE</p>
            <p class="cr-num mt-1 text-[16px] font-bold">{{ money(c.share_cents) }}</p>
          </div>
          <div class="rounded-control bg-cr-tint px-3 py-3">
            <p class="cr-meta">LESS COSTS</p>
            <p class="cr-num mt-1 text-[16px] font-bold text-cr-red">
              − {{ money(c.costs_cents) }}
            </p>
            <p class="text-[11px] text-cr-ink-muted">{{ costsSummary(c) }}</p>
          </div>
          <div class="rounded-control bg-cr-blue/10 px-3 py-3">
            <p class="cr-meta">{{ c.net_cents >= 0 ? 'TO SETTLE' : 'OWNER OWES' }}</p>
            <p
              class="cr-num mt-1 text-[16px] font-bold"
              [class]="c.net_cents < 0 ? 'text-cr-red' : 'text-cr-blue'"
            >
              {{ money(c.net_cents < 0 ? -c.net_cents : c.net_cents) }}
            </p>
            <p class="text-[11px] text-cr-ink-muted">release {{ date(c.due_on) }}</p>
          </div>
        </div>

        @if (c.paid_elsewhere_cents > 0) {
          <p class="mt-3 text-[12px] text-cr-ink-muted">
            {{ money(c.paid_elsewhere_cents) }} of this cutoff's runs were already paid from the
            wallet and are not counted here.
          </p>
        }

        <div class="mt-4 overflow-x-auto rounded-control border border-cr-line">
          <table class="w-full min-w-[560px] text-[12px]">
            <thead class="bg-cr-tint text-left">
              <tr>
                <th class="px-3 py-2 font-semibold" colspan="2">
                  Runs · how each share comes about
                </th>
                <th class="px-3 py-2 text-right font-semibold">Income</th>
                <th class="px-3 py-2 text-right font-semibold">Company</th>
                <th class="px-3 py-2 text-right font-semibold">Owner</th>
              </tr>
            </thead>
            <tbody>
              @for (run of c.runs; track run.id) {
                <tr class="border-t border-cr-line/70" [class.opacity-50]="!run.counted">
                  <td class="px-3 py-2">
                    <span class="block font-semibold">{{ run.reference ?? '—' }}</span>
                    <span class="block text-cr-ink-muted"
                      >{{ date(run.on) }} · {{ run.plate }}</span
                    >
                  </td>
                  <td class="px-3 py-2">
                    {{ run.origin }} → {{ run.destination }}
                    @if (run.customer) {
                      <span class="block text-cr-ink-muted">{{ run.customer }}</span>
                    }
                    @if (run.paid_elsewhere) {
                      <span class="block text-cr-warning">paid · {{ run.paid_elsewhere }}</span>
                    }
                  </td>
                  <td class="cr-num px-3 py-2 text-right">
                    {{ money(run.gross_cents) }}
                    @if (run.vat_in_price_cents > 0) {
                      <span class="block text-[10px] text-cr-ink-muted">
                        {{ money(run.price_cents) }} less VAT
                      </span>
                    }
                  </td>
                  <td class="cr-num px-3 py-2 text-right text-cr-ink-muted">
                    − {{ money(run.commission_cents) }}
                    <span class="block text-[10px]">{{ percent(run.fleet_keeps_bp) }}</span>
                  </td>
                  <td class="cr-num px-3 py-2 text-right font-semibold">
                    {{ money(run.share_cents) }}
                  </td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="5" class="px-3 py-3 text-center text-cr-ink-muted">
                    No runs on this cutoff.
                  </td>
                </tr>
              }
              <tr class="border-t border-cr-ink/20 bg-cr-tint/60">
                <td class="px-3 py-2 font-semibold" colspan="2">Their share</td>
                <td class="cr-num px-3 py-2 text-right">{{ money(c.gross_cents) }}</td>
                <td class="cr-num px-3 py-2 text-right text-cr-ink-muted">
                  − {{ money(c.commission_cents) }}
                </td>
                <td class="cr-num px-3 py-2 text-right font-bold">{{ money(c.share_cents) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        @if (c.costs.length > 0) {
          <div class="mt-3 overflow-x-auto rounded-control border border-cr-line">
            <table class="w-full min-w-[560px] text-[12px]">
              <thead class="bg-cr-tint text-left">
                <tr>
                  <th class="px-3 py-2 font-semibold" colspan="3">
                    Costs the fleet put into their truck · taken off the share
                  </th>
                  <th class="px-3 py-2 text-right font-semibold">Amount</th>
                </tr>
              </thead>
              <tbody>
                @for (cost of c.costs; track $index) {
                  <tr class="border-t border-cr-line/70">
                    <td class="px-3 py-2">
                      <span class="block font-semibold">{{ costWord(cost.kind) }}</span>
                      <span class="block text-cr-ink-muted">
                        {{ cost.on ? date(cost.on) : '—' }} · {{ cost.plate ?? '—' }}
                      </span>
                    </td>
                    <td class="px-3 py-2" colspan="2">
                      {{ cost.label }}
                      @if (cost.detail) {
                        <span class="block text-cr-ink-muted">{{ cost.detail }}</span>
                      }
                      @if (cost.litres !== null || cost.paid_with) {
                        <span class="block text-cr-ink-muted">
                          {{ cost.litres !== null ? litres(cost.litres) : '' }}
                          {{ cost.litres !== null && cost.paid_with ? '·' : '' }}
                          {{ cost.paid_with ?? '' }}
                        </span>
                      }
                    </td>
                    <td class="cr-num px-3 py-2 text-right font-semibold text-cr-red">
                      − {{ money(cost.cents) }}
                    </td>
                  </tr>
                }
                <tr class="border-t border-cr-ink/20 bg-cr-tint/60">
                  <td class="px-3 py-2 font-semibold" colspan="3">
                    {{ c.net_cents < 0 ? 'Owner owes the fleet' : 'To settle to the owner' }}
                    <span class="cr-num block text-[11px] font-normal text-cr-ink-muted">
                      {{ formula(c) }}
                    </span>
                  </td>
                  <td
                    class="cr-num px-3 py-2 text-right text-[14px] font-bold"
                    [class]="c.net_cents < 0 ? 'text-cr-red' : 'text-cr-blue'"
                  >
                    {{ money(c.net_cents < 0 ? -c.net_cents : c.net_cents) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        }

        @if (c.state === 'unpaid') {
          <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <app-field label="DATE PAID">
              <input
                type="date"
                [class]="inputClass"
                [max]="today"
                [value]="on()"
                (input)="on.set($any($event.target).value)"
              />
            </app-field>
            <app-field label="HOW">
              <select
                [class]="inputClass"
                [value]="method()"
                (change)="method.set($any($event.target).value)"
              >
                <option value="bank_transfer">Bank transfer</option>
                <option value="cash">Cash</option>
                <option value="cheque">Cheque</option>
                <option value="online">Online</option>
              </select>
            </app-field>
            <app-field label="REFERENCE" hint="A cheque number or a transfer reference.">
              <input
                type="text"
                maxlength="120"
                [class]="inputClass"
                [value]="reference()"
                (input)="reference.set($any($event.target).value)"
              />
            </app-field>
            <app-field label="NOTE" hint="Optional.">
              <input
                type="text"
                maxlength="255"
                [class]="inputClass"
                [value]="note()"
                (input)="note.set($any($event.target).value)"
              />
            </app-field>
          </div>

          @if (c.net_cents > 0) {
            <label class="mt-3 flex cursor-pointer items-start gap-2">
              <input
                type="checkbox"
                class="mt-0.5 h-4 w-4 flex-none accent-cr-blue"
                [checked]="cleared()"
                (change)="cleared.set($any($event.target).checked)"
              />
              <span class="text-[12px]">
                <span class="font-semibold">Already received</span>
                <span class="block text-cr-ink-muted">
                  Leave unticked for a transfer — confirm it from their wallet once it lands. The
                  costs come off their wallet either way.
                </span>
              </span>
            </label>
          } @else {
            <p class="mt-3 text-[13px]">
              The costs come to more than their share. Nothing goes out; the costs are charged to
              their wallet and the difference carries forward.
            </p>
          }
        } @else if (c.state === 'processing') {
          <p class="mt-4 text-[13px]">
            {{ money(c.settlement?.amount_cents ?? 0) }} was sent on
            {{ date(c.settlement?.occurred_on) }} and is waiting to clear. Confirm it from their
            wallet once it lands.
          </p>
        }
      } @else if (failure(); as message) {
        <p role="alert" class="mt-4 text-[13px] font-medium text-cr-red">{{ message }}</p>
      } @else {
        <div class="mt-4"><app-skeleton-rows [count]="4" /></div>
      }

      @if (cutoff() && failure(); as message) {
        <p role="alert" class="mt-3 text-[12px] font-medium text-cr-red">{{ message }}</p>
      }

      <ng-container modal-footer>
        <button
          type="button"
          class="h-10 rounded-control px-4 text-[14px] font-semibold text-cr-ink transition-colors hover:bg-cr-tint disabled:opacity-60"
          [disabled]="busy()"
          (click)="closed.emit()"
        >
          Close
        </button>
        @if (tab() === 'cutoff' && cutoff()?.state === 'unpaid') {
          <button
            type="button"
            class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
            [disabled]="busy() || !on()"
            (click)="confirm()"
          >
            {{ busy() ? 'Saving…' : payLabel() }}
          </button>
        }
      </ng-container>
    </app-modal>
  `,
})
export class OwnerCutoffDialog {
  private readonly finance = inject(FinanceService);

  readonly line = input.required<PayableLine>();

  readonly settled = output<void>();
  readonly closed = output<void>();

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] focus:border-cr-blue focus:outline-none';

  protected readonly today = new Date().toLocaleDateString('en-CA');

  protected readonly tab = signal<'cutoff' | 'history'>('cutoff');
  protected readonly cutoff = signal<OwnerCutoff | null>(null);
  protected readonly on = signal(this.today);
  protected readonly method = signal('bank_transfer');
  protected readonly reference = signal('');
  protected readonly note = signal('');
  protected readonly cleared = signal(false);
  protected readonly busy = signal(false);
  protected readonly failure = signal<string | null>(null);

  protected readonly state = cutoffState;
  protected readonly percent = percent;
  protected readonly costWord = costWord;

  protected readonly payLabel = computed(() => {
    const c = this.cutoff();

    if (!c) return 'Pay';

    return c.net_cents > 0 ? `Settle ${fmt.pesos(c.net_cents)}` : 'Charge the costs';
  });

  constructor() {
    queueMicrotask(() => this.load());
  }

  private load(): void {
    const line = this.line();

    if (!line.trucker_id || !line.period_start || !line.period_end) {
      this.failure.set('This line does not name a cutoff.');

      return;
    }

    this.finance
      .ownerPayables({ from: line.period_start, to: line.period_end, trucker: line.trucker_id })
      .subscribe({
        next: (statement) => {
          const cutoff = statement.owners[0]?.cutoffs.find((c) => c.start === line.period_start);

          if (!cutoff) {
            this.failure.set(
              'That cutoff is no longer on the statement. The list has been refreshed.',
            );
            this.settled.emit();

            return;
          }

          this.cutoff.set(cutoff);
        },
        error: () => this.failure.set('Could not load the cutoff. Check the connection.'),
      });
  }

  protected confirm(): void {
    const line = this.line();
    const c = this.cutoff();

    if (!c || !line.trucker_id) return;

    this.busy.set(true);
    this.failure.set(null);

    this.finance
      .settleOwnerCutoff(line.trucker_id, {
        start: c.start,
        end: c.end,
        method: this.method(),
        cleared: c.net_cents > 0 ? this.cleared() : true,
        reference: this.reference().trim() || null,
        note: this.note().trim() || null,
        occurred_on: this.on() || null,
      })
      .subscribe({
        next: () => {
          this.busy.set(false);
          this.settled.emit();
        },
        error: (error: HttpErrorResponse) => {
          this.busy.set(false);

          const errors = error.error?.errors as Record<string, string[]> | undefined;

          this.failure.set(
            error.status === 403
              ? 'You can see what is owed but not pay it. Ask whoever manages the truckers.'
              : ((errors && Object.values(errors)[0]?.[0]) ??
                  error.error?.message ??
                  'Could not save that. Check the connection and try again.'),
          );
        },
      });
  }

  protected fleetKeeps(c: OwnerCutoff): string {
    const rates = [
      ...new Set(c.runs.filter((run) => run.counted).map((run) => run.fleet_keeps_bp)),
    ];

    return rates.length === 0 ? '' : rates.map(percent).join(' / ') + ' of income';
  }

  protected costsSummary(c: OwnerCutoff): string {
    return (['fuel', 'allowance', 'maintenance'] as const)
      .filter((kind) => c[`${kind}_cents`] > 0)
      .map((kind) => `${costWord(kind).toLowerCase()} ${fmt.pesos(c[`${kind}_cents`])}`)
      .join(' · ');
  }

  protected formula(c: OwnerCutoff): string {
    const parts = (['fuel', 'allowance', 'maintenance'] as const)
      .filter((kind) => c[`${kind}_cents`] > 0)
      .map((kind) => `− ${fmt.pesos(c[`${kind}_cents`])} ${costWord(kind).toLowerCase()}`);

    return `${fmt.pesos(c.share_cents)} share ${parts.join(' ')} = ${fmt.pesos(c.net_cents)}`;
  }

  protected money(cents: number): string {
    return fmt.pesos(cents);
  }

  protected date(value: string | null | undefined): string {
    return fmt.date(value);
  }

  protected litres(value: number): string {
    return `${value.toLocaleString('en-PH', { maximumFractionDigits: 2 })} L`;
  }
}

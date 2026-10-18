import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';
import { catchError, map, of, startWith, switchMap } from 'rxjs';

import { TruckDashboard, TruckDashboardRow } from '../../models/dashboard/dashboard.model';
import { DashboardService } from '../../services/dashboard/dashboard.service';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { StatusPill } from '../../shared/status-pill';

type Period = 'day' | 'cutoff' | 'month';

function today(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

@Component({
  selector: 'app-truck-cards',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, RouterLink, StatusPill],
  template: `
    <section aria-label="Trucks" class="mt-6">
      <div class="flex flex-wrap items-center gap-3">
        <h2 class="text-[18px] font-semibold">Trucks</h2>
        <span class="text-[13px] text-cr-ink-muted">{{ data()?.label ?? '…' }}</span>

        <div class="ml-auto flex items-center gap-2">
          <div class="flex rounded-control bg-cr-tint p-1" role="tablist" aria-label="Period">
            @for (p of periods; track p.value) {
              <button
                type="button"
                role="tab"
                [attr.aria-selected]="period() === p.value"
                class="h-8 rounded-control px-3 text-[13px] font-semibold transition-colors"
                [class.bg-cr-surface]="period() === p.value"
                [class.text-cr-blue]="period() === p.value"
                [class.shadow-card]="period() === p.value"
                [class.text-cr-ink-muted]="period() !== p.value"
                (click)="period.set(p.value)"
              >
                {{ p.label }}
              </button>
            }
          </div>
          <button
            type="button"
            class="flex h-9 w-9 items-center justify-center rounded-control text-cr-ink-muted transition-colors hover:bg-cr-tint hover:text-cr-blue"
            aria-label="Previous period"
            (click)="go(data()?.previous)"
          >
            <app-icon name="chevron-left" [size]="16" />
          </button>
          <button
            type="button"
            class="flex h-9 w-9 items-center justify-center rounded-control text-cr-ink-muted transition-colors hover:bg-cr-tint hover:text-cr-blue"
            aria-label="Next period"
            (click)="go(data()?.next)"
          >
            <app-icon name="chevron-right" [size]="16" />
          </button>
        </div>
      </div>

      @if (failed()) {
        <p class="mt-3 rounded-card bg-cr-red-bg px-4 py-3 text-[13px] font-medium text-cr-red" role="alert">
          Could not load the trucks.
        </p>
      } @else if (data(); as d) {
        <div class="mt-3 grid grid-cols-2 gap-4 lg:grid-cols-4">
          <div class="rounded-card bg-cr-surface p-4 shadow-card ring-1 ring-cr-line/60">
            <p class="cr-meta text-cr-ink-muted">Trips delivered</p>
            <p class="cr-num mt-1 text-[22px] font-semibold">{{ d.totals.trips }}</p>
          </div>
          <div class="rounded-card bg-cr-surface p-4 shadow-card ring-1 ring-cr-line/60">
            <p class="cr-meta text-cr-ink-muted">Truck income</p>
            <p class="cr-num mt-1 text-[22px] font-semibold">{{ fmt.pesos(d.totals.income_cents) }}</p>
          </div>
          <div class="rounded-card bg-cr-surface p-4 shadow-card ring-1 ring-cr-line/60">
            <p class="cr-meta text-cr-ink-muted">Truck costs</p>
            <p class="cr-num mt-1 text-[22px] font-semibold">{{ fmt.pesos(d.totals.truck_expenses_cents) }}</p>
          </div>
          <div class="rounded-card bg-cr-surface p-4 shadow-card ring-1 ring-cr-line/60">
            <p class="cr-meta text-cr-ink-muted">Truck net</p>
            <p class="cr-num mt-1 text-[22px] font-semibold" [class.text-cr-red]="d.totals.truck_net_cents < 0">
              {{ fmt.pesos(d.totals.truck_net_cents) }}
            </p>
          </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          @for (t of d.trucks; track t.truck.id) {
            <article class="flex flex-col rounded-card bg-cr-surface p-4 shadow-card ring-1 ring-cr-line/60">
              <header class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <h3 class="truncate text-[15px] font-semibold">{{ t.truck.label }}</h3>
                  <p class="cr-num text-[12px] text-cr-ink-muted">{{ t.truck.plate ?? 'No plate' }}</p>
                </div>
                @if (t.status) {
                  <app-status-pill [status]="$any(t.status)" />
                }
              </header>

              <dl class="mt-4 grid grid-cols-3 gap-2 text-[12px]">
                <div>
                  <dt class="text-cr-ink-muted">Income</dt>
                  <dd class="cr-num mt-0.5 text-[14px] font-semibold">{{ fmt.pesos(t.income_cents) }}</dd>
                </div>
                <div>
                  <dt class="text-cr-ink-muted">Costs</dt>
                  <dd class="cr-num mt-0.5 text-[14px] font-semibold">{{ fmt.pesos(t.expenses_cents) }}</dd>
                </div>
                <div>
                  <dt class="text-cr-ink-muted">Net</dt>
                  <dd
                    class="cr-num mt-0.5 text-[14px] font-semibold"
                    [class.text-cr-red]="t.net_income_cents < 0"
                    [class.text-cr-success]="t.net_income_cents > 0"
                  >
                    {{ fmt.pesos(t.net_income_cents) }}
                  </dd>
                </div>
              </dl>

              <div class="mt-3">
                <div class="flex justify-between text-[11px] text-cr-ink-muted">
                  <span>Costs as share of income</span>
                  <span class="cr-num">{{ costShareLabel(t) }}</span>
                </div>
                <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-cr-line/60">
                  <span
                    class="block h-full rounded-full"
                    [class.bg-cr-warning]="costShare(t) <= 100"
                    [class.bg-cr-red]="costShare(t) > 100"
                    [style.width.%]="min(costShare(t), 100)"
                  ></span>
                </div>
              </div>

              <p class="mt-3 text-[12px] text-cr-ink-muted">
                {{ t.trips }} {{ t.trips === 1 ? 'trip' : 'trips' }}
                @if (t.top_costs.length > 0) {
                  · biggest cost {{ t.top_costs[0].kind }} {{ fmt.pesos(t.top_costs[0].amount_cents) }}
                }
              </p>
            </article>
          } @empty {
            <p class="text-[13px] text-cr-ink-muted">No trucks on the books yet.</p>
          }
        </div>

        <a routerLink="/truck-report" class="mt-3 inline-block text-[13px] font-semibold text-cr-blue hover:underline">
          Every expense, truck by truck →
        </a>
      } @else {
        <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          @for (s of [0, 1, 2]; track s) {
            <div class="rounded-card bg-cr-surface p-4 shadow-card ring-1 ring-cr-line/60">
              <div class="cr-skeleton h-3 w-24"></div>
              <div class="cr-skeleton mt-4 h-6 w-full"></div>
              <div class="cr-skeleton mt-3 h-2 w-full"></div>
            </div>
          }
        </div>
      }
    </section>
  `,
})
export class TruckCards {
  private readonly api = inject(DashboardService);

  protected readonly fmt = fmt;
  protected readonly min = Math.min;

  protected readonly periods: { value: Period; label: string }[] = [
    { value: 'day', label: 'Day' },
    { value: 'cutoff', label: 'Cutoff' },
    { value: 'month', label: 'Month' },
  ];

  protected readonly period = signal<Period>('month');
  protected readonly date = signal(today());

  private readonly state = toSignal(
    toObservable(computed(() => ({ period: this.period(), date: this.date() }))).pipe(
      switchMap(({ period, date }) =>
        this.api.trucks(period, date).pipe(
          map((data) => ({ data, error: false })),
          catchError(() => of({ data: null, error: true })),
          startWith({ data: null, error: false }),
        ),
      ),
    ),
    { initialValue: { data: null as TruckDashboard | null, error: false } },
  );

  protected readonly data = computed(() => this.state().data);
  protected readonly failed = computed(() => this.state().error);

  protected go(date: string | undefined): void {
    if (date) this.date.set(date);
  }

  protected costShare(t: TruckDashboardRow): number {
    if (t.income_cents <= 0) return t.expenses_cents > 0 ? 101 : 0;

    return Math.round((t.expenses_cents / t.income_cents) * 100);
  }

  protected costShareLabel(t: TruckDashboardRow): string {
    if (t.income_cents <= 0) return t.expenses_cents > 0 ? 'no income' : '—';

    return `${this.costShare(t)}%`;
  }
}

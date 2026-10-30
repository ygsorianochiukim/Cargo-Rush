import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';

import {
  OwnerCostKind,
  OwnerCutoff,
  OwnerStatement,
  OwnerStatementOwner,
} from '../../models/finance/payables.model';
import { FinanceService } from '../../services/finance/finance.service';
import { Card } from '../../shared/card';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { EmptyState, ErrorState, SkeletonRows } from '../../shared/states';
import { OwnerHistoryList } from './owner-history-list';
import { COST_KINDS, costWord, cutoffFormula, cutoffState, percent } from './owner-pay';

function monthOf(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function firstDay(month: string): string {
  return `${month}-01`;
}

function lastDay(month: string): string {
  const [year, m] = month.split('-').map(Number);
  const end = new Date(year, m, 0);

  return `${month}-${String(end.getDate()).padStart(2, '0')}`;
}

@Component({
  selector: 'app-owner-pay-report',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Field, Icon, EmptyState, ErrorState, OwnerHistoryList, SkeletonRows],
  templateUrl: './owner-pay-report.page.html',
})
export class OwnerPayReportPage {
  private readonly finance = inject(FinanceService);

  protected readonly fmt = fmt;
  protected readonly costKinds = COST_KINDS;
  protected readonly costWord = costWord;
  protected readonly percent = percent;
  protected readonly state = cutoffState;
  protected readonly formula = cutoffFormula;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly statement = signal<OwnerStatement | null>(null);
  protected readonly loading = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly search = signal('');
  protected readonly chosenId = signal<string | null>(null);
  protected readonly opened = signal<ReadonlySet<string>>(new Set<string>());
  protected readonly tab = signal<'cutoffs' | 'history'>('cutoffs');

  protected readonly fromMonth = signal(
    monthOf(new Date(new Date().getFullYear(), new Date().getMonth() - 2, 1)),
  );
  protected readonly toMonth = signal(monthOf(new Date()));

  protected readonly owners = computed(() => {
    const needle = this.search().trim().toLowerCase();
    const rows = this.statement()?.owners ?? [];

    if (needle === '') return rows;

    return rows.filter(
      (owner) =>
        owner.name.toLowerCase().includes(needle) ||
        (owner.business_name ?? '').toLowerCase().includes(needle) ||
        owner.plates.some((plate) => plate.toLowerCase().includes(needle)),
    );
  });

  protected readonly chosen = computed<OwnerStatementOwner | null>(() => {
    const id = this.chosenId();
    const rows = this.statement()?.owners ?? [];

    return rows.find((owner) => owner.id === id) ?? rows[0] ?? null;
  });

  protected readonly cutoffs = computed(() =>
    (this.chosen()?.cutoffs ?? []).filter((cutoff) => cutoff.state !== 'nothing'),
  );

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);

    this.finance
      .ownerPayables({ from: firstDay(this.fromMonth()), to: lastDay(this.toMonth()) })
      .subscribe({
        next: (statement) => {
          this.loading.set(false);
          this.statement.set(statement);

          const first = this.cutoffs()[0];

          if (first !== undefined && this.opened().size === 0) {
            this.opened.set(new Set<string>([first.start]));
          }
        },
        error: (failure: { error?: { message?: string } }) => {
          this.loading.set(false);
          this.statement.set(null);
          this.error.set(
            failure.error?.message ?? 'Could not load the truck owners. Check the connection.',
          );
        },
      });
  }

  protected choose(owner: OwnerStatementOwner): void {
    this.chosenId.set(owner.id);
    this.tab.set('cutoffs');

    const first = owner.cutoffs.find((cutoff) => cutoff.state !== 'nothing');

    this.opened.set(new Set<string>(first ? [first.start] : []));
  }

  protected setRange(which: 'from' | 'to', value: string): void {
    if (!/^\d{4}-\d{2}$/.test(value)) return;

    if (which === 'from') this.fromMonth.set(value);
    else this.toMonth.set(value);

    if (this.fromMonth() > this.toMonth()) {
      if (which === 'from') this.toMonth.set(value);
      else this.fromMonth.set(value);
    }

    this.load();
  }

  protected toggle(cutoff: OwnerCutoff): void {
    const next = new Set(this.opened());

    if (next.has(cutoff.start)) next.delete(cutoff.start);
    else next.add(cutoff.start);

    this.opened.set(next);
  }

  protected isOpen(cutoff: OwnerCutoff): boolean {
    return this.opened().has(cutoff.start);
  }

  protected costOf(cutoff: OwnerCutoff, kind: OwnerCostKind): number {
    return cutoff[`${kind}_cents`];
  }

  protected keeps(fleetKeepsBp: number): string {
    return percent(10_000 - fleetKeepsBp);
  }

  protected litres(value: number | null): string {
    return value === null ? '' : `${value.toLocaleString('en-PH', { maximumFractionDigits: 2 })} L`;
  }

  protected initials(name: string): string {
    return name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('');
  }
}

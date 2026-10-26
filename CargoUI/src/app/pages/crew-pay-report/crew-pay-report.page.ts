import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';

import {
  CrewPayCutoff,
  CrewPayReport,
  CrewPayRosterRow,
  CrewPayTripRow,
} from '../../models/hr/payroll.model';
import { CrewPayReportService } from '../../services/hr/crew-pay-report.service';
import { Card } from '../../shared/card';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { EmptyState, ErrorState, SkeletonRows } from '../../shared/states';

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
  selector: 'app-crew-pay-report',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Field, Icon, EmptyState, ErrorState, SkeletonRows],
  templateUrl: './crew-pay-report.page.html',
})
export class CrewPayReportPage {
  private readonly api = inject(CrewPayReportService);

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly roster = signal<CrewPayRosterRow[] | null>(null);
  protected readonly rosterError = signal<string | null>(null);
  protected readonly search = signal('');

  protected readonly chosen = signal<CrewPayRosterRow | null>(null);
  protected readonly report = signal<CrewPayReport | null>(null);
  protected readonly loading = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly fromMonth = signal(monthOf(new Date(new Date().getFullYear(), new Date().getMonth() - 2, 1)));
  protected readonly toMonth = signal(monthOf(new Date()));

  protected readonly opened = signal<ReadonlySet<string>>(new Set<string>());

  protected readonly shown = computed(() => {
    const needle = this.search().trim().toLowerCase();
    const rows = this.roster() ?? [];

    if (needle === '') return rows;

    return rows.filter(
      (row) =>
        row.name.toLowerCase().includes(needle) ||
        (row.employee_no ?? '').toLowerCase().includes(needle) ||
        row.role_label.toLowerCase().includes(needle),
    );
  });

  protected readonly summary = computed(() => {
    const report = this.report();

    if (report === null) return null;

    const onRuns = report.cutoffs.filter((cutoff) => cutoff.run?.on_run).length;
    const disagree = report.cutoffs.filter((cutoff) => cutoff.run?.agrees === false).length;

    return { onRuns, disagree, cutoffs: report.cutoffs.length };
  });

  constructor() {
    this.loadRoster();
  }

  protected loadRoster(): void {
    this.rosterError.set(null);

    this.api.roster().subscribe({
      next: (rows) => {
        this.roster.set(rows);

        const current = this.chosen();

        if (current === null && rows.length > 0) this.choose(rows[0]);
        else if (current !== null) {
          const fresh = rows.find((row) => row.employee_id === current.employee_id);

          if (fresh) this.chosen.set(fresh);
        }
      },
      error: () => {
        this.roster.set(null);
        this.rosterError.set('Could not load the crew. Check the connection and try again.');
      },
    });
  }

  protected choose(row: CrewPayRosterRow): void {
    this.chosen.set(row);
    this.opened.set(new Set<string>());
    this.load();
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

  protected load(): void {
    const person = this.chosen();

    if (person === null) return;

    this.loading.set(true);
    this.error.set(null);

    this.api.forEmployee(person.employee_id, firstDay(this.fromMonth()), lastDay(this.toMonth())).subscribe({
      next: (report) => {
        this.loading.set(false);
        this.report.set(report);

        const first = report.cutoffs[0];

        if (first !== undefined) this.opened.set(new Set<string>([first.start]));
      },
      error: (failure: { error?: { message?: string } }) => {
        this.loading.set(false);
        this.report.set(null);
        this.error.set(failure.error?.message ?? 'Could not load that person’s trips.');
      },
    });
  }

  protected toggle(cutoff: CrewPayCutoff): void {
    const next = new Set(this.opened());

    if (next.has(cutoff.start)) next.delete(cutoff.start);
    else next.add(cutoff.start);

    this.opened.set(next);
  }

  protected isOpen(cutoff: CrewPayCutoff): boolean {
    return this.opened().has(cutoff.start);
  }

  protected formula(cutoff: CrewPayCutoff): string {
    switch (cutoff.pay_basis) {
      case 'per_trip':
        return `${cutoff.counted_trips} ${cutoff.counted_trips === 1 ? 'trip' : 'trips'} × ${fmt.pesos(cutoff.rate_cents)}`;
      case 'daily':
        return `${cutoff.counted_days} ${cutoff.counted_days === 1 ? 'day' : 'days'} × ${fmt.pesos(cutoff.rate_cents)}`;
      default:
        return `Monthly ${fmt.pesos(cutoff.rate_cents)}, this cutoff’s share`;
    }
  }

  protected runState(cutoff: CrewPayCutoff): { label: string; tone: string } {
    const run = cutoff.run;

    if (run === null) {
      return cutoff.held_whole_cutoff
        ? { label: 'Held a hired truck all cutoff — nothing due', tone: 'bg-cr-tint text-cr-ink-muted' }
        : { label: 'No crew run yet', tone: 'bg-cr-warning-bg text-cr-warning' };
    }

    if (!run.on_run) {
      return { label: `${run.reference} · not on it`, tone: 'bg-cr-warning-bg text-cr-warning' };
    }

    if (run.agrees === false) {
      return { label: `${run.reference} · differs`, tone: 'bg-cr-red-bg text-cr-red' };
    }

    const status = run.status === 'paid' ? 'paid' : run.status === 'approved' ? 'approved' : 'draft';

    return { label: `${run.reference} · ${status} · matches`, tone: 'bg-cr-success-bg text-cr-success' };
  }

  protected seat(trip: CrewPayTripRow): string {
    return trip.seat === 'driver' ? 'Drove' : 'Helper';
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

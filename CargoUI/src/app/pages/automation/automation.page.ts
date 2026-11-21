import { DatePipe, DecimalPipe, NgTemplateOutlet, TitleCasePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  Injector,
  afterNextRender,
  computed,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';
import { EMPTY, Subject, catchError, debounceTime, exhaustMap, merge, switchMap, timer } from 'rxjs';

import { Envelope } from '../../models/shared/envelope.model';
import { ApiService } from '../../services/shared/api.service';
import { Card } from '../../shared/card';
import { DiscordBotSettings } from '../../shared/discord-bot-settings';
import { TableSearch } from '../../shared/table-search';

interface AutomationRun {
  id: string;
  workflow: string;
  trigger: 'scheduled' | 'manual';
  status: 'running' | 'completed' | 'failed' | 'unconfirmed';
  started_at: string;
  finished_at: string | null;
  changed_count: number;
}

interface Dashboard {
  active: AutomationRun[];
  history: AutomationRun[];
  latest_finance_run: AutomationRun | null;
}

interface InvoiceChange {
  id: number;
  invoice_id: string;
  invoice_number: string | null;
  invoice_available: boolean;
  before: string | null;
  after: string | null;
  changed_at: string;
}

interface RunDetail {
  run: AutomationRun;
  changes: InvoiceChange[];
  can_open_invoices: boolean;
}

@Component({
  selector: 'app-automation-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DatePipe, DecimalPipe, DiscordBotSettings, NgTemplateOutlet, RouterLink, TableSearch, TitleCasePipe],
  templateUrl: './automation.page.html',
})
export class AutomationPage {
  private readonly api = inject(ApiService);
  private readonly injector = inject(Injector);
  private readonly detailSection = viewChild('detailSection', { read: ElementRef<HTMLElement> });
  private readonly botSettings = viewChild(DiscordBotSettings);
  private readonly refresh = new Subject<void>();
  private readonly updates = merge(this.refresh, timer(0, 5000));

  protected readonly dashboard = signal<Envelope<Dashboard> | null>(null);
  protected readonly detail = signal<Envelope<RunDetail> | null>(null);
  protected readonly historyPage = signal(1);
  protected readonly historySearch = signal('');
  protected readonly selection = signal<{ id: string; page: number; search: string } | null>(null);
  protected readonly dashboardError = signal('');
  protected readonly detailError = signal('');

  private readonly statusLabels: Record<AutomationRun['status'], string> = {
    running: 'Running',
    completed: 'Completed',
    failed: 'Failed',
    unconfirmed: 'Activity unconfirmed',
  };

  constructor() {
    toObservable(computed(() => ({ page: this.historyPage(), search: this.historySearch().trim() })))
      .pipe(
        debounceTime(200),
        switchMap((query) =>
          this.updates.pipe(
            exhaustMap(() =>
              this.api.envelope<Dashboard>('automation/runs', query).pipe(
                catchError((error: unknown) => {
                  if (error instanceof HttpErrorResponse && [401, 403, 404].includes(error.status)) {
                    this.dashboard.set(null);
                    this.selection.set(null);
                    this.detail.set(null);
                    this.dashboardError.set('You do not have access to this automation dashboard.');
                  } else {
                    this.dashboardError.set('Could not refresh the automation dashboard.');
                  }
                  return EMPTY;
                }),
              ),
            ),
          ),
        ),
        takeUntilDestroyed(),
      )
      .subscribe((result) => {
        this.dashboard.set(result);
        this.dashboardError.set('');
      });

    toObservable(this.selection)
      .pipe(
        debounceTime(200),
        switchMap((selection) =>
          selection === null
            ? EMPTY
            : this.updates.pipe(
                exhaustMap(() =>
                  this.api
                    .envelope<RunDetail>('automation/runs/' + selection.id, {
                      page: selection.page,
                      search: selection.search.trim(),
                    })
                    .pipe(
                      catchError((error: unknown) => {
                        if (
                          error instanceof HttpErrorResponse &&
                          [401, 403, 404].includes(error.status)
                        ) {
                          this.detail.set(null);
                        }
                        this.detailError.set('Could not refresh the run details.');
                        return EMPTY;
                      }),
                    ),
                ),
              ),
        ),
        takeUntilDestroyed(),
      )
      .subscribe((result) => {
        this.detail.set(result);
        this.detailError.set('');
      });
  }

  protected refreshNow(): void {
    this.refresh.next();
    this.botSettings()?.refresh();
  }

  protected selectRun(id: string): void {
    if (id !== this.selection()?.id) {
      this.detail.set(null);
      this.detailError.set('');
      this.selection.set({ id, page: 1, search: '' });
    }

    afterNextRender(() => this.detailSection()?.nativeElement.focus(), { injector: this.injector });
  }

  protected readonly moveHistory = (page: number): void => this.historyPage.set(page);

  protected searchHistory(search: string): void {
    this.historySearch.set(search);
    this.historyPage.set(1);
  }

  protected searchDetail(search: string): void {
    this.selection.update((current) => current && { ...current, page: 1, search });
  }

  protected readonly moveDetail = (page: number): void => {
    const selected = this.selection();
    if (selected) this.selection.set({ ...selected, page });
  };

  protected pageCount(result: Envelope<unknown>): number {
    const total = Number(result.meta?.['total'] ?? 0);
    const perPage = Number(result.meta?.['per_page'] ?? 1);
    return Math.max(1, Math.ceil(total / perPage));
  }

  protected workflowLabel(run: AutomationRun): string {
    return run.workflow === 'overdue-invoices' ? 'Overdue invoice check' : run.workflow;
  }

  protected statusLabel(run: AutomationRun): string {
    return this.statusLabels[run.status];
  }
}

import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { Subscription } from 'rxjs';

import { Employee } from '../../models/hr/hr.model';
import { ListMeta } from '../../models/shared/envelope.model';
import { EmployeeService } from '../../services/hr/employee.service';
import { CompanyService } from '../../services/identity/company.service';
import { IdentityService } from '../../services/identity/identity.service';
import { toFormData } from '../../shared/record-form-spec';
import { Card } from '../../shared/card';
import { Icon } from '../../shared/icon';

type Agency = 'sss_enrolled' | 'philhealth_enrolled' | 'pagibig_enrolled';

/**
 * Whether payroll takes SSS, PhilHealth and Pag-IBIG — for the firm, and for
 * each person.
 *
 * Two levels, and the card keeps them apart on purpose. The switch at the top
 * is the firm's: off, no payslip carries a contribution, employee or employer
 * share. It does **not** rewrite anybody — each person's ticks below survive,
 * so switching back on returns everybody to what they had.
 *
 * The ticks are the same three flags as the employee form, saved on the spot,
 * so "only these people have benefits" is a matter of unticking the rest. A
 * change reaches the next pay run worked out; a run already approved keeps
 * what it froze.
 *
 * Withholding tax is not a benefit and is not on this card: it is owed on
 * taxable pay either way.
 */
@Component({
  selector: 'app-benefits-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Icon],
  template: `
    <app-card heading="Benefits" icon="badge" hint="SSS, PhilHealth and Pag-IBIG">
      @if (enabled() !== null) {
        <fieldset [disabled]="busy()">
          <legend class="text-[13px] font-semibold">Take contributions on payroll?</legend>
          <div class="mt-2 inline-flex rounded-control border border-cr-line p-0.5" role="group">
            @for (option of switches; track option.on) {
              <button
                type="button"
                class="h-9 rounded-control px-3 text-[13px] font-semibold transition-colors disabled:opacity-60"
                [class]="
                  option.on === enabled()
                    ? 'bg-cr-tint text-cr-blue'
                    : 'text-cr-ink-muted hover:bg-cr-tint'
                "
                [attr.aria-pressed]="option.on === enabled()"
                (click)="setEnabled(option.on)"
              >
                {{ option.label }}
              </button>
            }
          </div>
        </fieldset>

        <p class="cr-meta mt-2">
          @if (enabled()) {
            Each person below pays only the contributions ticked for them, and the company adds its
            share on those. Withholding tax still comes off.
          } @else {
            Benefits are off: no SSS, PhilHealth or Pag-IBIG comes off anybody's pay and the company
            adds no share. Withholding tax still comes off. The ticks below are kept for when
            benefits are turned back on.
          }
        </p>

        @if (canEditPeople) {
          <hr class="my-4 border-cr-line" />

          <div class="flex flex-wrap items-center gap-2">
            <p class="mr-auto text-[13px] font-semibold">
              Who has benefits
              <span class="cr-meta font-normal">· {{ total() }} active staff</span>
            </p>
            <label class="relative w-full sm:w-[280px]">
              <span class="sr-only">Search staff</span>
              <app-icon
                name="search"
                [size]="15"
                class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-cr-ink-muted"
              />
              <input
                type="search"
                placeholder="Search staff"
                class="h-9 w-full rounded-control border border-cr-line bg-cr-surface pr-3 pl-9 text-[13px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none"
                [value]="search()"
                (input)="setSearch($event)"
              />
            </label>
          </div>

          <div class="mt-2 overflow-x-auto" [class.opacity-60]="!enabled()">
            <table class="w-full min-w-[560px] text-[13px]">
              <thead>
                <tr class="cr-meta text-left">
                  <th class="py-2 pr-2 font-medium">Person</th>
                  @for (agency of agencies; track agency.key) {
                    <th class="px-2 py-2 text-center font-medium">{{ agency.label }}</th>
                  }
                  <th class="py-2 pl-2 text-right font-medium">All</th>
                </tr>
              </thead>
              <tbody [class.opacity-60]="loadingPeople()">
                @for (person of people(); track person.id) {
                  <tr class="border-t border-cr-line">
                    <td class="py-2 pr-2">
                      <span class="font-medium">{{ person.full_name }}</span>
                      <span class="cr-meta block"
                        >{{ person.position }} · {{ person.employee_no }}</span
                      >
                    </td>
                    @for (agency of agencies; track agency.key) {
                      <td class="px-2 py-2 text-center">
                        <input
                          type="checkbox"
                          class="h-4 w-4 accent-cr-blue"
                          [checked]="person[agency.key]"
                          [disabled]="saving().has(person.id)"
                          [attr.aria-label]="agency.label + ' for ' + person.full_name"
                          (change)="toggle(person, agency.key, $event)"
                        />
                      </td>
                    }
                    <td class="py-2 pl-2 text-right">
                      <button
                        type="button"
                        class="text-[12px] font-semibold text-cr-blue hover:underline disabled:opacity-60"
                        [disabled]="saving().has(person.id)"
                        (click)="setFlags(person, all(!hasAll(person)))"
                      >
                        {{ hasAll(person) ? 'None' : 'All' }}
                      </button>
                    </td>
                  </tr>
                } @empty {
                  <tr>
                    <td class="cr-meta py-3" [attr.colspan]="agencies.length + 2">
                      {{ loadingPeople() ? 'Loading staff…' : 'Nobody matches.' }}
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>

          <!-- The same pager the shared table uses, so the two read alike. -->
          <div
            class="mt-1 flex flex-wrap items-center justify-between gap-3 border-t border-cr-line pt-3"
          >
            <label class="flex items-center gap-2 text-[12px] text-cr-ink-muted">
              <span>Rows</span>
              <select
                [value]="size()"
                (change)="setSize($event)"
                class="h-8 rounded-control border border-cr-line bg-cr-surface px-2 text-[13px] text-cr-ink focus:border-cr-blue focus:outline-none"
              >
                @for (option of sizes; track option) {
                  <option [value]="option" [selected]="option === size()">{{ option }}</option>
                }
              </select>
            </label>

            <p class="cr-num text-[12px] text-cr-ink-muted">
              {{ firstShown() }}–{{ lastShown() }} of {{ total() }}
            </p>

            <div class="flex items-center gap-1">
              <button
                type="button"
                class="flex h-8 w-8 items-center justify-center rounded-control text-cr-ink-muted transition-colors hover:bg-cr-tint disabled:opacity-40"
                aria-label="Previous page"
                [disabled]="page() === 1 || loadingPeople()"
                (click)="goTo(page() - 1)"
              >
                <app-icon name="chevron-left" [size]="16" />
              </button>
              <span class="cr-num px-1 text-[12px] text-cr-ink-muted">
                {{ page() }} / {{ pageCount() }}
              </span>
              <button
                type="button"
                class="flex h-8 w-8 items-center justify-center rounded-control text-cr-ink-muted transition-colors hover:bg-cr-tint disabled:opacity-40"
                aria-label="Next page"
                [disabled]="page() >= pageCount() || loadingPeople()"
                (click)="goTo(page() + 1)"
              >
                <app-icon name="chevron-right" [size]="16" />
              </button>
            </div>
          </div>

          <p class="cr-meta mt-2">
            Ticks save straight away and apply to the next pay run worked out. An approved run keeps
            what it was worked out with.
          </p>
        }

        @if (failure(); as message) {
          <p role="alert" class="mt-3 text-[12px] font-medium text-cr-red">{{ message }}</p>
        }
      } @else {
        <p class="cr-meta">Loading benefits…</p>
      }
    </app-card>
  `,
})
export class BenefitsCard {
  private readonly companyApi = inject(CompanyService);
  private readonly employees = inject(EmployeeService);

  protected readonly canEditPeople = inject(IdentityService).has('hr.manage');

  protected readonly enabled = signal<boolean | null>(null);
  protected readonly busy = signal(false);
  protected readonly failure = signal<string | null>(null);

  /**
   * One page of the roster, paged and searched by the API rather than here —
   * a card on the settings screen should not have to load every employee to
   * show ten of them.
   */
  protected readonly people = signal<Employee[]>([]);
  protected readonly loadingPeople = signal(true);
  protected readonly saving = signal<ReadonlySet<string>>(new Set());
  protected readonly search = signal('');
  protected readonly page = signal(1);
  protected readonly size = signal(10);
  protected readonly total = signal(0);

  protected readonly sizes = [10, 25, 50, 100] as const;

  protected readonly pageCount = computed(() => Math.max(1, Math.ceil(this.total() / this.size())));
  protected readonly firstShown = computed(() =>
    this.total() === 0 ? 0 : (this.page() - 1) * this.size() + 1,
  );
  protected readonly lastShown = computed(() => Math.min(this.total(), this.page() * this.size()));

  private searchTimer: ReturnType<typeof setTimeout> | undefined;
  private request: Subscription | undefined;

  protected readonly switches = [
    { on: true, label: 'On' },
    { on: false, label: 'Off for everyone' },
  ];

  protected readonly agencies: { key: Agency; label: string }[] = [
    { key: 'sss_enrolled', label: 'SSS' },
    { key: 'philhealth_enrolled', label: 'PhilHealth' },
    { key: 'pagibig_enrolled', label: 'Pag-IBIG' },
  ];

  constructor() {
    this.companyApi.show().subscribe({
      next: (company) => this.enabled.set(company.payroll_benefits_enabled),
      error: () => this.failure.set('Could not load the benefits setting.'),
    });

    if (this.canEditPeople) this.fetch();

    inject(DestroyRef).onDestroy(() => {
      clearTimeout(this.searchTimer);
      this.request?.unsubscribe();
    });
  }

  protected setSearch(event: Event): void {
    this.search.set((event.target as HTMLInputElement).value);

    // Wait for a pause in the typing rather than asking once per keystroke.
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.goTo(1), 300);
  }

  protected setSize(event: Event): void {
    this.size.set(Number((event.target as HTMLSelectElement).value));
    this.goTo(1);
  }

  protected goTo(page: number): void {
    this.page.set(Math.min(Math.max(1, page), this.pageCount()));
    this.fetch();
  }

  private fetch(): void {
    const term = this.search().trim();

    this.loadingPeople.set(true);
    // A slower answer to an older question must not overwrite the newer one.
    this.request?.unsubscribe();
    this.request = this.employees
      .list({
        status: 'active',
        page: this.page(),
        per_page: this.size(),
        ...(term ? { search: term } : {}),
      })
      .subscribe({
        next: (envelope) => {
          const meta = envelope.meta as Partial<ListMeta> | undefined;

          this.people.set(envelope.data);
          this.total.set(meta?.total ?? envelope.data.length);
          this.loadingPeople.set(false);
        },
        error: () => {
          this.loadingPeople.set(false);
          this.failure.set('Could not load the staff list.');
        },
      });
  }

  protected toggle(person: Employee, agency: Agency, event: Event): void {
    const box = event.target as HTMLInputElement;

    this.setFlags(person, { [agency]: box.checked }, () => (box.checked = person[agency]));
  }

  protected hasAll(person: Employee): boolean {
    return this.agencies.every((a) => person[a.key]);
  }

  protected all(on: boolean): Record<Agency, boolean> {
    return { sss_enrolled: on, philhealth_enrolled: on, pagibig_enrolled: on };
  }

  protected setEnabled(on: boolean): void {
    if (on === this.enabled()) return;

    this.busy.set(true);
    this.failure.set(null);

    this.companyApi.updateProfile({ payroll_benefits_enabled: on }).subscribe({
      next: (company) => {
        this.enabled.set(company.payroll_benefits_enabled);
        this.busy.set(false);
      },
      error: () => {
        this.busy.set(false);
        this.failure.set('Could not save the benefits setting.');
      },
    });
  }

  protected setFlags(
    person: Employee,
    flags: Partial<Record<Agency, boolean>>,
    undo?: () => void,
  ): void {
    this.failure.set(null);
    this.mark(person.id, true);

    this.employees.save(toFormData(flags, 'PATCH'), person.id).subscribe({
      next: (saved) => {
        this.people.update((rows) => rows.map((row) => (row.id === saved.id ? saved : row)));
        this.mark(person.id, false);
      },
      error: () => {
        // Put the tick back where the server still has it.
        undo?.();
        this.mark(person.id, false);
        this.failure.set(`Could not save benefits for ${person.full_name}.`);
      },
    });
  }

  private mark(id: string, on: boolean): void {
    this.saving.update((set) => {
      const next = new Set(set);

      if (on) next.add(id);
      else next.delete(id);

      return next;
    });
  }
}

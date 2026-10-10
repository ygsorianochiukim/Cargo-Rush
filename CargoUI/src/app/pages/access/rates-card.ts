import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';

import { Company } from '../../models/identity/identity.model';
import { CompanyService } from '../../services/identity/company.service';
import { Card } from '../../shared/card';
import { Field } from '../../shared/field';

@Component({
  selector: 'app-rates-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Field, ReactiveFormsModule],
  template: `
    <app-card heading="Rates" icon="tag" hint="What you charge, and what you keep">
      @if (company(); as row) {
        <form [formGroup]="form" class="space-y-5">
          <fieldset [disabled]="busy()">
            <legend class="text-[13px] font-semibold">Partner truckers</legend>
            <p class="cr-meta mt-0.5">
              What the fleet keeps of a run an owner-operator hauls. Changes what future runs split
              at — everything already settled keeps the rate it closed out on.
            </p>

            <div class="mt-3 max-w-[200px]">
              <app-field label="COMMISSION %" [error]="errorFor('trucker_commission_bp')">
                <input
                  type="number"
                  step="0.5"
                  min="0"
                  max="50"
                  formControlName="commission"
                  [class]="inputClass"
                />
              </app-field>
            </div>
          </fieldset>

          <hr class="border-cr-line" />

          <fieldset [disabled]="busy()">
            <legend class="text-[13px] font-semibold">Tax and terms</legend>
            <p class="cr-meta mt-0.5">
              Applied to invoices raised from here on. A document already issued froze the rates it
              was raised under and is not touched.
            </p>

            <label class="mt-3 flex items-start gap-2 text-[14px]">
              <input
                type="checkbox"
                class="mt-1 h-4 w-4 flex-none accent-cr-blue"
                formControlName="vatRegistered"
              />
              <span>
                VAT-registered
                <span class="block text-[12px] text-cr-ink-muted">
                  Untick below the registration threshold — invoices then carry no VAT line at all,
                  rather than a rate of zero.
                </span>
              </span>
            </label>

            <div class="mt-3 grid gap-3 sm:grid-cols-3">
              <app-field label="VAT %" [error]="errorFor('vat_rate_bp')">
                <input
                  type="number"
                  min="0"
                  max="50"
                  step="0.5"
                  formControlName="vat"
                  [class]="numberClass"
                />
              </app-field>
              <app-field
                label="WITHHOLDING %"
                hint="A customer with its own rate keeps it."
                [error]="errorFor('withholding_rate_bp')"
              >
                <input
                  type="number"
                  min="0"
                  max="50"
                  step="0.5"
                  formControlName="withholding"
                  [class]="numberClass"
                />
              </app-field>
              <app-field
                label="PAYMENT TERMS (DAYS)"
                hint="How long a delivered run has to pay."
                [error]="errorFor('billing_terms_days')"
              >
                <input
                  type="number"
                  min="0"
                  max="365"
                  step="1"
                  formControlName="terms"
                  [class]="numberClass"
                />
              </app-field>
            </div>

            <label class="mt-3 flex items-start gap-2 text-[14px]">
              <input
                type="checkbox"
                class="mt-1 h-4 w-4 flex-none accent-cr-blue"
                formControlName="pricesIncludeVat"
              />
              <span>
                Prices are quoted all-in
                <span class="block text-[12px] text-cr-ink-muted">
                  Tick where the desk quotes one figure with the VAT already inside it. The net is
                  then worked backwards, so a customer is billed exactly what they were quoted.
                </span>
              </span>
            </label>
          </fieldset>
        </form>

        @if (usingDefaults()) {
          <p class="cr-meta mt-4">
            Every figure here is the system default. Saving adopts them as your own, which only
            matters if the defaults ever move.
          </p>
        } @else {
          <p class="cr-meta mt-4">System default: {{ defaultSummary() }}.</p>
        }

        @if (failure(); as message) {
          <p role="alert" class="mt-3 text-[12px] font-medium text-cr-red">{{ message }}</p>
        }

        @if (saved()) {
          <p class="mt-3 text-[12px] font-medium text-cr-green">Saved.</p>
        }

        <div class="mt-4 flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
            [disabled]="busy() || !dirty()"
            (click)="save()"
          >
            {{ busy() ? 'Saving…' : 'Save rates' }}
          </button>

          <button
            type="button"
            class="h-10 rounded-control border border-cr-line px-4 text-[14px] font-semibold text-cr-ink-muted transition-colors hover:bg-cr-tint disabled:opacity-60"
            [disabled]="busy()"
            (click)="restore()"
          >
            Use the system defaults
          </button>
        </div>
      } @else if (failure(); as message) {
        <p role="alert" class="text-[13px] font-medium text-cr-red">{{ message }}</p>
      } @else {
        <p class="cr-meta">Loading the rates…</p>
      }
    </app-card>
  `,
})
export class RatesCard {
  private readonly companyApi = inject(CompanyService);
  private readonly fb = inject(FormBuilder);

  protected readonly company = signal<Company | null>(null);

  protected readonly busy = signal(false);
  protected readonly saved = signal(false);
  protected readonly failure = signal<string | null>(null);

  protected readonly errors = signal<Record<string, string[]>>({});

  private readonly edits = signal(0);

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink focus:border-cr-blue focus:outline-none disabled:opacity-60';

  protected readonly numberClass = this.inputClass + ' cr-num';

  protected readonly form = this.fb.nonNullable.group({
    commission: [12],
    terms: [30],
    vatRegistered: [true],
    vat: [12],
    withholding: [2],
    pricesIncludeVat: [false],
  });

  protected readonly dirty = computed(() => {
    this.edits();

    const row = this.company();

    if (row === null) return false;

    return JSON.stringify(this.payload()) !== JSON.stringify(this.payloadFor(row.rates));
  });

  protected readonly usingDefaults = computed(() => {
    const row = this.company();

    if (row === null) return true;

    return (
      JSON.stringify(this.payloadFor(row.rates)) ===
      JSON.stringify(this.payloadFor(row.rate_defaults))
    );
  });

  constructor() {
    this.form.valueChanges.subscribe(() => this.edits.update((n) => n + 1));
    this.load();
  }

  protected defaultSummary(): string {
    const row = this.company();

    if (row === null) return '';

    const d = row.rate_defaults;

    return [
      `${this.percent(d.trucker_commission_bp)}% commission`,
      `${this.percent(d.vat_rate_bp)}% VAT`,
      `${d.billing_terms_days} days to pay`,
    ].join(', ');
  }

  protected errorFor(field: string): string | null {
    return this.errors()[field]?.[0] ?? null;
  }

  protected save(): void {
    this.write(this.payload());
  }

  protected restore(): void {
    const row = this.company();

    if (row === null) return;

    this.write({
      trucker_commission_bp: row.rate_defaults.trucker_commission_bp,
      billing_terms_days: null,
      vat_registered: row.rate_defaults.vat_registered,
      vat_rate_bp: null,
      withholding_rate_bp: null,
      prices_include_vat: null,
    });
  }

  private write(attributes: Record<string, unknown>): void {
    this.busy.set(true);
    this.failure.set(null);
    this.errors.set({});
    this.saved.set(false);

    this.companyApi.updateProfile(attributes).subscribe({
      next: (company) => {
        this.adopt(company);
        this.busy.set(false);
        this.saved.set(true);
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.absorb(error);
      },
    });
  }

  private payload(): Record<string, unknown> {
    const value = this.form.getRawValue();

    return {
      trucker_commission_bp: this.bp(value.commission),
      billing_terms_days: Math.round(value.terms),
      vat_registered: value.vatRegistered,
      vat_rate_bp: this.bp(value.vat),
      withholding_rate_bp: this.bp(value.withholding),
      prices_include_vat: value.pricesIncludeVat,
    };
  }

  private payloadFor(rates: Company['rates']): Record<string, unknown> {
    return {
      trucker_commission_bp: rates.trucker_commission_bp,
      billing_terms_days: rates.billing_terms_days,
      vat_registered: rates.vat_registered,
      vat_rate_bp: rates.vat_rate_bp,
      withholding_rate_bp: rates.withholding_rate_bp,
      prices_include_vat: rates.prices_include_vat,
    };
  }

  private load(): void {
    this.companyApi.show().subscribe({
      next: (company) => this.adopt(company),
      error: () => this.failure.set('Could not load the rates.'),
    });
  }

  private adopt(company: Company): void {
    this.company.set(company);

    const rates = company.rates;

    this.form.setValue(
      {
        commission: this.percent(rates.trucker_commission_bp),
        terms: rates.billing_terms_days,
        vatRegistered: rates.vat_registered,
        vat: this.percent(rates.vat_rate_bp),
        withholding: this.percent(rates.withholding_rate_bp),
        pricesIncludeVat: rates.prices_include_vat,
      },
      { emitEvent: false },
    );

    this.edits.update((n) => n + 1);
  }

  private absorb(error: HttpErrorResponse): void {
    if (error.status === 422) {
      this.errors.set((error.error?.errors as Record<string, string[]>) ?? {});
      this.failure.set(error.error?.message ?? 'Some of those figures were not accepted.');

      return;
    }

    if (error.status === 0) {
      this.failure.set('Cannot reach the server.');

      return;
    }

    if (error.status === 403) {
      this.failure.set('This account cannot change the rates.');

      return;
    }

    this.failure.set(error.error?.message ?? `The server refused that request (${error.status}).`);
  }

  private bp(percent: number): number {
    return Math.round((Number(percent) || 0) * 100);
  }

  private percent(bp: number): number {
    return bp / 100;
  }
}

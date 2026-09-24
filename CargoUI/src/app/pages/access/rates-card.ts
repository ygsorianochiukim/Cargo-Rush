import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';

import { Company } from '../../models/identity/identity.model';
import { CompanyService } from '../../services/identity/company.service';
import { Card } from '../../shared/card';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';

/**
 * The rates the office sets — what a haul is charged, what an invoice carries,
 * and what a partner's run is split at.
 *
 * Every figure on this card used to be an environment variable. That meant one
 * tariff, one set of tax rates and one commission for every haulier on the
 * install, and correcting any of them was a deployment — so a firm that wanted
 * to move its per-kilometre rate on a Tuesday afternoon raised a support
 * ticket. They are columns on the company now, and this is the screen.
 *
 * ## Where the commission went
 *
 * It was a number field on each partner's own detail screen, beside an approve
 * button and a wallet, and it is here instead. A commission is a commercial
 * term the firm states once for everybody it hauls with — twelve per cent
 * unless somebody moves it — not something re-typed per partner on a screen
 * about somebody's paperwork. Moving it changes what **future** runs split at;
 * every run already settled froze its own rate at delivery and does not move.
 *
 * ## Percentages on screen, basis points on the wire
 *
 * The API speaks basis points and centavos throughout, because there is no
 * float anywhere near a peso in this system. An office types 12 and ₱35, so
 * this card divides on the way in and multiplies on the way out, and it is the
 * only place that conversion happens.
 *
 * ## What it does not offer
 *
 * The rate card — a zone, a truck class and a diesel step — which is a table
 * with its own screen under Pricing. The tariff here is only what answers when
 * no line of that table covers a run.
 *
 * And the payroll contributions. SSS, PhilHealth and Pag-IBIG are the
 * government's figures, the same for every firm on the platform, and a form
 * inviting an office to edit them would be a form inviting an office to get
 * payroll wrong. They stay in configuration.
 */
@Component({
  selector: 'app-rates-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Field, ReactiveFormsModule],
  template: `
    <app-card heading="Rates" icon="tag" hint="What you charge, and what you keep">
      @if (company(); as row) {
        <form [formGroup]="form" class="space-y-5">
          <!--
            The commission first, because it is the figure somebody came to
            this screen for. One rate for every partner the firm hauls with.
          -->
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

          <!--
            The fallback tariff. Named as the fallback on the card itself,
            because an office that reads this as "our prices" and finds its
            quotes unchanged has been misled by the heading rather than by the
            arithmetic — the rate card prices most runs.
          -->
          <fieldset [disabled]="busy()">
            <legend class="text-[13px] font-semibold">Tariff</legend>
            <p class="cr-meta mt-0.5">
              What a run is quoted when no rate-card band covers it:
              <span class="font-medium text-cr-ink">base + per km + per kg</span>, never below the
              minimum.
            </p>

            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <app-field label="BASE (₱)" [error]="errorFor('tariff_base_cents')">
                <input
                  type="number"
                  min="0"
                  step="1"
                  formControlName="base"
                  [class]="numberClass"
                />
              </app-field>
              <app-field label="PER KM (₱)" [error]="errorFor('tariff_per_km_cents')">
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  formControlName="perKm"
                  [class]="numberClass"
                />
              </app-field>
              <app-field label="PER KG (₱)" [error]="errorFor('tariff_per_kg_cents')">
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  formControlName="perKg"
                  [class]="numberClass"
                />
              </app-field>
              <app-field label="MINIMUM (₱)" [error]="errorFor('tariff_minimum_cents')">
                <input
                  type="number"
                  min="0"
                  step="1"
                  formControlName="minimum"
                  [class]="numberClass"
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

        <!--
          What the install would say instead.

          Shown only where the firm has departed from it, and as a sentence
          rather than a second column of numbers — the point is to let somebody
          recognise a figure they did not mean to change, not to teach them the
          defaults.
        -->
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

  /** The last thing the server said. Everything on screen starts from it. */
  protected readonly company = signal<Company | null>(null);

  protected readonly busy = signal(false);
  protected readonly saved = signal(false);
  protected readonly failure = signal<string | null>(null);

  /** Field-by-field messages from a 422, keyed by the API's own field names. */
  protected readonly errors = signal<Record<string, string[]>>({});

  /** Bumped whenever the form changes, so `dirty()` recomputes. */
  private readonly edits = signal(0);

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink focus:border-cr-blue focus:outline-none disabled:opacity-60';

  protected readonly numberClass = this.inputClass + ' cr-num';

  /**
   * Pesos and percentages, which is what an office types.
   *
   * The wire carries centavos and basis points; the conversion is here and
   * nowhere else. Nulls are not possible on this form — every control has a
   * figure in it from the moment the company loads, because every one of these
   * rates is always in force.
   */
  protected readonly form = this.fb.nonNullable.group({
    commission: [12],
    base: [0],
    perKm: [0],
    perKg: [0],
    minimum: [0],
    terms: [30],
    vatRegistered: [true],
    vat: [12],
    withholding: [2],
    pricesIncludeVat: [false],
  });

  /** Nothing to save until something differs from what the server holds. */
  protected readonly dirty = computed(() => {
    this.edits();

    const row = this.company();

    if (row === null) return false;

    return JSON.stringify(this.payload()) !== JSON.stringify(this.payloadFor(row.rates));
  });

  /** Is the firm on the install's figures in every respect? */
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

  /**
   * The install's answer, in one line.
   *
   * Only the figures somebody is likely to be checking themselves against —
   * the tariff formula, the cut and the terms. A full restatement of every
   * setting would be the form again, in prose, under the form.
   */
  protected defaultSummary(): string {
    const row = this.company();

    if (row === null) return '';

    const d = row.rate_defaults;

    return [
      `${this.percent(d.trucker_commission_bp)}% commission`,
      `${fmt.money(d.tariff.base_cents)} + ${fmt.pesos(d.tariff.per_km_cents)}/km + ${fmt.pesos(
        d.tariff.per_kg_cents,
      )}/kg`,
      `minimum ${fmt.money(d.tariff.minimum_cents)}`,
      `${this.percent(d.vat_rate_bp)}% VAT`,
      `${d.billing_terms_days} days to pay`,
    ].join(', ');
  }

  /** The server's own words for a field it refused. */
  protected errorFor(field: string): string | null {
    return this.errors()[field]?.[0] ?? null;
  }

  protected save(): void {
    this.write(this.payload());
  }

  /**
   * Back to the install defaults, which is what a null column means.
   *
   * Nulls rather than the default figures themselves: typing them in would
   * pin the firm to today's numbers and quietly stop it following a correction
   * to the install. The commission is the exception and is sent as a figure —
   * its column is not nullable and there is nothing underneath it.
   */
  protected restore(): void {
    const row = this.company();

    if (row === null) return;

    this.write({
      trucker_commission_bp: row.rate_defaults.trucker_commission_bp,
      tariff_base_cents: null,
      tariff_per_km_cents: null,
      tariff_per_kg_cents: null,
      tariff_minimum_cents: null,
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

  /** What the form holds, in the units the API speaks. */
  private payload(): Record<string, unknown> {
    const value = this.form.getRawValue();

    return {
      trucker_commission_bp: this.bp(value.commission),
      tariff_base_cents: this.cents(value.base),
      tariff_per_km_cents: this.cents(value.perKm),
      tariff_per_kg_cents: this.cents(value.perKg),
      tariff_minimum_cents: this.cents(value.minimum),
      billing_terms_days: Math.round(value.terms),
      vat_registered: value.vatRegistered,
      vat_rate_bp: this.bp(value.vat),
      withholding_rate_bp: this.bp(value.withholding),
      prices_include_vat: value.pricesIncludeVat,
    };
  }

  /**
   * The same shape, built from a set of rates rather than from the form.
   *
   * What makes "is this dirty" and "is this the default" one comparison each
   * instead of ten, and — more to the point — makes them the *same* comparison,
   * so a field added to one cannot be forgotten in the other.
   */
  private payloadFor(rates: Company['rates']): Record<string, unknown> {
    return {
      trucker_commission_bp: rates.trucker_commission_bp,
      tariff_base_cents: rates.tariff.base_cents,
      tariff_per_km_cents: rates.tariff.per_km_cents,
      tariff_per_kg_cents: rates.tariff.per_kg_cents,
      tariff_minimum_cents: rates.tariff.minimum_cents,
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

  /**
   * Redraw from what the server actually stored, never from what was sent.
   *
   * A restore sends nulls and gets the install defaults back; a save of 12.005%
   * gets 1200 basis points back and should read as 12. A form that kept its own
   * version of the answer is the form that shows a setting the server does not
   * have.
   */
  private adopt(company: Company): void {
    this.company.set(company);

    const rates = company.rates;

    this.form.setValue(
      {
        commission: this.percent(rates.trucker_commission_bp),
        base: this.pesos(rates.tariff.base_cents),
        perKm: this.pesos(rates.tariff.per_km_cents),
        perKg: this.pesos(rates.tariff.per_kg_cents),
        minimum: this.pesos(rates.tariff.minimum_cents),
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

  /** A 422's field messages, or one sentence for everything else. */
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

  private cents(pesos: number): number {
    return Math.round((Number(pesos) || 0) * 100);
  }

  private percent(bp: number): number {
    return bp / 100;
  }

  private pesos(cents: number): number {
    return cents / 100;
  }
}

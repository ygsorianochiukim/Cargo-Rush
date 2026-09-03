import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormArray, FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';

import {
  BracketPayload,
  DieselState,
  PricingZone,
  QuoteBreakdown,
} from '../../models/pricing/pricing.model';
import { PricingService } from '../../services/pricing/pricing.service';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { ListToolbar } from '../../shared/list-toolbar';
import { ErrorState, SkeletonRows } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';

/**
 * Rate Card — the zone editor.
 *
 * Not built on `recordList`/`RecordSpec` like the other list modules, and
 * deliberately so: those render a flat record in a dialog, and a rate card is a
 * zone plus a variable number of bracket rows edited together. The same
 * reasoning already keeps the trip form and the ledger form out of the generic
 * renderer.
 *
 * The layout follows what somebody actually does here. The pump price and the
 * swing it is causing sit at the top, because that is the number that makes
 * every card on the page stale. The preview sits beside them, because the only
 * way to know a card is right is to ask it what a real run costs — rates are a
 * thing people get wrong in the third decimal place, and a customer should not
 * be the one who finds out.
 */
@Component({
  selector: 'app-pricing',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    Card,
    Field,
    Icon,
    ListToolbar,
    ErrorState,
    SkeletonRows,
    StatusPill,
    ReactiveFormsModule,
  ],
  templateUrl: './pricing.page.html',
})
export class PricingPage {
  private readonly pricingApi = inject(PricingService);
  private readonly fb = inject(FormBuilder);
  private readonly confirm = inject(Confirm);

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  /** Null means still loading — the four list states depend on telling that apart. */
  protected readonly zones = signal<PricingZone[] | null>(null);
  protected readonly loadError = signal<string | null>(null);
  protected readonly selectedId = signal<string | null>(null);
  protected readonly saving = signal(false);
  protected readonly saveError = signal<string | null>(null);

  protected readonly diesel = signal<DieselState | null>(null);
  protected readonly quote = signal<QuoteBreakdown | null>(null);
  protected readonly quoting = signal(false);

  protected readonly selected = computed(
    () => this.zones()?.find((zone) => zone.id === this.selectedId()) ?? null,
  );

  /** True while a zone that has never been saved is being filled in. */
  protected readonly creating = signal(false);

  protected readonly form: FormGroup = this.fb.group({
    name: ['', Validators.required],
    code: ['', Validators.required],
    aliases: [''],
    status: ['active'],
    diesel_baseline_cents: [null as number | null],
    notes: [''],
    brackets: this.fb.array([]),
  });

  protected readonly dieselForm = this.fb.group({
    price: [0, [Validators.required, Validators.min(0.01)]],
    source: [''],
  });

  protected readonly previewForm = this.fb.group({
    destination: [''],
    distance_km: [0],
    weight_kg: [0],
  });

  constructor() {
    this.refresh();
    this.refreshDiesel();
  }

  protected get brackets(): FormArray<FormGroup> {
    return this.form.get('brackets') as FormArray<FormGroup>;
  }

  protected refresh(): void {
    this.pricingApi.list().subscribe({
      next: (response) => {
        this.zones.set(response.data);
        this.loadError.set(null);

        // Keep whatever was open, or open the first card so the editor is
        // never an empty panel beside a populated list.
        if (this.selectedId() === null || !response.data.some((z) => z.id === this.selectedId())) {
          const first = response.data[0] ?? null;
          first ? this.select(first) : this.selectedId.set(null);
        }
      },
      error: () => {
        this.zones.set(null);
        this.loadError.set('Could not load the rate card. Check the connection and try again.');
      },
    });
  }

  private refreshDiesel(): void {
    this.pricingApi.diesel().subscribe({
      next: (state) => {
        this.diesel.set(state);
        this.dieselForm.patchValue({
          price: (state.current?.price_per_litre_cents ?? state.baseline_cents) / 100,
        });
      },
      error: () => this.diesel.set(null),
    });
  }

  /* ------------------------------------------------------------ The card */

  protected select(zone: PricingZone): void {
    this.creating.set(false);
    this.selectedId.set(zone.id);
    this.saveError.set(null);

    this.form.reset({
      name: zone.name,
      code: zone.code,
      // Edited as one comma-separated line rather than as a list of inputs:
      // aliases are typed in a burst ("Davao, DVO, Bajada") and a row of
      // add-a-field controls turns that into six clicks.
      aliases: zone.aliases.join(', '),
      status: zone.status,
      diesel_baseline_cents: zone.diesel_baseline_cents ? zone.diesel_baseline_cents / 100 : null,
      notes: zone.notes ?? '',
    });

    this.brackets.clear();
    zone.brackets.forEach((bracket) => this.brackets.push(this.bracketGroup(bracket)));
  }

  protected startNew(): void {
    this.creating.set(true);
    this.selectedId.set(null);
    this.saveError.set(null);

    this.form.reset({
      name: '',
      code: '',
      aliases: '',
      status: 'active',
      diesel_baseline_cents: null,
      notes: '',
    });

    this.brackets.clear();
    // A card with no rows prices nothing, so a new zone starts with the one
    // bracket that covers everything. The office narrows it from there.
    this.brackets.push(
      this.bracketGroup({
        label: 'All distances',
        min_km: 0,
        max_km: null,
        base_cents: 150_000,
        per_km_cents: 3_500,
        per_kg_cents: 0,
        minimum_cents: 150_000,
      }),
    );
  }

  /** Pesos in the form, centavos on the wire — nobody types 150000 for ₱1,500. */
  private bracketGroup(bracket: Partial<BracketPayload>): FormGroup {
    return this.fb.group({
      id: [bracket.id ?? null],
      label: [bracket.label ?? '', Validators.required],
      min_km: [bracket.min_km ?? 0, [Validators.required, Validators.min(0)]],
      max_km: [bracket.max_km ?? null],
      base: [(bracket.base_cents ?? 0) / 100],
      per_km: [(bracket.per_km_cents ?? 0) / 100],
      per_kg: [(bracket.per_kg_cents ?? 0) / 100],
      minimum: [(bracket.minimum_cents ?? 0) / 100],
    });
  }

  protected addBracket(): void {
    const last = this.brackets.at(this.brackets.length - 1);
    // A new row starts where the last one ended, because a card is a sequence
    // and the alternative is an overlap the API will refuse.
    const from = last ? Number(last.get('max_km')?.value ?? last.get('min_km')?.value ?? 0) : 0;

    this.brackets.push(
      this.bracketGroup({
        label: `From ${from} km`,
        min_km: from,
        max_km: null,
        base_cents: 0,
        per_km_cents: 0,
        per_kg_cents: 0,
        minimum_cents: 0,
      }),
    );
  }

  protected removeBracket(index: number): void {
    this.brackets.removeAt(index);
    this.brackets.markAsDirty();
  }

  protected save(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }

    this.saving.set(true);
    this.saveError.set(null);

    const values = this.form.getRawValue() as Record<string, unknown>;

    const payload = {
      name: String(values['name']),
      code: String(values['code']),
      aliases: String(values['aliases'] ?? '')
        .split(',')
        .map((alias) => alias.trim())
        .filter((alias) => alias !== ''),
      status: values['status'] as PricingZone['status'],
      diesel_baseline_cents: values['diesel_baseline_cents']
        ? Math.round(Number(values['diesel_baseline_cents']) * 100)
        : null,
      notes: String(values['notes'] ?? '') || null,
      brackets: this.brackets.controls.map((group) => {
        const bracket = group.getRawValue() as Record<string, unknown>;

        return {
          id: (bracket['id'] as string | null) ?? null,
          label: String(bracket['label']),
          min_km: Number(bracket['min_km'] ?? 0),
          // An empty upper bound is the open-ended top bracket, not a zero.
          max_km:
            bracket['max_km'] === null || bracket['max_km'] === ''
              ? null
              : Number(bracket['max_km']),
          base_cents: Math.round(Number(bracket['base'] ?? 0) * 100),
          per_km_cents: Math.round(Number(bracket['per_km'] ?? 0) * 100),
          per_kg_cents: Math.round(Number(bracket['per_kg'] ?? 0) * 100),
          minimum_cents: Math.round(Number(bracket['minimum'] ?? 0) * 100),
        };
      }),
    };

    const id = this.selectedId();
    const request =
      this.creating() || id === null
        ? this.pricingApi.create(payload)
        : this.pricingApi.update(id, payload);

    request.subscribe({
      next: (zone) => {
        this.saving.set(false);
        this.creating.set(false);
        this.selectedId.set(zone.id);
        this.refresh();
      },
      error: (error: HttpErrorResponse) => {
        this.saving.set(false);
        this.saveError.set(this.messageFor(error));
      },
    });
  }

  /**
   * The API's own validation, readable.
   *
   * Bracket errors arrive keyed by index (`brackets.1.min_km`), and the useful
   * half of those is the message — it already names the bracket it clashes
   * with, which is more use than highlighting a number field.
   */
  private messageFor(error: HttpErrorResponse): string {
    const errors = error.error?.errors as Record<string, string[]> | undefined;

    if (error.status === 422 && errors) {
      return Object.values(errors)[0]?.[0] ?? 'Check the figures on this card.';
    }

    return error.error?.message ?? 'Could not save this card. Check the connection and try again.';
  }

  protected async remove(zone: PricingZone): Promise<void> {
    const ok = await this.confirm.ask({
      title: `Delete ${zone.name}?`,
      body: 'New bookings here fall back to the standard tariff. Trips already priced keep their figure.',
      confirmLabel: 'Delete zone',
      danger: true,
    });

    if (!ok) return;

    this.pricingApi.remove(zone.id).subscribe({
      next: () => {
        this.selectedId.set(null);
        this.refresh();
      },
      error: () => this.saveError.set('Could not delete this zone.'),
    });
  }

  /* ---------------------------------------------------------- Pump price */

  protected recordDiesel(): void {
    if (this.dieselForm.invalid) {
      this.dieselForm.markAllAsTouched();

      return;
    }

    const { price, source } = this.dieselForm.getRawValue();

    this.pricingApi
      .recordDiesel({
        price_per_litre_cents: Math.round(Number(price ?? 0) * 100),
        source: source || null,
      })
      .subscribe({
        next: () => {
          this.refreshDiesel();
          // Every card's quote just moved, so a preview on screen is stale.
          this.preview();
        },
        error: () => this.saveError.set('Could not record that pump price.'),
      });
  }

  /** The adjustment as a percentage, signed, for reading rather than arithmetic. */
  protected readonly adjustmentPct = computed(() => {
    const bp = this.diesel()?.adjustment_bp ?? 0;

    return `${bp >= 0 ? '+' : ''}${(bp / 100).toFixed(2)}%`;
  });

  protected readonly surcharging = computed(() => (this.diesel()?.adjustment_bp ?? 0) > 0);
  protected readonly discounting = computed(() => (this.diesel()?.adjustment_bp ?? 0) < 0);

  /* ------------------------------------------------------------- Preview */

  protected preview(): void {
    const { destination, distance_km, weight_kg } = this.previewForm.getRawValue();

    if (!destination) {
      this.quote.set(null);

      return;
    }

    this.quoting.set(true);

    this.pricingApi
      .quote({
        destination,
        distance_km: Number(distance_km ?? 0),
        weight_kg: Number(weight_kg ?? 0),
      })
      .subscribe({
        next: (breakdown) => {
          this.quote.set(breakdown);
          this.quoting.set(false);
        },
        error: () => {
          this.quote.set(null);
          this.quoting.set(false);
        },
      });
  }
}

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormArray, FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';

import {
  BracketPayload,
  DieselState,
  PricingZone,
  QuoteBreakdown,
  TruckCategory,
} from '../../models/pricing/pricing.model';
import { PricingService, RateCardService } from '../../services/pricing/pricing.service';
import { CbmCard } from './cbm-card';
import { DistanceCard } from './distance-card';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { ListToolbar } from '../../shared/list-toolbar';
import { ErrorState, SkeletonRows } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';

@Component({
  selector: 'app-pricing',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    Card,
    CbmCard,
    DistanceCard,
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
  private readonly rateCard = inject(RateCardService);
  private readonly fb = inject(FormBuilder);
  private readonly confirm = inject(Confirm);

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly zones = signal<PricingZone[] | null>(null);
  protected readonly loadError = signal<string | null>(null);
  protected readonly selectedId = signal<string | null>(null);
  protected readonly saving = signal(false);
  protected readonly saveError = signal<string | null>(null);

  protected readonly diesel = signal<DieselState | null>(null);
  protected readonly quote = signal<QuoteBreakdown | null>(null);
  protected readonly quoting = signal(false);

  protected readonly categories = signal<TruckCategory[]>([]);

  protected readonly previewZoneId = signal<string | null>(null);

  protected readonly selected = computed(
    () => this.zones()?.find((zone) => zone.id === this.selectedId()) ?? null,
  );

  protected readonly creating = signal(false);

  private readonly typed = signal<{ code: string; name: string; from: number; to: number | null }>({
    code: '',
    name: '',
    from: 0,
    to: null,
  });

  protected readonly duplicate = computed(() => {
    const { code, name } = this.typed();
    const mine = this.selectedId();
    const others = (this.zones() ?? []).filter((zone) => zone.id !== mine);

    const sameCode = others.find((zone) => zone.code.toUpperCase() === code.trim().toUpperCase());
    if (sameCode) {
      return { field: 'code' as const, zone: sameCode };
    }

    const sameName = others.find(
      (zone) => zone.name.trim().toLowerCase() === name.trim().toLowerCase(),
    );

    return sameName ? { field: 'name' as const, zone: sameName } : null;
  });

  protected readonly overlapping = computed(() => {
    const { from, to } = this.typed();
    const mine = this.selectedId();
    const end = to === null ? Number.POSITIVE_INFINITY : to + 1;

    return (this.zones() ?? []).filter((zone) => {
      if (zone.id === mine) return false;

      const otherEnd = zone.max_km ?? Number.POSITIVE_INFINITY;

      return zone.min_km < end && from < otherEnd;
    });
  });

  protected readonly duplicateClasses = computed(() => {
    const seen = new Set<string>();
    const twice = new Set<string>();

    for (const id of this.rateClassIds()) {
      seen.has(id) ? twice.add(id) : seen.add(id);
    }

    return twice;
  });

  protected readonly rateClassIds = signal<string[]>([]);

  protected readonly blocked = computed(
    () => this.duplicate() !== null || this.duplicateClasses().size > 0,
  );

  protected blockedReason(): string {
    const clash = this.duplicate();

    if (clash !== null) {
      return clash.field === 'code'
        ? `Band ${clash.zone.code} already uses this code. Open that band and edit it rather than adding a second.`
        : `“${clash.zone.name}” is already a band. Open it and edit it rather than adding a second.`;
    }

    return 'Two rate rows price the same class of truck. A band prices each class once.';
  }

  protected readonly form: FormGroup = this.fb.group({
    name: ['', Validators.required],
    code: ['', Validators.required],
    min_km: [0, [Validators.required, Validators.min(0)]],
    max_km: [null as number | null],
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
    distance_km: [0],
    truck_category_id: [''],
  });

  constructor() {
    this.refresh();
    this.refreshDiesel();

    this.reloadCategories();

    this.previewForm.get('distance_km')?.valueChanges.subscribe(() => this.previewZoneId.set(null));

    this.form.valueChanges.subscribe(() => this.readForm());
  }

  private readForm(): void {
    const values = this.form.getRawValue() as Record<string, unknown>;
    const to = values['max_km'];

    this.typed.set({
      code: String(values['code'] ?? ''),
      name: String(values['name'] ?? ''),
      from: Number(values['min_km'] ?? 0),
      to: to === null || to === '' ? null : Number(to),
    });

    this.rateClassIds.set(
      this.brackets.controls.map(
        (group) => String(group.get('truck_category_id')?.value ?? '') || '*',
      ),
    );

    const baseline = Number(values['diesel_baseline_cents'] ?? 0);

    this.rateLines.set({
      baselinePesos: baseline > 0 ? Math.floor(baseline) : null,
      lines: this.brackets.controls.map((group) => ({
        categoryId: String(group.get('truck_category_id')?.value ?? ''),
        label: String(group.get('label')?.value ?? ''),
        baseCents: Math.round(Number(group.get('base')?.value ?? 0) * 100),
        stepCents: Math.round(Number(group.get('diesel_step')?.value ?? 0) * 100),
      })),
    });
  }

  private readonly rateLines = signal<{
    baselinePesos: number | null;
    lines: { categoryId: string; label: string; baseCents: number; stepCents: number }[];
  }>({ baselinePesos: null, lines: [] });

  protected readonly dieselGrid = computed(() => {
    const { baselinePesos, lines } = this.rateLines();
    const state = this.diesel();
    const baseline = baselinePesos ?? Math.floor((state?.baseline_cents ?? 0) / 100);
    const today = state?.current ? Math.floor(state.current.price_per_litre_cents / 100) : null;
    const first = Math.max(baseline, (today ?? baseline + 7) - 7);
    const columns = Array.from({ length: 15 }, (_, offset) => first + offset);
    const priced = (line: { baseCents: number; stepCents: number }) =>
      columns.map((pesos) => line.baseCents + line.stepCents * Math.max(0, pesos - baseline));

    const cardCbm = state?.card_cbm ?? 28;
    const fleet = lines.find((line) => line.categoryId === '');
    const lined = new Set(lines.map((line) => line.categoryId));
    const scaled = fleet
      ? this.categories()
          .filter((category) => category.cbm && category.cbm !== cardCbm && !lined.has(category.id))
          .map((category) => ({
            label: `${category.name} · ${category.cbm} cbm`,
            prices: priced(fleet).map((cents) =>
              Math.round((cents * (category.cbm as number)) / cardCbm),
            ),
          }))
      : [];

    return {
      today,
      columns,
      cardCbm,
      hasScaled: scaled.length > 0,
      rows: [
        ...lines.map((line) => ({ label: line.label, prices: priced(line), scaled: false })),
        ...scaled.map((row) => ({ ...row, scaled: true })),
      ],
    };
  });

  protected readonly fleetToday = computed(() => {
    const zone = this.selected();
    const { baselinePesos, lines } = this.rateLines();
    const fleet = lines.find((line) => line.categoryId === '');
    const state = this.diesel();

    if (!zone || !fleet) return null;

    const baseline = baselinePesos ?? Math.floor((state?.baseline_cents ?? 0) / 100);
    const today = state?.current ? Math.floor(state.current.price_per_litre_cents / 100) : baseline;

    return {
      code: zone.code,
      cents: fleet.baseCents + fleet.stepCents * Math.max(0, today - baseline),
    };
  });

  protected reloadCategories(): void {
    this.rateCard.truckCategories(true).subscribe({
      next: (envelope) => this.categories.set(envelope.data),
      error: () => this.categories.set([]),
    });
  }

  protected get brackets(): FormArray<FormGroup> {
    return this.form.get('brackets') as FormArray<FormGroup>;
  }

  protected refresh(): void {
    this.pricingApi.list().subscribe({
      next: (response) => {
        this.zones.set(response.data);
        this.loadError.set(null);

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


  protected select(zone: PricingZone): void {
    this.creating.set(false);
    this.selectedId.set(zone.id);
    this.saveError.set(null);

    this.form.reset({
      name: zone.name,
      code: zone.code,
      min_km: zone.min_km,
      max_km: zone.max_km === null ? null : zone.max_km - 1,
      status: zone.status,
      diesel_baseline_cents: zone.diesel_baseline_cents ? zone.diesel_baseline_cents / 100 : null,
      notes: zone.notes ?? '',
    });

    this.brackets.clear();
    zone.brackets.forEach((bracket) => this.brackets.push(this.bracketGroup(bracket)));
    this.readForm();
  }

  protected startNew(): void {
    this.creating.set(true);
    this.selectedId.set(null);
    this.saveError.set(null);

    const last = this.zones()?.reduce((furthest, zone) => Math.max(furthest, zone.max_km ?? 0), 0);

    this.form.reset({
      name: '',
      code: '',
      min_km: last ?? 0,
      max_km: null,
      status: 'active',
      diesel_baseline_cents: null,
      notes: '',
    });

    this.brackets.clear();
    this.brackets.push(this.bracketGroup({ label: 'Current fleet', base_cents: 0 }));
    this.readForm();
  }

  private bracketGroup(bracket: Partial<Omit<BracketPayload, 'min_km' | 'max_km'>>): FormGroup {
    return this.fb.group({
      id: [bracket.id ?? null],
      label: [bracket.label ?? '', Validators.required],
      truck_category_id: [bracket.truck_category_id ?? ''],
      base: [(bracket.base_cents ?? 0) / 100],
      diesel_step: [(bracket.diesel_step_cents ?? 0) / 100],
      per_km: [(bracket.per_km_cents ?? 0) / 100],
      per_kg: [(bracket.per_kg_cents ?? 0) / 100],
      minimum: [(bracket.minimum_cents ?? 0) / 100],
    });
  }

  protected addBracket(): void {
    const taken = new Set(
      this.brackets.controls.map((group) => String(group.get('truck_category_id')?.value ?? '')),
    );

    const next = this.categories().find((category) => !taken.has(category.id));
    const last = this.brackets.at(this.brackets.length - 1);

    this.brackets.push(
      this.bracketGroup({
        label: next?.name ?? 'Rate',
        truck_category_id: next?.id ?? null,
        diesel_step_cents: Math.round(Number(last?.get('diesel_step')?.value ?? 0) * 100),
      }),
    );

    this.readForm();
  }

  protected removeBracket(index: number): void {
    this.brackets.removeAt(index);
    this.brackets.markAsDirty();
    this.readForm();
  }

  protected classTakenElsewhere(index: number, categoryId: string): boolean {
    return this.brackets.controls.some(
      (group, at) =>
        at !== index && String(group.get('truck_category_id')?.value ?? '') === categoryId,
    );
  }

  protected save(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }

    if (this.blocked()) {
      this.saveError.set(this.blockedReason());

      return;
    }

    this.saving.set(true);
    this.saveError.set(null);

    const values = this.form.getRawValue() as Record<string, unknown>;

    const to = values['max_km'];

    const payload = {
      name: String(values['name']),
      code: String(values['code']),
      min_km: Number(values['min_km'] ?? 0),
      max_km: to === null || to === '' ? null : Number(to) + 1,
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
          truck_category_id: (bracket['truck_category_id'] as string) || null,
          base_cents: Math.round(Number(bracket['base'] ?? 0) * 100),
          diesel_step_cents: Math.round(Number(bracket['diesel_step'] ?? 0) * 100),
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

  private messageFor(error: HttpErrorResponse): string {
    const errors = error.error?.errors as Record<string, string[]> | undefined;

    if (error.status === 422 && errors) {
      return Object.values(errors)[0]?.[0] ?? 'Check the figures on this card.';
    }

    return error.error?.message ?? 'Could not save this card. Check the connection and try again.';
  }

  protected async remove(zone: PricingZone): Promise<void> {
    const ok = await this.confirm.ask({
      title: `Delete ${zone.code} — ${zone.name}?`,
      body:
        `New runs of ${zone.band} will be saved unpriced unless another band covers them. ` +
        'Trips already priced keep their figure.',
      confirmLabel: 'Delete band',
      danger: true,
    });

    if (!ok) return;

    this.pricingApi.remove(zone.id).subscribe({
      next: () => {
        this.selectedId.set(null);
        this.refresh();
      },
      error: () => this.saveError.set('Could not delete this band.'),
    });
  }


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
          this.preview();
        },
        error: () => this.saveError.set('Could not record that pump price.'),
      });
  }

  protected readonly adjustmentPct = computed(() => {
    const bp = this.diesel()?.adjustment_bp ?? 0;

    return `${bp >= 0 ? '+' : ''}${(bp / 100).toFixed(2)}%`;
  });

  protected readonly surcharging = computed(() => (this.diesel()?.adjustment_bp ?? 0) > 0);
  protected readonly discounting = computed(() => (this.diesel()?.adjustment_bp ?? 0) < 0);


  protected preview(): void {
    const { distance_km, truck_category_id } = this.previewForm.getRawValue();

    this.quoting.set(true);

    this.pricingApi
      .quote({
        distance_km: Number(distance_km ?? 0),
        weight_kg: 0,
        truck_category_id: truck_category_id || null,
        pricing_zone_id: this.previewZoneId(),
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

  protected previewIn(zoneId: string): void {
    this.previewZoneId.set(zoneId);
    this.preview();
  }

  protected previewUnpinned(): void {
    this.previewZoneId.set(null);
    this.preview();
  }
}

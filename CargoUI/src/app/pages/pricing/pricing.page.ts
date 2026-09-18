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
import { DistanceCard } from './distance-card';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { ListToolbar } from '../../shared/list-toolbar';
import { ErrorState, SkeletonRows } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';

/**
 * Rate Card — the band editor.
 *
 * A band is a row of a subsidy table: a code, a range of kilometres, and a rate
 * per class of truck. `A1` is 1–40 km at ₱4,165 for the fleet and ₱4,916 for a
 * brand new unit, plus ₱14 for every ₱1/L diesel sits above the card's
 * baseline.
 *
 * Not built on `recordList`/`RecordSpec` like the other list modules, and
 * deliberately so: those render a flat record in a dialog, and a band is a
 * header plus a variable number of rate rows edited together. The same
 * reasoning already keeps the trip form and the ledger form out of the generic
 * renderer.
 *
 * The layout follows what somebody actually does here. The pump price and the
 * swing it is causing sit at the top, because that is the number that makes
 * every band on the page stale. The preview sits beside them, because a card
 * typed from a printed table has to be checked against that table band by
 * band, and a customer should not be the one who finds a figure typed wrong.
 *
 * The preview also has to be able to ask for a *particular* band, not just the
 * one the distance lands in. A table holds A1 beside A2 over the same
 * kilometres at different money, so the quote comes back naming the bands it
 * did not use and the screen offers them — otherwise half of every table is
 * unreachable from the only screen that checks it.
 */
@Component({
  selector: 'app-pricing',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    Card,
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

  /** Null means still loading — the four list states depend on telling that apart. */
  protected readonly zones = signal<PricingZone[] | null>(null);
  protected readonly loadError = signal<string | null>(null);
  protected readonly selectedId = signal<string | null>(null);
  protected readonly saving = signal(false);
  protected readonly saveError = signal<string | null>(null);

  protected readonly diesel = signal<DieselState | null>(null);
  protected readonly quote = signal<QuoteBreakdown | null>(null);
  protected readonly quoting = signal(false);

  /**
   * The classes of unit, for the rate rows and the preview.
   *
   * Active only: a retired class is one the office has stopped running, and
   * offering it would put new prices back onto it.
   */
  protected readonly categories = signal<TruckCategory[]>([]);

  /**
   * The band the preview is pinned to, or null to let the distance decide.
   *
   * Held outside the form because it is set by clicking an alternative on the
   * last answer rather than typed, and because it has to be cleared the moment
   * the distance changes — a band pinned at 30 km is not an answer about a
   * 200 km run.
   */
  protected readonly previewZoneId = signal<string | null>(null);

  protected readonly selected = computed(
    () => this.zones()?.find((zone) => zone.id === this.selectedId()) ?? null,
  );

  /** True while a zone that has never been saved is being filled in. */
  protected readonly creating = signal(false);

  /**
   * What is currently typed in the header fields, as a signal.
   *
   * A reactive form is not a signal, so the duplicate checks below cannot see
   * it without this. Written on every change rather than polled, so the
   * warnings appear as somebody types rather than after they press save.
   */
  private readonly typed = signal<{ code: string; name: string; from: number; to: number | null }>({
    code: '',
    name: '',
    from: 0,
    to: null,
  });

  /**
   * A band already using this code or name.
   *
   * The whole of "prevent a duplicate entry", caught on the form rather than
   * left to the API's 422. Both are refused server-side too — that is what
   * actually guarantees it — but a refusal arriving after somebody has typed a
   * band's worth of rates is a refusal that costs them the typing.
   *
   * The band being edited is excluded, or editing anything would report itself
   * as its own duplicate.
   */
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

  /**
   * The bands this one's kilometres already overlap.
   *
   * A **warning**, not a refusal, and the distinction is the table's own: A1
   * and A2 are both 1–40 km at different money, as are E1 and E2, so a system
   * that blocked an overlapping band could not express half of a subsidy card.
   *
   * But a second band over the same kilometres is far more often a mistake
   * than an A2 — somebody retyping a row they already entered — and it is
   * invisible once saved, because the list is ordered by band and two entries
   * for 0–40 km look like they belong together. So it is said out loud before
   * the save rather than discovered from a quote that came back at the wrong
   * money.
   */
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

  /**
   * A class of truck priced twice in this band.
   *
   * The API refuses it — a band prices each class once, or a quote would
   * depend on row order — and this is what stops somebody reaching that
   * refusal. The select offers the taken ones as disabled rather than hiding
   * them, so a row that already holds one still shows what it holds.
   */
  protected readonly duplicateClasses = computed(() => {
    const seen = new Set<string>();
    const twice = new Set<string>();

    for (const id of this.rateClassIds()) {
      seen.has(id) ? twice.add(id) : seen.add(id);
    }

    return twice;
  });

  /** The class of truck on each rate row, as a signal the checks can read. */
  protected readonly rateClassIds = signal<string[]>([]);

  /** Is this card safe to send? */
  protected readonly blocked = computed(
    () => this.duplicate() !== null || this.duplicateClasses().size > 0,
  );

  /** Why it is not, in the words the office needs. */
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
    weight_kg: [0],
    truck_category_id: [''],
  });

  constructor() {
    this.refresh();
    this.refreshDiesel();

    this.rateCard.truckCategories(true).subscribe({
      next: (envelope) => this.categories.set(envelope.data),
      error: () => this.categories.set([]),
    });

    // A band pinned on the last answer is an answer about that distance. Let
    // it survive a change of distance and the screen quietly shows A1's money
    // against a 200 km run.
    this.previewForm.get('distance_km')?.valueChanges.subscribe(() => this.previewZoneId.set(null));

    // Mirror the header fields and the rate rows into signals, so the
    // duplicate checks run as somebody types rather than on save.
    this.form.valueChanges.subscribe(() => this.readForm());
  }

  /** Copy what is typed into the signals the duplicate checks read. */
  private readForm(): void {
    const values = this.form.getRawValue() as Record<string, unknown>;
    const to = values['max_km'];

    this.typed.set({
      code: String(values['code'] ?? ''),
      name: String(values['name'] ?? ''),
      from: Number(values['min_km'] ?? 0),
      to: to === null || to === '' ? null : Number(to),
    });

    /**
     * `*` for the row that names no class — the fleet as it stands.
     *
     * Kept in the list rather than filtered out, because two rows both left on
     * "Current fleet" is precisely the clash the API refuses: a band prices
     * each class once, and "no class" is one of them. Dropping the blanks
     * would let the commonest duplicate of all through to a 422.
     */
    this.rateClassIds.set(
      this.brackets.controls.map(
        (group) => String(group.get('truck_category_id')?.value ?? '') || '*',
      ),
    );
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
      min_km: zone.min_km,
      // Shown inclusive, the way the printed table writes it, and converted
      // back on save. A field reading 41 beside a document that says 40 is a
      // discrepancy to the only people who check.
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

    // The band after the last one, so typing a table down from A1 does not
    // mean retyping the boundary each time.
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
    // A band with no rate prices nothing, so a new one starts with the line
    // every table has: the rate for the fleet as it stands.
    this.brackets.push(this.bracketGroup({ label: 'Current fleet', base_cents: 0 }));
    this.readForm();
  }

  /**
   * One rate row. Pesos in the form, centavos on the wire — nobody types
   * 416500 for ₱4,165.
   *
   * No kilometres: the band belongs to the zone above, and a row carrying its
   * own copy would be a second place for 1–40 km to be wrong. They are struck
   * off the parameter type too, so a row cannot acquire one by accident on the
   * way through here.
   */
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

  /**
   * Another rate row, for the next class of unit not yet priced.
   *
   * Picked rather than left blank because the API refuses two rows for the same
   * class — a band prices each of them once — and a row defaulting to the one
   * already there would be refused on save with a message about row order.
   */
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
        // The band's own step, since every class in a table shares it — the
        // bases differ, the pesos per litre of diesel do not.
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

  /** Is this class of truck already priced on another row of this band? */
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

    /**
     * A duplicate is refused here as well as by the API.
     *
     * Not belt and braces for its own sake: the button is disabled while
     * `blocked()` holds, and this catches the path that does not go through
     * the button — a form submitted on Enter, or a list that refreshed under
     * somebody and made their code a duplicate while they were typing.
     */
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
      /**
       * Typed inclusive, sent exclusive.
       *
       * The field says 40 because the document says "1 to 40"; the column
       * holds 41 because ranges are half-open and 41 km has to belong to the
       * next band rather than to both or to neither. Converted in one place,
       * here, with `select()` converting back.
       */
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
          // Blank is "the fleet as it stands" — the table's own column — not a
          // missing answer.
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

  /**
   * The API's own validation, readable.
   *
   * Rate errors arrive keyed by index (`brackets.1.base_cents`), and the useful
   * half of those is the message — it already says what clashes with what,
   * which is more use than highlighting a number field.
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
      title: `Delete ${zone.code} — ${zone.name}?`,
      body:
        `Runs of ${zone.band} fall back to the distance card, or to the standard tariff if ` +
        'nothing covers them. Trips already priced keep their figure.',
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
    const { distance_km, weight_kg, truck_category_id } = this.previewForm.getRawValue();

    this.quoting.set(true);

    this.pricingApi
      .quote({
        distance_km: Number(distance_km ?? 0),
        weight_kg: Number(weight_kg ?? 0),
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

  /**
   * Re-quote in one of the bands the distance also falls in.
   *
   * The only way to see A2's money on this screen, since nothing about a
   * distance of 30 km distinguishes A2 from A1.
   */
  protected previewIn(zoneId: string): void {
    this.previewZoneId.set(zoneId);
    this.preview();
  }

  /** Back to letting the distance choose. */
  protected previewUnpinned(): void {
    this.previewZoneId.set(null);
    this.preview();
  }
}

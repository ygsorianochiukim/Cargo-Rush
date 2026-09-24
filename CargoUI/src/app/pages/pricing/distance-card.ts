import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';

import { BracketPayload, PricingBracket, TruckCategory } from '../../models/pricing/pricing.model';
import { RateCardService } from '../../services/pricing/pricing.service';
import { Card } from '../../shared/card';
import { Icon } from '../../shared/icon';

/**
 * The firm's plain distance card — "450 km is ₱5,000".
 *
 * These lines belong to no band and carry their own kilometres. They apply
 * wherever nothing more specific does, which is two situations rather than one:
 *
 *   A firm with **no published table** prices entirely from here, and never
 *   opens the band editor below.
 *
 *   A firm **working from a table** prices from here past its last band. A
 *   subsidy table stopping at 600 km leaves a 700 km run with no band at all,
 *   and a "600 km and beyond" line is what keeps that run off the configured
 *   tariff — which would answer at an unrelated number, quietly, and that is
 *   the worst way to be wrong about money.
 *
 * ## The second column is the kind of truck
 *
 * A freezer run costs more than a dry one over the same distance, and that has
 * nothing to do with the distance. A line can name a truck category, and one
 * that names none prices every kind — which is what every line on a card drawn
 * before categories existed is, so nothing changed underneath anybody.
 *
 * ## Why the whole card saves at once
 *
 * Because that is how it is read and edited: somebody adds a line, corrects a
 * rate on another, deletes a third, and presses save once. The API reconciles
 * by id, so a line that has priced real trips keeps its identity rather than
 * being dropped and recreated — a bracket's id is on every trip it ever priced.
 */
@Component({
  selector: 'app-distance-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Icon],
  template: `
    <app-card
      heading="Distance card"
      icon="tag"
      hint="What a run costs by how far it goes. No place needed."
    >
      @if (rows(); as lines) {
        @if (lines.length === 0) {
          <div class="rounded-control border border-dashed border-cr-line px-4 py-6 text-center">
            <app-icon name="tag" [size]="28" class="text-cr-ink-muted" />
            <p class="mt-2 text-[14px] font-semibold">No distance card yet</p>
            <p class="cr-meta mx-auto mt-1 max-w-md">
              Add a line or two — "within 100 km", "100–500 km" — and every booking is priced from
              them, wherever it is going. Runs no line covers fall back to the system tariff.
            </p>
          </div>
        } @else {
          <div class="overflow-x-auto">
            <table class="w-full border-collapse text-left text-[13px] whitespace-nowrap">
              <thead>
                <tr class="border-b border-cr-line">
                  <th class="py-2 pr-3 font-semibold">Line</th>
                  <th class="py-2 pr-3 font-semibold">From (km)</th>
                  <th class="py-2 pr-3 font-semibold">To (km)</th>
                  <th class="py-2 pr-3 font-semibold">Truck</th>
                  <th class="py-2 pr-3 text-right font-semibold">Base (₱)</th>
                  <th class="py-2 pr-3 text-right font-semibold">Per km</th>
                  <th class="py-2 pr-3 text-right font-semibold">Per kg</th>
                  <th class="py-2"></th>
                </tr>
              </thead>
              <tbody>
                @for (line of lines; track $index) {
                  <tr class="border-b border-cr-line/60">
                    <td class="py-1.5 pr-3">
                      <input
                        [value]="line.label"
                        (input)="edit($index, 'label', $any($event.target).value)"
                        placeholder="100–500 km"
                        [class]="cellClass + ' w-40'"
                      />
                    </td>
                    <td class="py-1.5 pr-3">
                      <input
                        type="number"
                        min="0"
                        [value]="line.min_km"
                        (input)="edit($index, 'min_km', $any($event.target).value)"
                        [class]="cellClass + ' w-24 text-right'"
                      />
                    </td>
                    <td class="py-1.5 pr-3">
                      <!-- Blank is the open-ended top line: "500 km and beyond". -->
                      <input
                        type="number"
                        min="1"
                        placeholder="onwards"
                        [value]="line.max_km ?? ''"
                        (input)="edit($index, 'max_km', $any($event.target).value)"
                        [class]="cellClass + ' w-24 text-right'"
                      />
                    </td>
                    <td class="py-1.5 pr-3">
                      <select
                        [value]="line.truck_category_id ?? ''"
                        (change)="edit($index, 'truck_category_id', $any($event.target).value)"
                        [class]="cellClass + ' w-36'"
                      >
                        <option value="">Any truck</option>
                        @for (category of categories(); track category.id) {
                          <option [value]="category.id">{{ category.name }}</option>
                        }
                      </select>
                    </td>
                    <td class="py-1.5 pr-3">
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        [value]="line.base"
                        (input)="edit($index, 'base', $any($event.target).value)"
                        [class]="cellClass + ' w-28 text-right'"
                      />
                    </td>
                    <td class="py-1.5 pr-3">
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        [value]="line.perKm"
                        (input)="edit($index, 'perKm', $any($event.target).value)"
                        [class]="cellClass + ' w-24 text-right'"
                      />
                    </td>
                    <td class="py-1.5 pr-3">
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        [value]="line.perKg"
                        (input)="edit($index, 'perKg', $any($event.target).value)"
                        [class]="cellClass + ' w-24 text-right'"
                      />
                    </td>
                    <td class="py-1.5 text-right">
                      <button
                        type="button"
                        aria-label="Remove line"
                        class="h-8 w-8 rounded-control text-cr-ink-muted transition-colors hover:bg-cr-red-bg hover:text-cr-red"
                        (click)="removeLine($index)"
                      >
                        <app-icon name="close" [size]="14" class="mx-auto" />
                      </button>
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }

        @if (failure(); as message) {
          <p role="alert" class="mt-3 text-[12px] font-medium text-cr-red">{{ message }}</p>
        }

        @if (saved()) {
          <p role="status" class="mt-3 text-[12px] font-medium text-cr-green">Card saved.</p>
        }

        <div class="mt-4 flex items-center gap-2">
          <button
            type="button"
            class="flex h-9 items-center gap-1.5 rounded-control border border-cr-line px-3 text-[13px] font-semibold transition-colors hover:bg-cr-tint"
            (click)="addLine()"
          >
            <app-icon name="plus" [size]="14" />
            Add line
          </button>

          <button
            type="button"
            class="h-9 rounded-control bg-cr-blue px-4 text-[13px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover disabled:opacity-60"
            [disabled]="busy()"
            (click)="save()"
          >
            {{ busy() ? 'Saving…' : 'Save card' }}
          </button>

          <p class="cr-meta ml-auto">A run no line covers falls back to the system tariff.</p>
        </div>
      } @else {
        <div class="cr-skeleton h-24 w-full"></div>
      }
    </app-card>
  `,
})
export class DistanceCard {
  private readonly rateCard = inject(RateCardService);

  protected readonly cellClass =
    'h-8 rounded-control border border-cr-line bg-cr-surface px-2 text-[13px] focus:border-cr-blue focus:outline-none';

  /**
   * The card as it is being edited, in **pesos**.
   *
   * Centavos on the wire and pesos on screen, converted at both edges rather
   * than anywhere in between — the office types 5000 and means ₱5,000, and a
   * form that made them type 500000 would collect a wrong figure sooner or
   * later.
   */
  protected readonly rows = signal<EditableLine[] | null>(null);
  protected readonly categories = signal<TruckCategory[]>([]);

  protected readonly busy = signal(false);
  protected readonly saved = signal(false);
  protected readonly failure = signal<string | null>(null);

  constructor() {
    this.load();
  }

  protected addLine(): void {
    const lines = this.rows() ?? [];
    const last = lines[lines.length - 1];

    this.rows.set([
      ...lines,
      {
        id: null,
        label: '',
        // Starts where the last one ended, which is what somebody extending a
        // card almost always means — and it keeps the card contiguous, which
        // the API insists on.
        min_km: last?.max_km ?? 0,
        max_km: null,
        truck_category_id: null,
        base: 0,
        perKm: 0,
        perKg: 0,
        minimum: 0,
      },
    ]);

    this.saved.set(false);
  }

  protected removeLine(index: number): void {
    this.rows.set((this.rows() ?? []).filter((_, i) => i !== index));
    this.saved.set(false);
  }

  protected edit(index: number, field: keyof EditableLine, raw: string): void {
    const lines = [...(this.rows() ?? [])];
    const line = { ...lines[index] };

    if (field === 'label') {
      line.label = raw;
    } else if (field === 'truck_category_id') {
      // Blank is "any truck", and null is what the API reads as that.
      line.truck_category_id = raw === '' ? null : raw;
    } else if (field === 'max_km') {
      // Blank means open-ended, which is a real answer for the top line and
      // not the same as zero.
      line.max_km = raw === '' ? null : Number(raw);
    } else {
      (line as unknown as Record<string, number>)[field] = Number(raw);
    }

    lines[index] = line;
    this.rows.set(lines);
    this.saved.set(false);
  }

  protected save(): void {
    this.busy.set(true);
    this.failure.set(null);
    this.saved.set(false);

    const payload: BracketPayload[] = (this.rows() ?? []).map((line) => ({
      id: line.id,
      label: line.label,
      min_km: Number(line.min_km),
      max_km: line.max_km === null ? null : Number(line.max_km),
      truck_category_id: line.truck_category_id,
      base_cents: Math.round(Number(line.base) * 100),
      per_km_cents: Math.round(Number(line.perKm) * 100),
      per_kg_cents: Math.round(Number(line.perKg) * 100),
      minimum_cents: Math.round(Number(line.minimum) * 100),
    }));

    this.rateCard.saveCard(payload).subscribe({
      next: (envelope) => {
        this.busy.set(false);
        this.saved.set(true);
        // Redrawn from what was stored rather than from what was sent: the API
        // assigns ids to new lines and orders them, and a screen holding its
        // own version of the answer is the one that shows a card the server
        // does not have.
        this.adopt(envelope.data);
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.failure.set(this.messageFor(error));
      },
    });
  }

  private load(): void {
    this.rateCard.card().subscribe({
      next: (envelope) => this.adopt(envelope.data),
      error: () => this.failure.set('Could not load the distance card.'),
    });

    // Active only: a retired category is one the office has stopped running,
    // and offering it would put new prices back onto it.
    this.rateCard.truckCategories(true).subscribe({
      next: (envelope) => this.categories.set(envelope.data),
      error: () => this.categories.set([]),
    });
  }

  private adopt(brackets: PricingBracket[]): void {
    this.rows.set(
      brackets.map((bracket) => ({
        id: bracket.id,
        label: bracket.label,
        // A plain line always has kilometres of its own; the null case is a
        // line inside a band, and this card is the lines with no band.
        min_km: bracket.min_km ?? 0,
        max_km: bracket.max_km,
        truck_category_id: bracket.truck_category_id,
        base: bracket.base_cents / 100,
        perKm: bracket.per_km_cents / 100,
        perKg: bracket.per_kg_cents / 100,
        minimum: bracket.minimum_cents / 100,
      })),
    );
  }

  /**
   * The server's own words for a 422.
   *
   * It knows the two things a card can get wrong that only show up at quote
   * time — a line ending before it starts, and two lines covering the same
   * distance for the same kind of truck — and its message names which line.
   */
  private messageFor(error: HttpErrorResponse): string {
    if (error.status === 422) {
      const errors = error.error?.errors as Record<string, string[]> | undefined;

      return (
        Object.values(errors ?? {})[0]?.[0] ?? error.error?.message ?? 'That card was not accepted.'
      );
    }

    if (error.status === 0) return 'Cannot reach the server.';
    if (error.status === 403) return 'This account cannot change the rate card.';

    return error.error?.message ?? `The server refused that request (${error.status}).`;
  }
}

/** One line of the card while it is being edited — money in pesos. */
interface EditableLine {
  id: string | null;
  label: string;
  min_km: number;
  max_km: number | null;
  truck_category_id: string | null;
  base: number;
  perKm: number;
  perKg: number;
  minimum: number;
}

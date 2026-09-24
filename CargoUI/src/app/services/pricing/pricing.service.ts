import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { ApiService } from '../shared/api.service';
import {
  BracketPayload,
  DieselPrice,
  DieselPricePayload,
  DieselState,
  PricingBracket,
  PricingZone,
  PricingZonePayload,
  QuoteBreakdown,
  QuoteRequest,
  TruckCategory,
  TruckCategoryPayload,
} from '../../models/pricing/pricing.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

/** Rate Card — the bands and their rates, the pump price, and the preview. */
@Injectable({ providedIn: 'root' })
export class PricingService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<PricingZone[]>> {
    return this.api.envelope<PricingZone[]>('pricing/zones', query);
  }

  create(payload: PricingZonePayload): Observable<PricingZone> {
    return this.api.post<PricingZone>('pricing/zones', payload);
  }

  update(id: string, payload: Partial<PricingZonePayload>): Observable<PricingZone> {
    return this.api.patch<PricingZone>(`pricing/zones/${id}`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`pricing/zones/${id}`);
  }

  /** What the pump costs, what the cards assume, and the resulting swing. */
  diesel(): Observable<DieselState> {
    return this.api.get<DieselState>('pricing/diesel');
  }

  recordDiesel(payload: DieselPricePayload): Observable<DieselPrice> {
    return this.api.post<DieselPrice>('pricing/diesel', payload);
  }

  /**
   * What a run would be quoted, by which band, and which other bands could
   * have.
   *
   * A POST despite changing nothing, which is now a convention rather than a
   * precaution — the free-text destination that once made a query string a
   * privacy problem is gone, and what goes up is a distance and two ids.
   */
  quote(request: QuoteRequest): Observable<QuoteBreakdown> {
    return this.api.post<QuoteBreakdown>('pricing/quote', request);
  }
}

/**
 * The firm's plain distance card, and the kinds of unit it runs.
 *
 * Kept apart from `PricingService` above, which is the band editor: these are
 * the two halves of a card with **no bands in it** — "450 km is ₱5,000", and
 * "a freezer costs more than a dry van". For a haulier with no published rate
 * table that is the whole card; for one working from a table it is what prices
 * a run past the last band.
 */
@Injectable({ providedIn: 'root' })
export class RateCardService {
  private readonly api = inject(ApiService);

  /** The lines belonging to no band — the card that applies anywhere. */
  card(): Observable<Envelope<PricingBracket[]>> {
    return this.api.envelope<PricingBracket[]>('pricing/card');
  }

  /**
   * The whole card at once, as the editor holds it.
   *
   * A PUT rather than a per-row PATCH because that is how it is edited:
   * somebody adds a line, corrects a rate on another, deletes a third and
   * presses save once. The API reconciles by id, so a line that priced past
   * trips keeps its identity rather than being dropped and recreated.
   */
  saveCard(brackets: BracketPayload[]): Observable<Envelope<PricingBracket[]>> {
    return this.api.putEnvelope<PricingBracket[]>('pricing/card', { brackets });
  }

  truckCategories(activeOnly = false): Observable<Envelope<TruckCategory[]>> {
    return this.api.envelope<TruckCategory[]>(
      'pricing/truck-categories',
      activeOnly ? { active: 1 } : undefined,
    );
  }

  createCategory(payload: TruckCategoryPayload): Observable<TruckCategory> {
    return this.api.post<TruckCategory>('pricing/truck-categories', payload);
  }

  updateCategory(id: string, payload: Partial<TruckCategoryPayload>): Observable<TruckCategory> {
    return this.api.patch<TruckCategory>(`pricing/truck-categories/${id}`, payload);
  }

  /**
   * Remove a category — or retire it, where the rate card or the fleet uses it.
   *
   * The API decides which and answers differently: 204 for a delete, 200 with
   * the retired row otherwise. Deleting one a card line names would widen that
   * line to "any truck" and silently change what a freezer run is quoted, so
   * `deleteEnvelope` is used rather than `deleteItem` — the latter reads
   * `.data` off a body a 204 does not have.
   */
  removeCategory(id: string): Observable<{ retired: TruckCategory | null; reason: string | null }> {
    return this.api.deleteEnvelope<TruckCategory>(`pricing/truck-categories/${id}`).pipe(
      map((envelope) => ({
        retired: envelope?.data ?? null,
        reason: (envelope?.meta?.['reason'] as string | undefined) ?? null,
      })),
    );
  }
}

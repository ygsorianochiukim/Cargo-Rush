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

  diesel(): Observable<DieselState> {
    return this.api.get<DieselState>('pricing/diesel');
  }

  recordDiesel(payload: DieselPricePayload): Observable<DieselPrice> {
    return this.api.post<DieselPrice>('pricing/diesel', payload);
  }

  quote(request: QuoteRequest): Observable<QuoteBreakdown> {
    return this.api.post<QuoteBreakdown>('pricing/quote', request);
  }
}

@Injectable({ providedIn: 'root' })
export class RateCardService {
  private readonly api = inject(ApiService);

  card(): Observable<Envelope<PricingBracket[]>> {
    return this.api.envelope<PricingBracket[]>('pricing/card');
  }

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

  removeCategory(id: string): Observable<{ retired: TruckCategory | null; reason: string | null }> {
    return this.api.deleteEnvelope<TruckCategory>(`pricing/truck-categories/${id}`).pipe(
      map((envelope) => ({
        retired: envelope?.data ?? null,
        reason: (envelope?.meta?.['reason'] as string | undefined) ?? null,
      })),
    );
  }
}

import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import {
  DieselPrice,
  DieselPricePayload,
  DieselState,
  PricingZone,
  PricingZonePayload,
  QuoteBreakdown,
  QuoteRequest,
} from '../../models/pricing/pricing.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

/** Rate Card — zones and their brackets, the pump price, and the preview. */
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
   * What a run would be quoted, and by which bracket.
   *
   * A POST despite changing nothing: a destination is free text that can carry
   * a customer's address, and a query string would put that in every access log.
   */
  quote(request: QuoteRequest): Observable<QuoteBreakdown> {
    return this.api.post<QuoteBreakdown>('pricing/quote', request);
  }
}

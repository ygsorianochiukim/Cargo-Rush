import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import {
  Asset,
  AssetCategory,
  AssetLogRow,
  AssetReport,
  AssetTurnover,
  AssetTurnoverPayload,
  Custodian,
} from '../../models/asset/asset.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';
import { ApiService } from '../shared/api.service';

@Injectable({ providedIn: 'root' })
export class AssetService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Asset[]>> {
    return this.api.envelope<Asset[]>('assets', query);
  }

  create(payload: Record<string, unknown>): Observable<Asset> {
    return this.api.post<Asset>('assets', payload);
  }

  update(id: string, payload: Record<string, unknown>): Observable<Asset> {
    return this.api.patch<Asset>(`assets/${id}`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`assets/${id}`);
  }

  turnover(id: string, payload: AssetTurnoverPayload): Observable<Asset> {
    return this.api.post<Asset>(`assets/${id}/turnover`, payload);
  }

  turnovers(id: string): Observable<AssetTurnover[]> {
    return this.api.get<AssetTurnover[]>(`assets/${id}/turnovers`);
  }

  report(): Observable<AssetReport> {
    return this.api.get<AssetReport>('assets/report');
  }

  reportPdf(): Observable<Blob> {
    return this.api.blob('assets/report.pdf');
  }

  log(query: { from?: string; to?: string; employee_id?: string } = {}): Observable<AssetLogRow[]> {
    return this.api.get<AssetLogRow[]>('assets/turnovers', query as never);
  }

  custodians(): Observable<Custodian[]> {
    return this.api.get<Custodian[]>('assets/custodians');
  }

  categories(activeOnly = false): Observable<AssetCategory[]> {
    return this.api.get<AssetCategory[]>(
      'asset-categories',
      activeOnly ? ({ active: 1 } as never) : undefined,
    );
  }

  createCategory(payload: { name: string }): Observable<AssetCategory> {
    return this.api.post<AssetCategory>('asset-categories', payload);
  }

  updateCategory(
    id: string,
    payload: { name?: string; status?: string },
  ): Observable<AssetCategory> {
    return this.api.patch<AssetCategory>(`asset-categories/${id}`, payload);
  }

  removeCategory(id: string): Observable<void> {
    return this.api.delete(`asset-categories/${id}`);
  }
}

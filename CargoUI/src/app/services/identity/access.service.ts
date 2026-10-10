import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import {
  PermissionGroup,
  Position,
  PositionPayload,
  Role,
  RolePayload,
} from '../../models/identity/access.model';
import { Envelope } from '../../models/shared/envelope.model';

@Injectable({ providedIn: 'root' })
export class AccessService {
  private readonly api = inject(ApiService);

  roles(activeOnly = false): Observable<Envelope<Role[]>> {
    return this.api.envelope<Role[]>('access/roles', activeOnly ? { active: 1 } : undefined);
  }

  createRole(payload: RolePayload): Observable<Role> {
    return this.api.post<Role>('access/roles', payload);
  }

  updateRole(id: string, payload: Partial<RolePayload>): Observable<Role> {
    return this.api.patch<Role>(`access/roles/${id}`, payload);
  }

  removeRole(id: string): Observable<void> {
    return this.api.delete(`access/roles/${id}`);
  }

  permissions(): Observable<PermissionGroup[]> {
    return this.api.get<PermissionGroup[]>('access/permissions');
  }

  positions(activeOnly = false): Observable<Envelope<Position[]>> {
    return this.api.envelope<Position[]>(
      'access/positions',
      activeOnly ? { active: 1 } : undefined,
    );
  }

  createPosition(payload: PositionPayload): Observable<Position> {
    return this.api.post<Position>('access/positions', payload);
  }

  updatePosition(id: string, payload: Partial<PositionPayload>): Observable<Position> {
    return this.api.patch<Position>(`access/positions/${id}`, payload);
  }

  removePosition(id: string): Observable<void> {
    return this.api.delete(`access/positions/${id}`);
  }
}

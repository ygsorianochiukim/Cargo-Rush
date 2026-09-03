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

/** Roles, positions and the permission vocabulary. */
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

  /**
   * The vocabulary the matrix is drawn from.
   *
   * Read-only: a permission is only real if the API checks for it, so one
   * invented here would gate nothing.
   */
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

  /**
   * Delete a position, or hear that it was retired.
   *
   * Two outcomes the caller has to tell apart: one nobody holds is deleted, one
   * with employees on it is switched off instead. The page re-reads the list
   * and says which happened.
   */
  removePosition(id: string): Observable<void> {
    return this.api.delete(`access/positions/${id}`);
  }
}

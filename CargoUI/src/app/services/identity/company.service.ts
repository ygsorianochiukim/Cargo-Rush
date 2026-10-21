import { Injectable, inject } from '@angular/core';
import { Observable, tap } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { Company } from '../../models/identity/identity.model';
import { IdentityService } from './identity.service';

@Injectable({ providedIn: 'root' })
export class CompanyService {
  private readonly api = inject(ApiService);
  private readonly identity = inject(IdentityService);

  show(): Observable<Company> {
    return this.api.get<Company>('company');
  }

  updateProfile(attributes: Record<string, unknown>): Observable<Company> {
    return this.api
      .patch<Company>('company', attributes)
      .pipe(tap((c) => this.identity.applyCompany(c)));
  }

  uploadLogo(file: File): Observable<Company> {
    const form = new FormData();
    form.append('logo', file);

    return this.api
      .postForm<Company>('company/logo', form)
      .pipe(tap((c) => this.identity.applyCompany(c)));
  }

  removeLogo(): Observable<Company> {
    return this.api
      .deleteItem<Company>('company/logo')
      .pipe(tap((c) => this.identity.applyCompany(c)));
  }
}

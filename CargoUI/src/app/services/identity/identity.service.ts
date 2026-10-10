import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpContext } from '@angular/common/http';
import { Observable, finalize, shareReplay, switchMap, tap } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { AUTH_PROBE } from '../shared/http-context';
import {
  Company,
  Credentials,
  Me,
  NavItem,
  Registration,
} from '../../models/identity/identity.model';

export interface NavGroup {
  name: string;
  items: NavItem[];
}

@Injectable({ providedIn: 'root' })
export class IdentityService {
  private readonly api = inject(ApiService);

  private readonly meSignal = signal<Me | null>(null);
  private readonly navSignal = signal<NavItem[] | null>(null);

  readonly me = this.meSignal.asReadonly();

  private inFlight: Observable<Me> | null = null;
  readonly navigation = this.navSignal.asReadonly();

  readonly initials = computed(() => {
    const name = this.meSignal()?.name ?? '';

    return name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('');
  });

  readonly navGroups = computed<NavGroup[] | null>(() => {
    const items = this.navSignal();
    if (items === null) return null;

    const groups: NavGroup[] = [];

    for (const item of items) {
      const last = groups[groups.length - 1];
      if (last && last.name === item.group) last.items.push(item);
      else groups.push({ name: item.group, items: [item] });
    }

    return groups;
  });

  load(): Observable<Me> {
    if (this.inFlight !== null) return this.inFlight;

    this.inFlight = this.api.get<Me>('me', undefined, new HttpContext().set(AUTH_PROBE, true)).pipe(
      tap((me) => this.meSignal.set(me)),
      finalize(() => {
        this.inFlight = null;
      }),
      shareReplay({ bufferSize: 1, refCount: false }),
    );

    return this.inFlight;
  }

  loadNavigation(): Observable<NavItem[]> {
    return this.api.get<NavItem[]>('navigation').pipe(tap((items) => this.navSignal.set(items)));
  }

  login(credentials: Credentials): Observable<Me> {
    return this.api.csrfCookie().pipe(
      switchMap(() => this.api.post<Me>('login', credentials)),
      tap((me) => this.meSignal.set(me)),
    );
  }

  register(registration: Registration): Observable<Me> {
    return this.api.csrfCookie().pipe(
      switchMap(() => this.api.post<Me>('register', registration)),
      tap((me) => this.meSignal.set(me)),
    );
  }

  readonly name = computed(() => this.meSignal()?.name ?? '');

  readonly can = (permission: string): boolean => {
    const held = this.meSignal()?.permissions ?? [];

    return held.includes('*') || held.includes(permission);
  };

  readonly company = computed(() => this.meSignal()?.company_name ?? null);

  readonly companyLogo = computed(() => this.meSignal()?.company_logo_url ?? null);

  readonly companyInitials = computed(() => {
    const words = (this.meSignal()?.company_name ?? '').split(/\s+/).filter(Boolean);

    if (words.length === 0) return '';
    if (words.length === 1) return words[0].slice(0, 2).toUpperCase();

    return words
      .slice(0, 2)
      .map((word) => word[0].toUpperCase())
      .join('');
  });

  applyCompany(company: Company): void {
    this.meSignal.update((me) =>
      me === null
        ? me
        : {
            ...me,
            company_name: company.name,
            company_code: company.code,
            company_logo_url: company.logo_url,
          },
    );
  }

  forgotPassword(email: string): Observable<{ message: string }> {
    return this.api
      .csrfCookie()
      .pipe(switchMap(() => this.api.post<{ message: string }>('forgot-password', { email })));
  }

  resetPassword(payload: {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
  }): Observable<{ message: string }> {
    return this.api
      .csrfCookie()
      .pipe(switchMap(() => this.api.post<{ message: string }>('reset-password', payload)));
  }

  changePassword(payload: {
    current_password: string;
    password: string;
    password_confirmation: string;
  }): Observable<void> {
    return this.api.postVoid('me/password', payload);
  }

  logout(): Observable<void> {
    return this.api.postVoid('logout').pipe(
      finalize(() => {
        this.meSignal.set(null);
        this.navSignal.set(null);
        this.inFlight = null;
      }),
    );
  }

  has(permission: string): boolean {
    const held = this.meSignal()?.permissions ?? [];

    return held.includes('*') || held.includes(permission);
  }
}

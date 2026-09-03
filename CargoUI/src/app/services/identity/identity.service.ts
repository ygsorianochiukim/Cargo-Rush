import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpContext } from '@angular/common/http';
import { Observable, finalize, shareReplay, switchMap, tap } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { AUTH_PROBE } from '../shared/http-context';
import { Credentials, Me, NavItem } from '../../models/identity/identity.model';

/** A nav section: the group heading and the items under it. */
export interface NavGroup {
  name: string;
  items: NavItem[];
}

/**
 * Identity and navigation — the two calls that make the shell data-driven
 * (DESIGN.md section 7.2 and 7.3).
 *
 * Both are cached in signals because the sidebar asks for them on every
 * navigation and neither changes between routes.
 */
@Injectable({ providedIn: 'root' })
export class IdentityService {
  private readonly api = inject(ApiService);

  private readonly meSignal = signal<Me | null>(null);
  private readonly navSignal = signal<NavItem[] | null>(null);

  readonly me = this.meSignal.asReadonly();

  /** The `GET /me` currently in flight, if any. See `load()`. */
  private inFlight: Observable<Me> | null = null;
  readonly navigation = this.navSignal.asReadonly();

  /** The user chip's initials, from whatever name came back. */
  readonly initials = computed(() => {
    const name = this.meSignal()?.name ?? '';

    return name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('');
  });

  /**
   * The nav, bucketed into the sections the API declared.
   *
   * The client never invents a group the API did not send, and the items are
   * already sorted by `order` then `label` before they arrive.
   */
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

  /**
   * Who is calling, asked of the API.
   *
   * The session lives in an httpOnly cookie the client cannot read, so using
   * it is the only honest way to know whether it is still good.
   *
   * The in-flight request is shared. Two guards can resolve on one navigation
   * — the module guard and, after a redirect, the login guard — and without
   * this each would open its own request for the same answer.
   *
   * `AUTH_PROBE` marks the 401 as expected, so the interceptor does not read
   * "nobody is signed in" as "the session just expired" and redirect.
   */
  load(): Observable<Me> {
    if (this.inFlight !== null) return this.inFlight;

    this.inFlight = this.api
      .get<Me>('me', undefined, new HttpContext().set(AUTH_PROBE, true))
      .pipe(
        tap((me) => this.meSignal.set(me)),
        // Cleared either way: a failure must not be cached as the answer for
        // the rest of the session, and a success is already held in the signal.
        finalize(() => {
          this.inFlight = null;
        }),
        shareReplay({ bufferSize: 1, refCount: false }),
      );

    return this.inFlight;
  }

  loadNavigation(): Observable<NavItem[]> {
    return this.api
      .get<NavItem[]>('navigation')
      .pipe(tap((items) => this.navSignal.set(items)));
  }

  /**
   * No `device_name`, so the API sets the SPA session cookie rather than
   * issuing a token. The CSRF cookie has to be in hand first, or Laravel
   * rejects the POST before it ever reaches the credentials.
   */
  login(credentials: Credentials): Observable<Me> {
    return this.api.csrfCookie().pipe(
      switchMap(() => this.api.post<Me>('login', credentials)),
      tap((me) => this.meSignal.set(me)),
    );
  }

  logout(): Observable<void> {
    return this.api.post<void>('logout', {}).pipe(
      tap(() => {
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

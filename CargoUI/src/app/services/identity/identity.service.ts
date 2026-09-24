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

    this.inFlight = this.api.get<Me>('me', undefined, new HttpContext().set(AUTH_PROBE, true)).pipe(
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
    return this.api.get<NavItem[]>('navigation').pipe(tap((items) => this.navSignal.set(items)));
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

  /**
   * Register a company and its first account.
   *
   * The same shape as `login()` on purpose, down to setting `me` from the
   * response: registering signs you in, so what comes back is a signed-in
   * session and the caller navigates into the app exactly as it would after a
   * sign-in. The CSRF cookie is needed first for the same reason — this is a
   * cookie-authenticated POST like any other.
   */
  register(registration: Registration): Observable<Me> {
    return this.api.csrfCookie().pipe(
      switchMap(() => this.api.post<Me>('register', registration)),
      tap((me) => this.meSignal.set(me)),
    );
  }

  /** The signed-in person's name. Empty until `me` has loaded. */
  readonly name = computed(() => this.meSignal()?.name ?? '');

  /**
   * Does this account hold a permission?
   *
   * The **same rule the server applies**, wildcard and all: an administrator's
   * permission list is the single entry `['*']` rather than an expanded set, so
   * a plain `includes()` would answer false for every permission they in fact
   * hold. See `User::hasPermission()`, which this mirrors.
   *
   * Two things this is emphatically not for. It is not access control — that is
   * the permission gate on each endpoint, and a client deciding what it may
   * reach is a client that can be told otherwise. And it is not how the sidebar
   * is built: navigation comes back already filtered, because a menu item a
   * role has no permission for would be a link that 403s on click, which is the
   * appearance of access without any.
   *
   * What it *is* for is offering the right one of two honest paths — a person
   * who can change a setting is pointed at the setting; a person who cannot is
   * offered the request. Both work whatever this returns; it only decides which
   * is put in front of somebody first.
   */
  readonly can = (permission: string): boolean => {
    const held = this.meSignal()?.permissions ?? [];

    return held.includes('*') || held.includes(permission);
  };

  /** The company whose system is on screen. Null until `me` has loaded. */
  readonly company = computed(() => this.meSignal()?.company_name ?? null);

  /** Its mark, or null — in which case the shell renders `companyInitials`. */
  readonly companyLogo = computed(() => this.meSignal()?.company_logo_url ?? null);

  /**
   * The company's initials, for when it has no logo.
   *
   * Two letters from two words, or the first two of a single-word name —
   * "SF" for Southern Freight, "AC" for Acme. The same idea as the user
   * chip's initials, kept separate because the inputs differ: a person's
   * name is reliably two words and a company's is anything from "DHL" to
   * "Southern Freight Services Incorporated".
   */
  readonly companyInitials = computed(() => {
    const words = (this.meSignal()?.company_name ?? '').split(/\s+/).filter(Boolean);

    if (words.length === 0) return '';
    if (words.length === 1) return words[0].slice(0, 2).toUpperCase();

    return words
      .slice(0, 2)
      .map((word) => word[0].toUpperCase())
      .join('');
  });

  /**
   * Take the company's details back from a `CompanyResource`.
   *
   * Uploading a logo answers with the whole company, and the sidebar is
   * driven by `me`. Without this the new mark would not appear until the next
   * full page load, which reads as "the upload did not work".
   */
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

  /**
   * Ask for a reset link.
   *
   * Answers the same way whether or not the address has an account — the API
   * will not say, because that would make this a way of asking who is on the
   * platform. So the screen shows the same confirmation either way, and must
   * not be written as though a success means the address was found.
   */
  forgotPassword(email: string): Observable<{ message: string }> {
    return this.api
      .csrfCookie()
      .pipe(switchMap(() => this.api.post<{ message: string }>('forgot-password', { email })));
  }

  /** Set a new password from the token in the emailed link. */
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

  /**
   * Change it while signed in.
   *
   * Nothing to update locally afterwards: the session that made the call is
   * still good, because proving the current password is what authorised it.
   */
  changePassword(payload: {
    current_password: string;
    password: string;
    password_confirmation: string;
  }): Observable<void> {
    // `postVoid`: the API answers 204, and reading a body off one is what broke
    // signing out — see `ApiService::postVoid`.
    return this.api.postVoid('me/password', payload);
  }

  /**
   * Sign out.
   *
   * Two things had to be got right here and neither was.
   *
   * The call answers **204**, so it goes through `postVoid` — `post()` would
   * read `.data` off an empty response and throw on a request that worked.
   *
   * And the local state is cleared in `finalize`, which runs whether the call
   * succeeded or failed. On the failure path that is not tidiness: the session
   * is usually gone by then anyway, and a client still holding a `me` gets
   * turned round by `guestGuard` the moment it navigates to `/login` — which
   * is a sign-out button that looks like it does nothing at all.
   */
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

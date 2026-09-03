import { HttpClient, HttpContext, provideHttpClient, withInterceptors } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { provideRouter } from '@angular/router';
import { beforeEach, describe, expect, it } from 'vitest';

import { authInterceptor } from './auth.interceptor';
import { AUTH_PROBE } from './http-context';

/**
 * The case these exist for is a redirect loop, and it is not hypothetical —
 * an earlier version decided "is this the login page?" by reading
 * `Router.url`, which still holds the previous route while a navigation is in
 * flight. The guard probed `/me`, the interceptor called that 401 a session
 * expiry and navigated to `/login`, which re-ran the guard, which probed
 * again. The browser sat there hammering `/me`.
 */
describe('authInterceptor', () => {
  let http: HttpClient;
  let controller: HttpTestingController;
  /** Every `router.navigate(...)` the interceptor made, in order. */
  let navigations: unknown[][];

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
      ],
    });

    http = TestBed.inject(HttpClient);
    controller = TestBed.inject(HttpTestingController);

    // Replaced rather than spied on: a real navigation would need routes and
    // a location, and what is under test is only whether one was attempted.
    navigations = [];
    TestBed.inject(Router).navigate = ((...args: unknown[]) => {
      navigations.push(args);

      return Promise.resolve(true);
    }) as Router['navigate'];
  });

  const fail401 = (url: string, context?: HttpContext) => {
    http.get(url, context ? { context } : {}).subscribe({ error: () => undefined });
    controller.expectOne(url).flush(null, { status: 401, statusText: 'Unauthorized' });
  };

  it('does not redirect when a guard probes for a session', () => {
    // This is the loop. A 401 here means "nobody is signed in", which is the
    // answer the guard asked for — not a session that just expired.
    fail401('/api/v1/me', new HttpContext().set(AUTH_PROBE, true));

    expect(navigations).toEqual([]);
  });

  it('does not redirect when a login attempt is rejected', () => {
    fail401('/api/v1/login');

    expect(navigations).toEqual([]);
  });

  it('redirects when a real request loses its session mid-use', () => {
    fail401('/api/v1/trips');

    expect(navigations).toHaveLength(1);
    expect(navigations[0][0]).toEqual(['/login']);
  });

  it('leaves other failures to the page that made the call', () => {
    http.get('/api/v1/trips').subscribe({ error: () => undefined });
    controller
      .expectOne('/api/v1/trips')
      .flush(null, { status: 500, statusText: 'Server Error' });

    expect(navigations).toEqual([]);
  });
});

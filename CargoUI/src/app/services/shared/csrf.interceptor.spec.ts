import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { environment } from '../../../environments/environment';
import { csrfInterceptor } from './csrf.interceptor';

/**
 * These exist because the failure they guard against is silent from the
 * client's side: the request goes out looking fine and Laravel answers
 * "CSRF token mismatch" with no clue that a header was dropped.
 *
 * Angular's own XSRF interceptor drops it on any cross-origin request, which
 * is every call in development. If someone swaps this back for
 * `withXsrfConfiguration`, the first case here fails.
 */
describe('csrfInterceptor', () => {
  const api = environment.apiUrl || '';
  const url = `${api}/api/v1/trips`;

  let http: HttpClient;
  let controller: HttpTestingController;

  const setCookie = (value: string) => {
    document.cookie = `XSRF-TOKEN=${value}; path=/`;
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([csrfInterceptor])),
        provideHttpClientTesting(),
      ],
    });

    http = TestBed.inject(HttpClient);
    controller = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    controller.verify();
  });

  it('signs a write to the API even though it is cross-origin', () => {
    setCookie('token-abc');

    http.post(url, {}).subscribe();

    const request = controller.expectOne(url);
    expect(request.request.headers.get('X-XSRF-TOKEN')).toBe('token-abc');
    request.flush({});
  });

  it('decodes the cookie, because Laravel URL-encodes it', () => {
    // A real Sanctum token is base64 and routinely ends in `=`, which arrives
    // as `%3D`. Passing that through raw is a mismatch every time.
    setCookie(encodeURIComponent('abc+/=='));

    http.post(url, {}).subscribe();

    const request = controller.expectOne(url);
    expect(request.request.headers.get('X-XSRF-TOKEN')).toBe('abc+/==');
    request.flush({});
  });

  it('leaves reads alone — Laravel does not check them', () => {
    setCookie('token-abc');

    http.get(url).subscribe();

    const request = controller.expectOne(url);
    expect(request.request.headers.has('X-XSRF-TOKEN')).toBe(false);
    request.flush({});
  });

  it('never sends the token to anywhere but our own API', () => {
    setCookie('token-abc');

    http.post('https://example.com/collect', {}).subscribe();

    const request = controller.expectOne('https://example.com/collect');
    expect(request.request.headers.has('X-XSRF-TOKEN')).toBe(false);
    request.flush({});
  });

  it('sends nothing rather than an empty header when there is no cookie', () => {
    http.post(url, {}).subscribe();

    const request = controller.expectOne(url);
    expect(request.request.headers.has('X-XSRF-TOKEN')).toBe(false);
    request.flush({});
  });
});

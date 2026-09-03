import { HttpInterceptorFn } from '@angular/common/http';

import { environment } from '../../../environments/environment';

const COOKIE = 'XSRF-TOKEN';
const HEADER = 'X-XSRF-TOKEN';

/**
 * Attach Laravel's CSRF token to every write.
 *
 * Angular's own `withXsrfConfiguration` cannot do this here. Its interceptor
 * bails out when the request origin differs from the page origin:
 *
 *     if (locationOrigin !== requestOrigin) return next(req);
 *
 * In development the app is served from `localhost:4200` and the API from
 * `localhost:8000`, so every write is cross-origin by that test and the header
 * is silently dropped — which Laravel reports as "CSRF token mismatch".
 *
 * Angular's caution is right in general: you should not hand a token from your
 * own origin to an arbitrary third party. It is safe here because the token is
 * sent to exactly one origin — the API this app was configured against — and
 * that is the origin that issued the cookie in the first place. The check
 * below is what keeps that true.
 */
export const csrfInterceptor: HttpInterceptorFn = (request, next) => {
  const method = request.method.toUpperCase();

  // Reads do not need a token, and Laravel does not check them for one.
  if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') {
    return next(request);
  }

  if (!isOurApi(request.url) || request.headers.has(HEADER)) {
    return next(request);
  }

  const token = readCookie(COOKIE);

  if (token === null) {
    // No cookie yet — the caller has not been through `/sanctum/csrf-cookie`.
    // Sending nothing gets a clear 419 rather than a confusing empty header.
    return next(request);
  }

  return next(request.clone({ headers: request.headers.set(HEADER, token) }));
};

/**
 * Is this our API?
 *
 * In production `apiUrl` is empty and the API is same-origin, so a relative
 * path is ours and an absolute URL to anywhere else is not.
 */
function isOurApi(url: string): boolean {
  if (environment.apiUrl !== '') return url.startsWith(environment.apiUrl);

  return !/^https?:\/\//i.test(url);
}

/**
 * Laravel URL-encodes the cookie value, so it has to be decoded before it goes
 * back out as a header — the raw value will not match.
 */
function readCookie(name: string): string | null {
  if (typeof document === 'undefined') return null;

  for (const part of document.cookie.split(';')) {
    const [key, ...rest] = part.trim().split('=');
    if (key === name) return decodeURIComponent(rest.join('='));
  }

  return null;
}

import { HttpClient, HttpContext, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { Envelope, ListQuery } from '../../models/shared/envelope.model';
import { environment } from '../../../environments/environment';

/**
 * The one place a request leaves this app.
 *
 * Every module service composes this rather than injecting `HttpClient`, so
 * the base URL, the credentials mode and the envelope unwrapping are decided
 * once. A module service that wanted its own conventions would have to go
 * around this class, which is the point.
 */
@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);

  private readonly base = `${environment.apiUrl}/api/v1`;

  /**
   * CargoUI authenticates as a first-party SPA, so the session cookie has to
   * ride along on every call (DESIGN.md section 7.4).
   */
  private readonly options = { withCredentials: true } as const;

  /**
   * Ask Laravel to set the XSRF cookie.
   *
   * Sanctum's SPA flow needs this once before the first write, and it is
   * outside the `/api/v1` prefix — hence the raw URL rather than `url()`.
   */
  csrfCookie(): Observable<void> {
    return this.http.get<void>(`${environment.apiUrl}/sanctum/csrf-cookie`, this.options);
  }

  /**
   * The full envelope, for a caller that needs `meta` as well as `data`.
   *
   * `context` carries per-request flags the interceptors read — see
   * `AUTH_PROBE` for the one case where a 401 is an answer, not a fault.
   */
  envelope<T>(path: string, query?: ListQuery, context?: HttpContext): Observable<Envelope<T>> {
    return this.http.get<Envelope<T>>(this.url(path), {
      ...this.options,
      params: this.params(query),
      context,
    });
  }

  /** Just the payload — what most callers want. */
  get<T>(path: string, query?: ListQuery, context?: HttpContext): Observable<T> {
    return this.envelope<T>(path, query, context).pipe(map((r) => r.data));
  }

  post<T>(path: string, body: unknown): Observable<T> {
    return this.http
      .post<Envelope<T>>(this.url(path), body, this.options)
      .pipe(map((r) => r.data));
  }

  /**
   * The whole envelope from a write, for the calls whose answer is in `meta`.
   *
   * Creating a staff login is the case: the record comes back in `data` and the
   * password to hand over comes back in `meta`, once and never again.
   */
  postEnvelope<T>(path: string, body: unknown): Observable<Envelope<T>> {
    return this.http.post<Envelope<T>>(this.url(path), body, this.options);
  }

  patch<T>(path: string, body: unknown): Observable<T> {
    return this.http
      .patch<Envelope<T>>(this.url(path), body, this.options)
      .pipe(map((r) => r.data));
  }

  put<T>(path: string, body: unknown): Observable<T> {
    return this.http
      .put<Envelope<T>>(this.url(path), body, this.options)
      .pipe(map((r) => r.data));
  }

  /** As `put`, for a caller that needs `meta` as well. */
  putEnvelope<T>(path: string, body: unknown): Observable<Envelope<T>> {
    return this.http.put<Envelope<T>>(this.url(path), body, this.options);
  }

  /**
   * A multipart write — anything with a file on it.
   *
   * Always a POST, even for an edit. PHP does not populate `$_FILES` on a PUT
   * or a PATCH, so an update carries `_method` in the body instead, which is
   * the override Laravel already reads. `Content-Type` is deliberately not set:
   * the browser has to add the multipart boundary itself, and naming the type
   * by hand omits it and makes the request unparseable at the other end.
   */
  postForm<T>(path: string, form: FormData): Observable<T> {
    return this.http
      .post<Envelope<T>>(this.url(path), form, this.options)
      .pipe(map((r) => r.data));
  }

  /** A 204 has no body, so this resolves to void rather than a parsed null. */
  delete(path: string): Observable<void> {
    return this.http
      .delete<void>(this.url(path), this.options)
      .pipe(map(() => undefined));
  }

  private url(path: string): string {
    return `${this.base}/${path.replace(/^\//, '')}`;
  }

  /**
   * Only the keys that carry a value are sent. An empty `search=` would make
   * the API filter on the empty string rather than skip the filter.
   */
  private params(query?: ListQuery): HttpParams {
    let params = new HttpParams();

    for (const [key, value] of Object.entries(query ?? {})) {
      if (value === undefined || value === null || value === '') continue;

      if (Array.isArray(value)) {
        for (const v of value) params = params.append(`${key}[]`, String(v));
      } else {
        params = params.set(key, String(value));
      }
    }

    return params;
  }
}

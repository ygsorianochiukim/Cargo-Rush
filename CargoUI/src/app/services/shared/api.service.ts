import { HttpClient, HttpContext, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { Envelope, ListQuery } from '../../models/shared/envelope.model';
import { environment } from '../../../environments/environment';

@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);

  private readonly base = `${environment.apiUrl}/api/v1`;

  private readonly options = { withCredentials: true } as const;

  csrfCookie(): Observable<void> {
    return this.http.get<void>(`${environment.apiUrl}/sanctum/csrf-cookie`, this.options);
  }

  envelope<T>(path: string, query?: ListQuery, context?: HttpContext): Observable<Envelope<T>> {
    return this.http.get<Envelope<T>>(this.url(path), {
      ...this.options,
      params: this.params(query),
      context,
    });
  }

  get<T>(path: string, query?: ListQuery, context?: HttpContext): Observable<T> {
    return this.envelope<T>(path, query, context).pipe(map((r) => r.data));
  }

  post<T>(path: string, body: unknown): Observable<T> {
    return this.http.post<Envelope<T>>(this.url(path), body, this.options).pipe(map((r) => r.data));
  }

  postEnvelope<T>(path: string, body: unknown): Observable<Envelope<T>> {
    return this.http.post<Envelope<T>>(this.url(path), body, this.options);
  }

  patch<T>(path: string, body: unknown): Observable<T> {
    return this.http
      .patch<Envelope<T>>(this.url(path), body, this.options)
      .pipe(map((r) => r.data));
  }

  put<T>(path: string, body: unknown): Observable<T> {
    return this.http.put<Envelope<T>>(this.url(path), body, this.options).pipe(map((r) => r.data));
  }

  putEnvelope<T>(path: string, body: unknown): Observable<Envelope<T>> {
    return this.http.put<Envelope<T>>(this.url(path), body, this.options);
  }

  postForm<T>(path: string, form: FormData): Observable<T> {
    return this.http.post<Envelope<T>>(this.url(path), form, this.options).pipe(map((r) => r.data));
  }

  postVoid(path: string, body: unknown = {}): Observable<void> {
    return this.http.post<void>(this.url(path), body, this.options).pipe(map(() => undefined));
  }

  delete(path: string): Observable<void> {
    return this.http.delete<void>(this.url(path), this.options).pipe(map(() => undefined));
  }

  deleteItem<T>(path: string): Observable<T> {
    return this.http.delete<Envelope<T>>(this.url(path), this.options).pipe(map((r) => r.data));
  }

  deleteEnvelope<T>(path: string): Observable<Envelope<T> | null> {
    return this.http
      .delete<Envelope<T> | null>(this.url(path), this.options)
      .pipe(map((response) => response ?? null));
  }

  private url(path: string): string {
    return `${this.base}/${path.replace(/^\//, '')}`;
  }

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

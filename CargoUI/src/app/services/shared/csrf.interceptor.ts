import { HttpInterceptorFn } from '@angular/common/http';

import { environment } from '../../../environments/environment';

const COOKIE = 'XSRF-TOKEN';
const HEADER = 'X-XSRF-TOKEN';

export const csrfInterceptor: HttpInterceptorFn = (request, next) => {
  const method = request.method.toUpperCase();

  if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') {
    return next(request);
  }

  if (!isOurApi(request.url) || request.headers.has(HEADER)) {
    return next(request);
  }

  const token = readCookie(COOKIE);

  if (token === null) {
    return next(request);
  }

  return next(request.clone({ headers: request.headers.set(HEADER, token) }));
};

function isOurApi(url: string): boolean {
  if (environment.apiUrl !== '') return url.startsWith(environment.apiUrl);

  return !/^https?:\/\//i.test(url);
}

function readCookie(name: string): string | null {
  if (typeof document === 'undefined') return null;

  for (const part of document.cookie.split(';')) {
    const [key, ...rest] = part.trim().split('=');
    if (key === name) return decodeURIComponent(rest.join('='));
  }

  return null;
}

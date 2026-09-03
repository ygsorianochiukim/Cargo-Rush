import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { Router } from '@angular/router';
import { inject } from '@angular/core';
import { catchError, throwError } from 'rxjs';

import { AUTH_PROBE } from './http-context';

/**
 * A session can expire between one request and the next, and when it does
 * every page in the app quietly stops working.
 *
 * This turns that into the one thing the user can act on: back to sign-in,
 * with where they were kept so they land there again. The error still
 * propagates, so a page that wants to show its own error state still can.
 *
 * Two kinds of 401 are deliberately left alone:
 *
 *  - **The session probe.** A guard asking `GET /me` whether anyone is signed
 *    in gets a 401 when nobody is. That is the answer it wanted.
 *  - **The login call itself.** A rejected password is a 422, but an
 *    unreachable or misconfigured API can 401 here too, and bouncing the login
 *    page back to itself would be a loop with no explanation.
 *
 * Both are read off the request rather than off `Router.url`, which still
 * holds the previous route while a navigation is in flight — the earlier
 * version guessed from it and looped.
 */
export const authInterceptor: HttpInterceptorFn = (request, next) => {
  const router = inject(Router);

  return next(request).pipe(
    catchError((error: HttpErrorResponse) => {
      const expected = request.context.get(AUTH_PROBE) || request.url.endsWith('/login');

      if (error.status === 401 && !expected) {
        router.navigate(['/login'], { queryParams: { next: router.url } });
      }

      return throwError(() => error);
    }),
  );
};

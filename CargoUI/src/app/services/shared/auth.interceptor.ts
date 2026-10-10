import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { Router } from '@angular/router';
import { inject } from '@angular/core';
import { catchError, throwError } from 'rxjs';

import { AUTH_PROBE } from './http-context';

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

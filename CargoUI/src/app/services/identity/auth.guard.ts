import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { catchError, map, of } from 'rxjs';

import { IdentityService } from './identity.service';

export const authGuard: CanActivateFn = (_route, state) => {
  const identity = inject(IdentityService);
  const router = inject(Router);

  if (identity.me() !== null) return true;

  return identity.load().pipe(
    map(() => true),
    catchError(() =>
      of(router.createUrlTree(['/login'], { queryParams: { next: state.url } })),
    ),
  );
};

export const guestGuard: CanActivateFn = () => {
  const identity = inject(IdentityService);
  const router = inject(Router);

  if (identity.me() !== null) return router.createUrlTree(['/dashboard']);

  return identity.load().pipe(
    map(() => router.createUrlTree(['/dashboard'])),
    catchError(() => of(true)),
  );
};

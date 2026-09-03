import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { catchError, map, of } from 'rxjs';

import { IdentityService } from './identity.service';

/**
 * Every module route sits behind this.
 *
 * It asks the API who is calling rather than trusting a flag in local storage:
 * the session lives in an httpOnly cookie the client cannot read, so the only
 * honest way to know whether it is still valid is to use it. The answer is
 * cached in `IdentityService`, so this costs one request per page load, not
 * one per navigation.
 */
export const authGuard: CanActivateFn = (_route, state) => {
  const identity = inject(IdentityService);
  const router = inject(Router);

  if (identity.me() !== null) return true;

  return identity.load().pipe(
    map(() => true),
    catchError(() =>
      // Where they were headed rides along, so signing in lands them there
      // rather than dumping them on the dashboard.
      of(router.createUrlTree(['/login'], { queryParams: { next: state.url } })),
    ),
  );
};

/**
 * The mirror of the above, on the login route.
 *
 * Somebody who is already signed in has no business on a sign-in form; without
 * this, a bookmarked `/login` would show one to a working session.
 */
export const guestGuard: CanActivateFn = () => {
  const identity = inject(IdentityService);
  const router = inject(Router);

  if (identity.me() !== null) return router.createUrlTree(['/dashboard']);

  return identity.load().pipe(
    map(() => router.createUrlTree(['/dashboard'])),
    catchError(() => of(true)),
  );
};

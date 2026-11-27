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

export const developerGuard: CanActivateFn = () => {
  const identity = inject(IdentityService);
  const router = inject(Router);
  return identity.me()?.permissions.includes('automation.manage') === true
    ? true
    : router.createUrlTree(['/dashboard']);
};

export const companyManagerGuard: CanActivateFn = () => {
  const identity = inject(IdentityService);
  const router = inject(Router);

  return identity.can('company.manage') || router.createUrlTree(['/dashboard']);
};

export const virtualOperationsGuard: CanActivateFn = () => {
  const identity = inject(IdentityService);
  const router = inject(Router);

  return identity.me()?.can_access_virtual_operations === true
    || router.createUrlTree(['/dashboard']);
};

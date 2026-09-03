import { ApplicationConfig, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideHttpClient, withFetch, withInterceptors, withNoXsrfProtection } from '@angular/common/http';
import { provideRouter } from '@angular/router';

import { routes } from './app.routes';
import { authInterceptor } from './services/shared/auth.interceptor';
import { csrfInterceptor } from './services/shared/csrf.interceptor';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes),

    // Sanctum's SPA flow signs writes with the XSRF-TOKEN cookie it sets.
    // Angular's built-in XSRF handling is switched off because it refuses to
    // act on a cross-origin request, which is every call in development —
    // `csrfInterceptor` does the job with the one origin check that matters.
    provideHttpClient(
      withFetch(),
      withNoXsrfProtection(),
      withInterceptors([csrfInterceptor, authInterceptor]),
    ),
  ],
};

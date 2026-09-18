import { Environment } from './environment.model';

/**
 * Production. The SPA is served from app.aya-it.online and the API answers on
 * api.aya-it.online, so `apiUrl` is absolute rather than empty.
 *
 * Two consequences of that split, both handled elsewhere:
 *   - every request is cross-origin, so the API names this host in
 *     `FRONTEND_URL` (config/cors.php) and in `SANCTUM_STATEFUL_DOMAINS`;
 *   - the session and XSRF cookies are issued with `Domain=.aya-it.online`
 *     so this origin can read the token `csrfInterceptor` has to echo back.
 */
export const environment: Environment = {
  production: true,
  apiUrl: 'https://api.aya-it.online',
};

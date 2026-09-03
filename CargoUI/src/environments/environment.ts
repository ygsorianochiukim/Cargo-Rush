import { Environment } from './environment.model';

/**
 * Local development. `ng build --configuration production` swaps this file for
 * `environment.prod.ts`.
 *
 * The API runs on its own origin here, so the SPA cookie needs this app's host
 * in `SANCTUM_STATEFUL_DOMAINS` and in `config/cors.php` — see CargoApi/.env.
 */
export const environment: Environment = {
  production: false,
  apiUrl: 'http://localhost:8000',
};

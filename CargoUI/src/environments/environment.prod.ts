import { Environment } from './environment.model';

/** Production. Same-origin, so the API is reached by path alone. */
export const environment: Environment = {
  production: true,
  apiUrl: '',
};

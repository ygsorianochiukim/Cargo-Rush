import { Environment } from './environment.model';

/** Staging. Same split as production, one environment down. */
export const environment: Environment = {
  production: true,
  apiUrl: 'https://staging.cargorush-logistics.com',
};

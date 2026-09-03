import { Credentials, Me, NavItem } from '@/models/identity/identity.model';

import { api } from '../shared/api.service';

/**
 * Identity and navigation.
 *
 * The tab bar comes from the API the way the web sidebar does, so adding a
 * driver-facing module is a row in `nav_items` rather than an edit in here.
 */
export const identityService = {
  me(): Promise<Me> {
    return api.get<Me>('me');
  },

  /** The driver tabs, already filtered by permission and sorted. */
  navigation(): Promise<NavItem[]> {
    return api.get<NavItem[]>('navigation?client=mobile');
  },

  /**
   * A `device_name` always goes with this, so the API issues a bearer token
   * rather than a cookie this app has no browser to hold. The token is handed
   * straight to the client so nothing else has to know it exists.
   */
  async login(credentials: Credentials): Promise<Me> {
    const response = await api.postEnvelope<Me>('login', credentials);

    api.setToken(String(response.meta?.['token'] ?? ''));

    return response.data;
  },

  async logout(): Promise<void> {
    await api.post<void>('logout', {});
    api.setToken(null);
  },

  /** The availability switch on the dashboard. */
  setAvailability(driverId: string, available: boolean): Promise<unknown> {
    return api.post(`drivers/${driverId}/availability`, { available });
  },
};

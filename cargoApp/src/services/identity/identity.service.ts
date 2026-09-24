import {
  Credentials,
  Me,
  NavItem,
  ShipperRegistration,
} from '@/models/identity/identity.model';
import { TruckerRegistration } from '@/models/trucker/trucker.model';

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

  /**
   * Sign a customer up. No carrier: they pick one per load.
   *
   * Answers in exactly the shape a login does, token and all, because
   * registering *is* signing in — so the app carries on into the portal down
   * the same code path rather than a second one written only for this. The
   * company fields come back null, because there is no haulier yet.
   */
  async registerCustomer(registration: ShipperRegistration): Promise<Me> {
    const response = await api.postEnvelope<Me>('register/customer', registration);

    api.setToken(String(response.meta?.['token'] ?? ''));

    return response.data;
  },

  /**
   * Sign an owner-operator up.
   *
   * The third registration, and the one that asks for the most — a fleet, a
   * licence and a truck on top of the login. Each earns its place: a partner's
   * relationship is a standing one with a rate and a running balance, so there
   * is no coherent state in which they belong to nobody; the licence is what a
   * human reads before approving them; the truck decides which loads they can
   * be offered.
   *
   * Answers signed in, like the other two. What it does **not** answer is that
   * they can start working: the account lands `pending`, and `meta.trucker_status`
   * says so, so the app opens on the waiting screen rather than an empty board.
   */
  async registerTrucker(registration: TruckerRegistration): Promise<Me> {
    const response = await api.postEnvelope<Me>('register/trucker', registration);

    api.setToken(String(response.meta?.['token'] ?? ''));

    return response.data;
  },

  async logout(): Promise<void> {
    await api.post<void>('logout', {});
    api.setToken(null);
  },

  /**
   * The availability switch on the dashboard.
   *
   * No driver id, and that is the fix rather than a tidy-up. This used to post
   * to `drivers/{id}/availability`, which is the office's route behind
   * `drivers.manage` — the permission that edits the roster, and one no driver
   * holds. Every driver who touched their own switch got back "This account
   * does not hold drivers.manage."
   *
   * `drivers/me/availability` is the driver's own: it resolves the record from
   * the token, like every other call the handset makes about its own work, and
   * can reach no other row.
   */
  setAvailability(available: boolean): Promise<unknown> {
    return api.post('drivers/me/availability', { available });
  },
};

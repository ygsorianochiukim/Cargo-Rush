/**
 * `GET /api/v1/me` — the driver's own record.
 *
 * The same resource the web sidebar reads; the driver fields are filled in
 * because this account has a `drivers` row behind it.
 */
export interface Me {
  id: number;
  name: string;
  email: string;
  role: string;
  role_label: string;
  avatar_url: string | null;
  permissions: string[];

  driver_id: string | null;
  licence_no: string | null;
  licence_expiry: string | null;
  /** Drives the availability switch on the dashboard. */
  available: boolean | null;

  /**
   * The unit they currently hold the keys to.
   *
   * Needed before there is a trip to read one from: a pre-trip check happens
   * at the vehicle, not on the road.
   */
  vehicle_id: string | null;
  vehicle_plate: string | null;

  /**
   * Present only for a customer, and the mirror of the driver pair above.
   *
   * The app decides which home screen to open on from `role`; this is the
   * record everything on that screen is scoped to. Null on a customer account
   * nobody linked to a firm — the portal endpoints answer 404 for one of
   * those, so the app can say so rather than showing an empty list that reads
   * like a customer with no deliveries.
   */
  customer_id: string | null;
  customer_name: string | null;
}

/**
 * Who is holding the app.
 *
 * `cargoApp` is one app with two products in it: the driver's cab screens and
 * the customer's portal. This is what it branches on, and it is the only thing
 * it branches on — the tab set, the home screen and the API calls all follow
 * from the role the API reported.
 */
export type UserRole = 'administrator' | 'dispatcher' | 'accountant' | 'driver' | 'customer';

/** `GET /api/v1/navigation?client=mobile` — the tab bar. */
export interface NavItem {
  key: string;
  label: string;
  icon: string;
  route: string;
  order: number;
  mobile: boolean;
  group: string;
  badge?: number | null;
}

export interface Credentials {
  email: string;
  password: string;
  /** Always sent from here: the handset wants a bearer token, not a cookie. */
  device_name: string;
}

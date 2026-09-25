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

  /**
   * The company this account belongs to.
   *
   * Filled in for every account but one. A driver drives for a company and a
   * customer is a customer *of* one; the exception is a customer who has just
   * signed themselves up in this app and not yet sent anything, who is a
   * customer of nobody until they pick a carrier for their first load. Null
   * until then, and filled in from that request onwards — so a screen naming it
   * needs a fallback rather than an assumption.
   *
   * The app does not scope anything with it. Every endpoint is already filtered
   * server-side from the token that called it; this is here so a screen can say
   * whose fleet it is, which matters for a driver who has worked for two.
   */
  company_id: string | null;
  company_name: string | null;
  company_code: string | null;
  /**
   * The company's mark, a 64px square PNG, or null for one that has not set
   * a logo — in which case a screen renders the company's initials.
   *
   * Set from the back office; there is nothing on the handset that changes it.
   * A driver's phone reads it, it does not write it.
   */
  company_logo_url: string | null;

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
   * record everything on that screen is scoped to. Null while a self-registered
   * customer has yet to send their first load — there is no record on anybody's
   * books until a carrier is chosen — and the portal answers those screens
   * empty rather than as an error, because a customer with no deliveries is
   * exactly what they are.
   */
  customer_id: string | null;
  customer_name: string | null;

  /**
   * May this customer choose who carries their load?
   *
   * True only for a firm that signed itself up in this app. False for one the
   * office added — that account is that haulier's, its work all goes to them,
   * and the app hides the carrier list entirely rather than offering a choice
   * the API will refuse.
   */
  chooses_carrier: boolean;

  /**
   * Where this firm's loads go out from, if a carrier keeps an address for
   * them.
   *
   * Not asked for at sign-up, and usually null: where a load is going out from
   * is answered per request, from the handset's own position or the map. What
   * fills this in is a haulier's office writing an address onto its own record
   * of the firm — and when it is there the request form starts "pick up from"
   * with it, and the carrier list is measured from it when the handset has no
   * position of its own.
   */
  customer_address: string | null;
  customer_lat: number | null;
  customer_lng: number | null;

  /**
   * Present only for a partner trucker — the third of the three handset
   * identities, beside `driver_id` and `customer_id`.
   *
   * `trucker_status` is the one field the app genuinely branches on. A
   * registration lands `pending` and the job board stays empty until somebody
   * at the fleet reads the licence and approves it, so an app reading only the
   * empty board would open on a screen that looks broken. This is what lets it
   * say "we are checking your details" instead.
   *
   * `trucker_online` is the partner's own switch and says nothing about whether
   * they are approved. Both are reported because collapsing them would leave
   * the app unable to tell somebody waiting on the office from somebody who is
   * simply off duty — two different screens.
   */
  trucker_id: string | null;
  trucker_status: string | null;
  trucker_online: boolean | null;
  trucker_can_take_work: boolean | null;

  /**
   * What this partner's runs split at, in basis points. 1200 is 12%.
   *
   * On `me` so the job board can state the rate on a card without a second
   * call. Null for anybody who is not a trucker.
   */
  commission_bp: number | null;
}

/**
 * What `POST /api/v1/register/customer` takes — a person and a login.
 *
 * The customer-side mirror of a company registering on the web, and
 * deliberately smaller: the app signs up a **customer**, not a business. A
 * trading name, a TIN and a VAT treatment are the haulier's record to keep and
 * the office's form to fill in.
 *
 * No carrier and no address, and neither is an omission. Who carries a load and
 * where the load is going out from are answered per load, on the request form,
 * by whoever is standing next to it — so the account this creates belongs to no
 * haulier until the first request picks one.
 */
export interface ShipperRegistration {
  /**
   * The customer, and the person. One name.
   *
   * The app registers a customer rather than a business: what is typed here is
   * both what the login is called and what a carrier's books will be opened in
   * the name of. There is no trading name to ask for.
   */
  name: string;
  /**
   * What a carrier's office rings about a pickup.
   *
   * Kept on the login until there is a carrier to copy it to, since there is no
   * customer record anywhere yet.
   */
  contact_phone?: string;
  email: string;
  password: string;
  password_confirmation: string;
  /** Always sent from here: the handset wants a bearer token, not a cookie. */
  device_name: string;
}

/**
 * Who is holding the app.
 *
 * `cargoApp` is one app with three products in it: the driver's cab screens,
 * the customer's portal, and the partner trucker's board. This is what it
 * branches on, and it is the only thing it branches on — the tab set, the home
 * screen and the API calls all follow from the role the API reported.
 */
export type UserRole =
  | 'administrator'
  | 'dispatcher'
  | 'accountant'
  | 'driver'
  | 'customer'
  /**
   * An owner-operator with their own truck.
   *
   * Not a driver, and the app never treats them as one: a driver is an
   * employee working the run they were given, and a trucker chooses which work
   * to take and is paid a share of what it billed.
   */
  | 'trucker';

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

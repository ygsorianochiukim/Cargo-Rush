/**
 * `GET /api/v1/me` — drives the sidebar user chip (DESIGN.md section 7.2).
 *
 * `role` is the machine enum and `role_label` the display string; the client
 * uppercases the label and never reads words out of the enum.
 */
export interface Me {
  id: number;
  name: string;
  email: string;
  role: string;
  role_label: string;
  /** Null means render initials. */
  avatar_url: string | null;
  permissions: string[];

  /** Present only when the account is linked to a driver record. */
  driver_id: string | null;
  licence_no: string | null;
  licence_expiry: string | null;
  available: boolean | null;
}

/**
 * `GET /api/v1/navigation` — the sidebar and the mobile tab bar.
 *
 * Already filtered by permission and already sorted. The client renders what
 * comes back and keeps no list of its own (DESIGN.md section 7.3).
 */
export interface NavItem {
  key: string;
  label: string;
  /** A name from the shared icon set, never a URL. */
  icon: string;
  route: string;
  order: number;
  mobile: boolean;
  /** Sidebar section heading; items sharing a group render together. */
  group: string;
  /** Absent or null means no badge. Zero is never sent. */
  badge?: number | null;
}

/** What `POST /api/v1/login` takes. */
export interface Credentials {
  email: string;
  password: string;
  /** Present asks for a bearer token; absent sets the SPA cookie. */
  device_name?: string;
}

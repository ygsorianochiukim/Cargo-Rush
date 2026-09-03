import { CustomerHomePage } from '@/pages/customer/customer-home.page';
import { DashboardPage } from '@/pages/dashboard/dashboard.page';
import { useSession } from '@/services/identity/session';

/**
 * expo-router route file.
 *
 * Routes are thin on purpose: the screen itself is a module page under
 * `src/pages/<module>/`, so the router's file layout and the module map stay
 * two separate concerns. Renaming a tab is a change here; changing what the
 * Dashboard does is a change there.
 *
 * **This one route is thicker than the others, deliberately.** `cargoApp` is
 * one app holding two products — the cab screens and the customer portal — and
 * the home tab has to be whichever one the signed-in account belongs to.
 *
 * Doing it here rather than by redirecting: expo-router resolves the tab set
 * at build time, so `index` is always the first tab whoever is holding the
 * phone. Sending a customer onwards from it would mean the driver dashboard
 * mounts first, fires its five driver-scoped fetches, 404s on every one of
 * them because there is no `drivers` row behind the account, and *then*
 * navigates away. Choosing the page before anything mounts costs nothing and
 * fails nowhere.
 */
export default function Home() {
  const { me } = useSession();

  return me?.role === 'customer' ? <CustomerHomePage /> : <DashboardPage />;
}

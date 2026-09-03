import { HttpContextToken } from '@angular/common/http';

/**
 * Marks a request whose 401 is an answer rather than a failure.
 *
 * The route guards ask `GET /me` to find out whether there is a session at
 * all. For a signed-out visitor a 401 is the expected reply — it is how the
 * guard learns to show the login page — and it must not be treated as a
 * session that just expired.
 *
 * This is carried on the request rather than inferred from the current URL,
 * because `Router.url` still holds the *previous* route while a navigation is
 * in flight. Guessing from it produced a loop: the guard probed, the
 * interceptor saw a 401 and navigated to `/login`, that re-ran the guard,
 * which probed again.
 */
export const AUTH_PROBE = new HttpContextToken<boolean>(() => false);

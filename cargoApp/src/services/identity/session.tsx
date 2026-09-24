import {
  createContext,
  ReactNode,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from 'react';
import { Platform } from 'react-native';

import { Credentials, Me, ShipperRegistration } from '@/models/identity/identity.model';
import { TruckerRegistration } from '@/models/trucker/trucker.model';

import { api, ApiRequestError } from '../shared/api.service';
import { identityService } from './identity.service';
import { tokenStore } from './token-store';

export type SessionState = {
  /** Null until the stored token has been checked. */
  me: Me | null;
  /** True while restoring on launch — the app shows nothing rather than a flash of the sign-in form. */
  restoring: boolean;
  /**
   * `remember` is the tick on the sign-in form. True keeps the session across
   * launches — which is what a driver wants and what the box is ticked to by
   * default; false ends it with the app, for a shared or borrowed handset.
   */
  signIn: (credentials: Omit<Credentials, 'device_name'>, remember?: boolean) => Promise<void>;
  /**
   * Sign a new firm up and open the app on them.
   *
   * Beside `signIn` rather than inside the registration screen, because what it
   * has to do afterwards is identical — keep the token, and put a `me` in
   * context — and a second copy of that would be a second place for the token
   * to be forgotten.
   */
  register: (
    registration: Omit<ShipperRegistration, 'device_name'>,
    remember?: boolean,
  ) => Promise<void>;
  /**
   * The same, for an owner-operator.
   *
   * Its own function rather than a flag on `register`: the payloads share four
   * fields out of eleven and go to different endpoints, and a discriminated
   * union would make both call sites harder to read to save three lines here.
   */
  registerTrucker: (
    registration: Omit<TruckerRegistration, 'device_name'>,
    remember?: boolean,
  ) => Promise<void>;
  signOut: () => Promise<void>;
};

const SessionContext = createContext<SessionState | null>(null);

/**
 * Who is signed in, for the whole app.
 *
 * The token is restored from the keychain on launch and verified with
 * `GET /me`. What it does *not* do any more is treat every failure of that
 * call as a dead token: a driver in a yard with no bars used to come back to
 * the sign-in form with the stored token already deleted, and no way back in
 * until they were somewhere with signal and could remember their password.
 *
 * So the two failures are told apart. A 401 is the server saying this token is
 * finished — sign out. Anything else is the network, and the app opens on the
 * identity cached beside the token, with the same token still in place, and
 * asks again on the next launch.
 */
export function SessionProvider({ children }: { children: ReactNode }) {
  const [me, setMe] = useState<Me | null>(null);
  const [restoring, setRestoring] = useState(true);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      const stored = await tokenStore.session();

      if (stored === null) {
        if (!cancelled) setRestoring(false);

        return;
      }

      // "Remember me" was left unticked, and this is the next launch — the
      // moment that tick is about. The token is handed back to the server to
      // be revoked before it is dropped, so that it dies with the session
      // rather than sitting on the account until somebody notices it. The
      // handset forgets it either way: if that call cannot go out, nothing
      // here can use the token again regardless.
      if (!stored.remember) {
        api.setToken(stored.token);
        await identityService.logout().catch(() => undefined);
        api.setToken(null);
        await tokenStore.clear();

        if (!cancelled) setRestoring(false);

        return;
      }

      api.setToken(stored.token);

      // Open on the cached person straight away when there is one. The
      // verification below still runs and still signs a revoked token out; what
      // this buys is a launch that does not wait on the network, and one that
      // works at all without it.
      if (stored.me !== null && !cancelled) {
        setMe(stored.me);
        setRestoring(false);
      }

      try {
        const user = await identityService.me();

        if (cancelled) return;

        setMe(user);
        await tokenStore.cache(user);
      } catch (error) {
        if (cancelled) return;

        // Expired or revoked. Drop it rather than leaving a token that fails
        // every call for the rest of the session.
        if (revoked(error)) {
          setMe(null);
          api.setToken(null);
          await tokenStore.clear();
        }

        // Anything else — no signal, a 500, a server that is not up yet — is
        // not evidence about the token, and is not answered by making the
        // driver type a password they may not have with them.
      } finally {
        if (!cancelled) setRestoring(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  const signIn = useCallback(
    async (credentials: Omit<Credentials, 'device_name'>, remember = true) => {
      const user = await identityService.login({
        ...credentials,
        // Names this handset on the token, so signing in here does not sign the
        // driver out of another device.
        device_name: await deviceName(),
      });

      const token = api.token;
      if (token !== null) await tokenStore.write(token, remember, user);

      setMe(user);
    },
    [],
  );

  const register = useCallback(
    async (registration: Omit<ShipperRegistration, 'device_name'>, remember = true) => {
      const user = await identityService.registerCustomer({
        ...registration,
        device_name: await deviceName(),
      });

      const token = api.token;
      if (token !== null) await tokenStore.write(token, remember, user);

      setMe(user);
    },
    [],
  );

  /**
   * Sign an owner-operator up and open the app on them.
   *
   * Beside `register` rather than folded into it: the two take different
   * payloads and hit different endpoints, and the one thing they share — keep
   * the token, put a `me` in context — is three lines. Collapsing them behind a
   * discriminator would make both harder to read to save nothing.
   *
   * The account lands `pending`. The app opens on the partner's dashboard
   * regardless, which says so; there is nothing to gate here.
   */
  const registerTrucker = useCallback(
    async (registration: Omit<TruckerRegistration, 'device_name'>, remember = true) => {
      const user = await identityService.registerTrucker({
        ...registration,
        device_name: await deviceName(),
      });

      const token = api.token;
      if (token !== null) await tokenStore.write(token, remember, user);

      setMe(user);
    },
    [],
  );

  const signOut = useCallback(async () => {
    // Cleared locally first: a driver who taps sign out is signed out whether
    // or not the network agrees.
    setMe(null);
    api.setToken(null);
    await tokenStore.clear();

    await identityService.logout().catch(() => undefined);
  }, []);

  const value = useMemo<SessionState>(
    () => ({ me, restoring, signIn, register, registerTrucker, signOut }),
    [me, restoring, signIn, register, registerTrucker, signOut],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionState {
  const session = useContext(SessionContext);

  if (session === null) {
    throw new Error('useSession must be used inside a SessionProvider.');
  }

  return session;
}

/**
 * The server has finished with this token, as opposed to the app having failed
 * to reach the server.
 *
 * 401 only. A 403 is the opposite answer — the token is good and the account
 * simply may not have that thing — and signing somebody out over one would
 * turn a missing permission into a lost session.
 */
function revoked(error: unknown): boolean {
  return error instanceof ApiRequestError && error.status === 401;
}

/**
 * Something a person would recognise in a list of their signed-in devices,
 * and that no second handset answers with.
 *
 * The uniqueness is the point: the API drops any existing token of the same
 * name when it issues one, so a name shared by every Android phone meant the
 * driver's second device signed the first one out. Capped at the 80 characters
 * `device_name` validates to.
 */
async function deviceName(): Promise<string> {
  const id = await tokenStore.deviceId();
  const model = tokenStore.model();

  return `cargoApp ${model ?? Platform.OS} ${id}`.slice(0, 80);
}

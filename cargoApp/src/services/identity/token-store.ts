import * as Device from 'expo-device';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

import { Me } from '@/models/identity/identity.model';

const TOKEN_KEY = 'cargorush.token';
const REMEMBER_KEY = 'cargorush.remember';
const ME_KEY = 'cargorush.me';
const DEVICE_KEY = 'cargorush.device';

/**
 * What the app knows about the signed-in person before it has asked anybody.
 */
export type StoredSession = {
  token: string;
  /**
   * Whether this session outlives the launch.
   *
   * False when the driver unticked "Remember me": the token is still written,
   * because the background location task runs in a JavaScript context with no
   * in-memory anything and reads the keychain on every fix — not persisting
   * would stop tracking the moment the OS backgrounded the app. So it is the
   * *next cold start* that honours the tick: the session is thrown away before
   * it is used, rather than never being written.
   */
  remember: boolean;
  /**
   * The last verified `GET /me`.
   *
   * Kept so a launch with no signal opens the app on the person who was
   * already signed in, instead of the sign-in form. Verified against the API
   * in the background either way.
   */
  me: Me | null;
};

/**
 * Where the bearer token lives between launches.
 *
 * A driver signs in once and stays signed in — asking for a password at the
 * start of every shift, in a cab, is the kind of friction that gets an app put
 * down. The token is a credential, so it goes in the keychain rather than in
 * plain storage.
 *
 * `expo-secure-store` has no web implementation. On web the app falls back to
 * `localStorage`, which is honestly weaker — it is there so the browser build
 * used for development still works, not because it is equivalent.
 */
export const tokenStore = {
  /**
   * Just the token.
   *
   * The background location task's whole world: it has no session, no React
   * and no `remember` to honour, and asking it to parse a record it does not
   * use would be ceremony.
   */
  read(): Promise<string | null> {
    return get(TOKEN_KEY);
  },

  /** Everything the splash needs to decide what to draw. */
  async session(): Promise<StoredSession | null> {
    const token = await get(TOKEN_KEY);

    if (token === null || token === '') return null;

    const [remember, cached] = await Promise.all([get(REMEMBER_KEY), get(ME_KEY)]);

    return {
      token,
      // Absent means an install that signed in before this setting existed.
      // Those sessions were kept across launches, and silently signing those
      // drivers out on upgrade would be the exact thing this is here to stop.
      remember: remember !== '0',
      me: parse(cached),
    };
  },

  async write(token: string, remember: boolean, me: Me | null): Promise<void> {
    await Promise.all([
      set(TOKEN_KEY, token),
      set(REMEMBER_KEY, remember ? '1' : '0'),
      me === null ? remove(ME_KEY) : set(ME_KEY, JSON.stringify(strip(me))),
    ]);
  },

  /** Refresh the cached identity after a `GET /me` without touching the token. */
  async cache(me: Me): Promise<void> {
    await set(ME_KEY, JSON.stringify(strip(me)));
  },

  async clear(): Promise<void> {
    // The device id deliberately survives: it names this handset, not this
    // session, and rolling it on every sign-out would leave a trail of dead
    // token rows on the account with no way to tell them apart.
    await Promise.all([remove(TOKEN_KEY), remove(REMEMBER_KEY), remove(ME_KEY)]);
  },

  /**
   * A name for this handset that no other handset shares.
   *
   * The server deletes any existing token with the same name when it issues a
   * new one, so that a phone signing in again replaces its own token rather
   * than stacking up a row per sign-in. That only works if the name is the
   * *handset* — with a name like "cargoApp android", a driver signing in on a
   * second phone silently killed the token on the first, and the first phone
   * dropped to the sign-in form on its next call.
   *
   * The random half is what makes it unique; the model is there so the row is
   * recognisable in a list of signed-in devices. Not a secret and not used for
   * anything but a label, which is why it does not need a crypto source.
   */
  async deviceId(): Promise<string> {
    const existing = await get(DEVICE_KEY);
    if (existing !== null && existing !== '') return existing;

    const id = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
    await set(DEVICE_KEY, id);

    return id;
  },

  /** The model, for the human half of the device name. */
  model(): string | null {
    return Device.modelName;
  },
};

/**
 * `permissions` is dropped from the cached copy on purpose.
 *
 * Android's keychain rejects values much over 2 KB, and an administrator's
 * permission list on its own can get there — which would fail the write and
 * leave no cached identity at all. Nothing on the handset reads the list (the
 * API filters every response by the token that asked), so what is cached is
 * the part the app actually draws, and the real list arrives with the `GET
 * /me` that follows a moment later.
 */
function strip(me: Me): Me {
  return { ...me, permissions: [] };
}

function parse(raw: string | null): Me | null {
  if (raw === null || raw === '') return null;

  try {
    const value: unknown = JSON.parse(raw);

    // A record written by an older build, or half-written, is worth no more
    // than none: the app falls back to asking the API.
    return typeof value === 'object' && value !== null && 'id' in value ? (value as Me) : null;
  } catch {
    return null;
  }
}

async function get(key: string): Promise<string | null> {
  try {
    if (Platform.OS === 'web') return globalThis.localStorage?.getItem(key) ?? null;

    return await SecureStore.getItemAsync(key);
  } catch {
    // A locked or unavailable keychain means "not signed in", which the app
    // already knows how to show. Throwing here would break the splash.
    return null;
  }
}

async function set(key: string, value: string): Promise<void> {
  try {
    if (Platform.OS === 'web') {
      globalThis.localStorage?.setItem(key, value);

      return;
    }

    await SecureStore.setItemAsync(key, value);
  } catch {
    // Failing to persist is not failing to sign in: the in-memory token
    // still works for this session.
  }
}

async function remove(key: string): Promise<void> {
  try {
    if (Platform.OS === 'web') {
      globalThis.localStorage?.removeItem(key);

      return;
    }

    await SecureStore.deleteItemAsync(key);
  } catch {
    // Nothing to do — the caller has already cleared the in-memory copy.
  }
}

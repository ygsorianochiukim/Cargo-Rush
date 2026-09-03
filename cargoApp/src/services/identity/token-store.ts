import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

const KEY = 'cargorush.token';

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
  async read(): Promise<string | null> {
    try {
      if (Platform.OS === 'web') return globalThis.localStorage?.getItem(KEY) ?? null;

      return await SecureStore.getItemAsync(KEY);
    } catch {
      // A locked or unavailable keychain means "not signed in", which the app
      // already knows how to show. Throwing here would break the splash.
      return null;
    }
  },

  async write(token: string): Promise<void> {
    try {
      if (Platform.OS === 'web') {
        globalThis.localStorage?.setItem(KEY, token);

        return;
      }

      await SecureStore.setItemAsync(KEY, token);
    } catch {
      // Failing to persist is not failing to sign in: the in-memory token
      // still works for this session.
    }
  },

  async clear(): Promise<void> {
    try {
      if (Platform.OS === 'web') {
        globalThis.localStorage?.removeItem(KEY);

        return;
      }

      await SecureStore.deleteItemAsync(KEY);
    } catch {
      // Nothing to do — the caller has already cleared the in-memory copy.
    }
  },
};

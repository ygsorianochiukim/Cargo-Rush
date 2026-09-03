import { Me } from '@/models/identity/identity.model';
import { useSession } from '@/services/identity/session';

import { AsyncState } from './use-api';

/**
 * The signed-in driver, in the shape a screen already expects.
 *
 * The session verified `GET /me` before the tabs mounted, so this is a read
 * of what the app is holding rather than another request. Screens keep the
 * `AsyncState` shape so their loading and error branches do not have to be
 * rewritten around a value that is simply always there.
 */
export function useMe(): AsyncState<Me> {
  const { me } = useSession();

  return {
    data: me,
    loading: false,
    error: null,
    reload: () => undefined,
  };
}

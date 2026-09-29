import { CurrentTrip } from '@/models/trip/trip.model';
import { useSession } from '@/services/identity/session';
import { tripService } from '@/services/trip/trip.service';
import { crewService } from '@/services/trucker/crew.service';

import { AsyncState, useApi } from './use-api';

/**
 * The trip the driver is on right now.
 *
 * Three screens need it — Dashboard, Cargo and Tracking — and all three are
 * scoped to the caller rather than to an id in the URL, so the fetch is the
 * same call every time and belongs in one place.
 *
 * A trucker's driver reads theirs from `crew/trips/current`, which answers in
 * the same shape — so Tracking and Inspect are the same screens for them, and
 * never touch Cargo Rush's driver endpoints.
 *
 * `data: null` with no error means they are genuinely between runs; that is an
 * answer, not a failure, and each screen renders its own empty state for it.
 */
export function useCurrentTrip(): AsyncState<CurrentTrip | null> {
  const { me } = useSession();
  const crew = me?.role === 'trucker_driver';

  return useApi<CurrentTrip | null>(
    () => (crew ? crewService.current() : tripService.current()),
    [crew],
  );
}

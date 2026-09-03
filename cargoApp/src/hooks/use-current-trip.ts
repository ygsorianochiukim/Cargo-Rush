import { CurrentTrip } from '@/models/trip/trip.model';
import { tripService } from '@/services/trip/trip.service';

import { AsyncState, useApi } from './use-api';

/**
 * The trip the driver is on right now.
 *
 * Three screens need it — Dashboard, Cargo and Tracking — and all three are
 * scoped to the caller rather than to an id in the URL, so the fetch is the
 * same call every time and belongs in one place.
 *
 * `data: null` with no error means they are genuinely between runs; that is an
 * answer, not a failure, and each screen renders its own empty state for it.
 */
export function useCurrentTrip(): AsyncState<CurrentTrip | null> {
  return useApi<CurrentTrip | null>(() => tripService.current());
}

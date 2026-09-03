import { GpsPingPayload, TrackingState } from '@/models/gps/gps.model';

import { api } from '../shared/api.service';

/**
 * GPS Tracking.
 *
 * This app is the writer — the handset is the position source that feeds the
 * web GPS Dashboard (DESIGN.md section 5.4). The read below is this screen's
 * own view of what it has been reporting.
 */
export const gpsService = {
  tracking(tripId: string): Promise<TrackingState> {
    return api.get<TrackingState>(`gps/trips/${tripId}/tracking`);
  },

  /**
   * Post a position.
   *
   * `recorded_at` is stamped by the caller rather than the server, because a
   * unit in a dead spot records readings it can only send later — and the time
   * that matters is when the truck was there, not when the signal came back.
   */
  report(ping: GpsPingPayload): Promise<unknown> {
    return api.post('gps/pings', ping);
  },
};

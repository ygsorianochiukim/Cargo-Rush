import * as Location from 'expo-location';
import * as TaskManager from 'expo-task-manager';
import { Platform } from 'react-native';

import { CurrentTrip } from '@/models/trip/trip.model';

import { distanceM, headingOf, progressPct, LatLng } from './geometry';
import { gpsService } from './gps.service';
import { trackingStore, TrackingSession } from './tracking-store';

/** The task name Expo registers the background location handler under. */
export const LOCATION_TASK = 'cargorush-location';

/**
 * How often a position is reported.
 *
 * Sixty seconds **or** three hundred metres, whichever comes first. On a
 * highway that is a reading roughly every twenty seconds; parked at a depot
 * it settles to one a minute. The alternative — a fixed short interval — buys
 * precision nobody looks at and costs a driver their battery on a ten-hour
 * run, which is how fleet apps get closed.
 */
const INTERVAL_MS = 60_000;
const DISTANCE_M = 300;

/**
 * Reporting where the truck is.
 *
 * The handset is the position source that feeds the web GPS Dashboard
 * (DESIGN.md section 5.4), and this is the part that makes that true.
 *
 * It runs as a **background** task rather than a foreground watcher, because
 * the phone spends the trip in a cradle with the screen off — a foreground-only
 * watcher stops the moment the driver locks it, which is most of the run.
 *
 * Readings are stamped when taken, never when sent. A truck through a dead
 * spot records its positions and posts them on reconnect, and the office sees
 * where it was at the time rather than a straight line from the last bar of
 * signal.
 */
export const trackingService = {
  /**
   * Ask for permission.
   *
   * Foreground first, then background, because the OS requires that order and
   * a driver who declines the second still gets useful tracking while the app
   * is open.
   */
  async requestPermission(): Promise<'granted' | 'foreground-only' | 'denied'> {
    const foreground = await Location.requestForegroundPermissionsAsync();
    if (!foreground.granted) return 'denied';

    if (Platform.OS === 'web') return 'foreground-only';

    const background = await Location.requestBackgroundPermissionsAsync();

    return background.granted ? 'granted' : 'foreground-only';
  },

  async isTracking(): Promise<boolean> {
    if (Platform.OS === 'web') return false;

    return TaskManager.isTaskRegisteredAsync(LOCATION_TASK).catch(() => false);
  },

  /** Which trip is being reported on, if any. */
  session(): Promise<TrackingSession | null> {
    return trackingStore.session();
  },

  /**
   * Start reporting for a trip.
   *
   * The trip's own endpoints are written into the session so the background
   * task can work out progress without a network round trip per reading.
   */
  async start(trip: CurrentTrip): Promise<void> {
    const session: TrackingSession = {
      tripId: trip.id,
      reference: trip.reference,
      origin: pointOf(trip.origin_lat, trip.origin_lng),
      destination: pointOf(trip.destination_lat, trip.destination_lng),
      distanceDoneM: 0,
      last: null,
      startedAt: new Date().toISOString(),
    };

    await trackingStore.saveSession(session);

    if (Platform.OS === 'web') return;

    await Location.startLocationUpdatesAsync(LOCATION_TASK, {
      accuracy: Location.Accuracy.Balanced,
      timeInterval: INTERVAL_MS,
      distanceInterval: DISTANCE_M,
      // Android kills a location service with no visible notification. The
      // notice is also the honest thing: a driver should be able to see that
      // their position is being reported, and stop it.
      foregroundService: {
        notificationTitle: 'Cargo Rush is reporting your position',
        notificationBody: `Trip ${trip.reference}. Stop it from the Tracking tab.`,
        notificationColor: '#15589C',
      },
      pausesUpdatesAutomatically: false,
      showsBackgroundLocationIndicator: true,
    });
  },

  /** Stop reporting, and send anything still queued. */
  async stop(): Promise<void> {
    if (Platform.OS !== 'web' && (await trackingService.isTracking())) {
      await Location.stopLocationUpdatesAsync(LOCATION_TASK).catch(() => undefined);
    }

    await trackingService.flush();
    await trackingStore.clearSession();
  },

  /**
   * Record one position.
   *
   * Exported because the background task calls it, and because the Tracking
   * screen calls it once on start so the office sees the unit move
   * immediately rather than up to a minute later.
   */
  async record(coords: LatLng, speedMs: number | null, takenAt: Date): Promise<void> {
    const session = await trackingStore.session();
    if (session === null) return;

    const step = session.last === null ? 0 : distanceM(session.last, coords);
    const distanceDoneM = session.distanceDoneM + step;

    const ping = {
      trip_id: session.tripId,
      // The office reads a place name. Without a reverse geocode in the
      // background — which would be a network call per reading and a rate
      // limit breach — the coordinates are the honest answer.
      location: `${coords.lat.toFixed(5)}, ${coords.lng.toFixed(5)}`,
      speed_kph: Math.max(0, Math.round((speedMs ?? 0) * 3.6)),
      heading: session.last === null ? 'N' : headingOf(session.last, coords),
      progress_pct: progressPct(coords, session.origin, session.destination) ?? 0,
      distance_done_m: distanceDoneM,
      // Stamped when taken. This is the whole reason a dead spot does not
      // corrupt the trail.
      recorded_at: takenAt.toISOString(),
    };

    await trackingStore.saveSession({ ...session, distanceDoneM, last: coords });

    try {
      await gpsService.report(ping);
      // A successful send is also the signal that the network is back, so
      // anything held from the dead spot goes out behind it.
      await trackingService.flush();
    } catch {
      await trackingStore.enqueue(ping);
    }
  },

  /**
   * Send whatever is queued, oldest first.
   *
   * Stops at the first failure and puts the rest back. Carrying on would post
   * the trail out of order, and re-queueing only the failures would reorder
   * the run.
   */
  async flush(): Promise<number> {
    const queue = await trackingStore.queue();
    if (queue.length === 0) return 0;

    let sent = 0;

    for (const ping of queue) {
      try {
        await gpsService.report(ping);
        sent++;
      } catch {
        await trackingStore.replaceQueue(queue.slice(sent));

        return sent;
      }
    }

    await trackingStore.clearQueue();

    return sent;
  },

  queued(): Promise<number> {
    return trackingStore.queue().then((queue) => queue.length);
  },
};

function pointOf(lat: number | null, lng: number | null): LatLng | null {
  return lat === null || lng === null ? null : { lat, lng };
}

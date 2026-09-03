import * as Location from 'expo-location';
import * as TaskManager from 'expo-task-manager';

import { api } from '../shared/api.service';
import { tokenStore } from '../identity/token-store';
import { LOCATION_TASK, trackingService } from './tracking.service';

/**
 * The background location handler.
 *
 * Defined at module scope, and imported from the root layout, because Expo
 * requires the task to be registered before the app renders — the OS can
 * deliver a location to a task in a process that was launched for no other
 * reason, and if the definition is inside a component it will not be there.
 *
 * That also means this runs in a JavaScript context with **no React state and
 * no in-memory token**, so the token is read back from the keychain on every
 * delivery. Nothing here may assume the app is on screen, or that anything
 * else has run first.
 */
TaskManager.defineTask(LOCATION_TASK, async ({ data, error }) => {
  if (error) {
    // Nothing useful to do: throwing would kill the task and end tracking for
    // the rest of the trip over one bad fix.
    return;
  }

  const locations = (data as { locations?: Location.LocationObject[] } | undefined)?.locations;
  if (!locations?.length) return;

  if (api.token === null) {
    const token = await tokenStore.read();

    // No token means the driver signed out. The updates are stopped rather
    // than left running and failing every minute for the rest of the day.
    if (token === null) {
      await trackingService.stop().catch(() => undefined);

      return;
    }

    api.setToken(token);
  }

  // The OS batches deliveries, so a dead spot arrives as several at once.
  // Recorded in order, because the trail is a sequence.
  for (const location of locations) {
    await trackingService.record(
      { lat: location.coords.latitude, lng: location.coords.longitude },
      location.coords.speed,
      new Date(location.timestamp),
    );
  }
});

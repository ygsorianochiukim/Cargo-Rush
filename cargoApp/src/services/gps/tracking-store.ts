import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

import { GpsPingPayload } from '@/models/gps/gps.model';

import { LatLng } from './geometry';

const SESSION_KEY = 'cargorush.tracking.session';
const QUEUE_KEY = 'cargorush.tracking.queue';

/**
 * What a background task needs to know to report a position.
 *
 * It has to be written down rather than held in memory: a background location
 * task runs in its own JavaScript context, with no access to React state and
 * no guarantee the app that started it is still resident.
 */
export interface TrackingSession {
  tripId: string;
  reference: string;
  origin: LatLng | null;
  destination: LatLng | null;
  /** Path length so far, in metres. Summed across readings, so it persists. */
  distanceDoneM: number;
  /** The previous fix, for the next distance and heading. */
  last: LatLng | null;
  startedAt: string;
}

/**
 * Session state and the offline queue.
 *
 * `SecureStore` rather than another dependency. Nothing here is a secret, but
 * it is small, it survives a restart, and the token already lives there — one
 * storage mechanism in the app is worth more than the right one twice.
 *
 * Every read and write is guarded. Storage failing is not a reason to stop
 * reporting: the reading in hand is still good, and losing the queue is
 * better than crashing a background task.
 */
async function read(key: string): Promise<string | null> {
  try {
    if (Platform.OS === 'web') return globalThis.localStorage?.getItem(key) ?? null;

    return await SecureStore.getItemAsync(key);
  } catch {
    return null;
  }
}

async function write(key: string, value: string): Promise<void> {
  try {
    if (Platform.OS === 'web') {
      globalThis.localStorage?.setItem(key, value);

      return;
    }

    await SecureStore.setItemAsync(key, value);
  } catch {
    // Nothing to do — the caller carries on with what it has in memory.
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
    // As above.
  }
}

export const trackingStore = {
  async session(): Promise<TrackingSession | null> {
    const raw = await read(SESSION_KEY);
    if (raw === null) return null;

    try {
      return JSON.parse(raw) as TrackingSession;
    } catch {
      return null;
    }
  },

  async saveSession(session: TrackingSession): Promise<void> {
    await write(SESSION_KEY, JSON.stringify(session));
  },

  async clearSession(): Promise<void> {
    await remove(SESSION_KEY);
  },

  /**
   * Readings taken with no signal.
   *
   * Capped, and the **oldest** are dropped when it overflows. A phone out of
   * coverage for a whole day would otherwise fill its storage, and if
   * something has to be lost it should be the part of the route already
   * driven rather than where the truck is now.
   */
  async queue(): Promise<GpsPingPayload[]> {
    const raw = await read(QUEUE_KEY);
    if (raw === null) return [];

    try {
      const parsed = JSON.parse(raw);

      return Array.isArray(parsed) ? (parsed as GpsPingPayload[]) : [];
    } catch {
      return [];
    }
  },

  async enqueue(ping: GpsPingPayload, limit = 500): Promise<void> {
    const queue = await trackingStore.queue();
    queue.push(ping);

    await write(QUEUE_KEY, JSON.stringify(queue.slice(-limit)));
  },

  async replaceQueue(pings: GpsPingPayload[]): Promise<void> {
    if (pings.length === 0) {
      await remove(QUEUE_KEY);

      return;
    }

    await write(QUEUE_KEY, JSON.stringify(pings));
  },

  async clearQueue(): Promise<void> {
    await remove(QUEUE_KEY);
  },
};

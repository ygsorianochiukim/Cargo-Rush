import { useFocusEffect } from 'expo-router';
import { useCallback, useRef } from 'react';

/**
 * Re-fetch a screen's data every time it comes back into view.
 *
 * Tabs stay mounted when you leave them, so a screen's `useApi` calls ran once
 * and then held whatever they got — a run handed to a driver while they were
 * on another tab, or a check that passed on Inspect, did not show on My Trips
 * until the app was reloaded. This is the fix: on every return to the screen,
 * each `reload` is called.
 *
 * Not on the first focus — the fetches are already in flight from mounting,
 * and firing them twice would only flash a second skeleton.
 */
export function useRefreshOnFocus(...reloads: (() => void)[]): void {
  const first = useRef(true);

  useFocusEffect(
    useCallback(() => {
      if (first.current) {
        first.current = false;

        return;
      }

      reloads.forEach((reload) => reload());
      // The reload functions from `useApi` are stable, so this only changes
      // if a screen passes a different set.
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, reloads),
  );
}

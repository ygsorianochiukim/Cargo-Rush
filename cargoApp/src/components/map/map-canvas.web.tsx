import { useEffect, useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { Brand, Radius } from '@/constants/theme';

import { leafletPage } from './leaflet-page';
import { MapCanvasProps, parseMapMessage } from './map-canvas.types';

/**
 * The map on the web build: the same Leaflet page, in an `iframe`.
 *
 * `react-native-webview` has no web platform, and `expo start --web` is how
 * this app is demonstrated on a laptop — so rather than let the picker be a
 * blank box there, the one thing that differs is swapped out. Metro resolves
 * this file on web and the native one everywhere else; the page, the tiles,
 * the geocoder and the contract are identical.
 *
 * The talking is by `postMessage` in both directions, since there is no
 * script injection into a frame the host does not own the origin of.
 */
export function MapCanvas({ point, onPick, height = 260 }: MapCanvasProps) {
  const frame = useRef<HTMLIFrameElement | null>(null);
  const [html] = useState(() => leafletPage(point));

  const ready = useRef(false);
  const pending = useRef<object | null>(null);
  const fromPage = useRef(false);
  const mounted = useRef(false);

  const post = (message: object) => {
    if (!ready.current) {
      pending.current = message;

      return;
    }

    frame.current?.contentWindow?.postMessage(JSON.stringify(message), '*');
  };

  useEffect(() => {
    const onMessage = (event: MessageEvent) => {
      // Every frame on the page can post here, including browser extensions.
      if (event.source !== frame.current?.contentWindow) return;

      const message = parseMapMessage(event.data);
      if (message === null) return;

      if (message.type === 'ready') {
        ready.current = true;

        if (pending.current !== null) {
          frame.current?.contentWindow?.postMessage(JSON.stringify(pending.current), '*');
          pending.current = null;
        }

        return;
      }

      fromPage.current = true;
      onPick(message.lat, message.lng);
    };

    window.addEventListener('message', onMessage);

    return () => window.removeEventListener('message', onMessage);
  }, [onPick]);

  // The coordinates, not the point object: a pin whose *name* has just arrived
  // from the geocoder is the same pin, and redrawing it would re-centre the
  // map a second after somebody clicked it.
  const lat = point?.lat ?? null;
  const lng = point?.lng ?? null;

  useEffect(() => {
    if (!mounted.current) {
      mounted.current = true;

      return;
    }

    if (fromPage.current) {
      fromPage.current = false;

      return;
    }

    post(
      lat === null || lng === null
        ? { type: 'clear' }
        : { type: 'set', lat, lng, recentre: true },
    );
  }, [lat, lng]);

  return (
    <View style={[styles.frame, { height }]}>
      <iframe
        ref={frame}
        srcDoc={html}
        title="Map. Tap to place the pin, or search above."
        style={{ border: 0, width: '100%', height: '100%', display: 'block' }}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  frame: {
    overflow: 'hidden',
    borderRadius: Radius.card,
    borderWidth: 1,
    borderColor: Brand.line,
    backgroundColor: Brand.tint,
  },
});

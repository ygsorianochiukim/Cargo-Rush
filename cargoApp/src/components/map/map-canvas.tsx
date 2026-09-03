import { useEffect, useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { WebView, WebViewMessageEvent } from 'react-native-webview';

import { Brand, Radius } from '@/constants/theme';

import { leafletPage } from './leaflet-page';
import { MapCanvasProps, parseMapMessage } from './map-canvas.types';

/**
 * The map on a handset: the Leaflet page in a `WebView`.
 *
 * The page is built once, from the point the sheet opened on, and never
 * rebuilt — a new `source` reloads the WebView, and re-loading the map on
 * every keystroke in the search box above it would be a blank grey box for
 * most of the time somebody is typing. Every later change is a one-line call
 * injected into the page that is already running.
 */
export function MapCanvas({ point, onPick, height = 260 }: MapCanvasProps) {
  const webview = useRef<WebView>(null);
  const [html] = useState(() => leafletPage(point));

  /** Injection is silently lost before the page loads, so it is held here. */
  const ready = useRef(false);
  const pending = useRef<string | null>(null);

  /** The pin this component was told about is already drawn on the page. */
  const fromPage = useRef(false);
  const mounted = useRef(false);

  const run = (script: string) => {
    if (ready.current) {
      webview.current?.injectJavaScript(`${script} true;`);

      return;
    }

    pending.current = script;
  };

  // The coordinates, not the point object: a pin whose *name* has just arrived
  // from the geocoder is the same pin, and redrawing it would re-centre the
  // map a second after somebody tapped it.
  const lat = point?.lat ?? null;
  const lng = point?.lng ?? null;

  useEffect(() => {
    // The point the map opened on is baked into the page already.
    if (!mounted.current) {
      mounted.current = true;

      return;
    }

    // A pin the user just placed is on screen where their thumb left it.
    // Re-centring on it would move the map out from under them.
    if (fromPage.current) {
      fromPage.current = false;

      return;
    }

    if (lat === null || lng === null) {
      run('window.__clearPin && window.__clearPin();');

      return;
    }

    run(`window.__setPin && window.__setPin(${lat}, ${lng}, true);`);
  }, [lat, lng]);

  const onMessage = (event: WebViewMessageEvent) => {
    const message = parseMapMessage(event.nativeEvent.data);
    if (message === null) return;

    if (message.type === 'ready') {
      ready.current = true;

      if (pending.current !== null) {
        webview.current?.injectJavaScript(`${pending.current} true;`);
        pending.current = null;
      }

      return;
    }

    fromPage.current = true;
    onPick(message.lat, message.lng);
  };

  return (
    <View style={[styles.frame, { height }]}>
      <WebView
        ref={webview}
        source={{ html }}
        onMessage={onMessage}
        // The page is ours and inline, so there is no origin to allow; this is
        // what lets a `html` source load at all.
        originWhitelist={['*']}
        javaScriptEnabled
        // The map does its own panning. Letting the WebView scroll as well
        // means a drag sometimes moves the page instead of the map.
        scrollEnabled={false}
        // Android draws a white page over the tint for an instant otherwise.
        androidLayerType="hardware"
        style={styles.web}
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
  web: { flex: 1, backgroundColor: Brand.tint },
});

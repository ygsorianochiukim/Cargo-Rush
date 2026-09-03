import { GeoPoint } from '@/models/geo/geo.model';

/**
 * The map, as the rest of the app sees it.
 *
 * Two implementations sit behind this: a `WebView` on a handset and an
 * `iframe` on the web build, because `react-native-webview` has no web
 * platform. Metro picks between them by filename — `map-canvas.web.tsx` wins
 * on web, `map-canvas.tsx` everywhere else — so nothing above this file knows
 * which one it is talking to, and the web bundle never sees the native module.
 *
 * Both draw the same page (`leaflet-page.ts`) and honour the same contract:
 * the point is owned by React and pushed down, and a pin the *user* moves
 * comes back up through `onPick`.
 */
export interface MapCanvasProps {
  /** Where the pin is. Null means nothing is pinned yet. */
  point: GeoPoint | null;

  /**
   * The user moved the pin on the map — a tap or a drag, never a change this
   * component was told about. The distinction matters: re-centring on a pin
   * somebody just placed with their thumb would move the map out from under
   * them.
   */
  onPick: (lat: number, lng: number) => void;

  /** Fixed, because a map in an auto-height box measures zero and renders grey. */
  height?: number;
}

/** What the page sends up. */
export type MapMessage = { type: 'ready' } | { type: 'pick'; lat: number; lng: number };

/** Read one, or null if it is not ours — the web hears every frame on the page. */
export function parseMapMessage(raw: unknown): MapMessage | null {
  if (typeof raw !== 'string') return null;

  try {
    const data = JSON.parse(raw) as MapMessage;

    if (data?.type === 'ready') return data;
    if (data?.type === 'pick' && Number.isFinite(data.lat) && Number.isFinite(data.lng)) {
      return data;
    }

    return null;
  } catch {
    return null;
  }
}

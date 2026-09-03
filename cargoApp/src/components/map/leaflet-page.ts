import { GeoPoint } from '@/models/geo/geo.model';

/**
 * Where the map opens when there is nothing to centre on — Iponan, Cagayan de
 * Oro, which is where the fleet actually runs from. The same default the back
 * office uses, so both clients open on the same yard.
 */
const DEFAULT_CENTRE: [number, number] = [8.4856, 124.5808];

/** Street level. A barangay default earns it; a metro-wide guess would not. */
const ZOOM = 13;

/**
 * The map itself: Leaflet and OpenStreetMap tiles, in a page.
 *
 * The back office draws its picker with Leaflet directly. React Native has no
 * Leaflet, and the two native map libraries would each cost something real —
 * `expo-maps` is alpha, needs a development build and does not run on web at
 * all, and Google Maps wants a billing account this install does not have. A
 * page in a `WebView` is the same map, the same tiles and the same behaviour
 * as the desk, on a dependency that ships inside Expo Go.
 *
 * It reports and receives, and holds no state worth keeping: the coordinates
 * live in React, and this is a view of them that happens to be interactive.
 *
 *   page -> host   `{ type: 'pick', lat, lng }` on a tap or a dragged pin
 *   host -> page   `window.__setPin(lat, lng)` / `window.__clearPin()`
 *
 * The pin is draggable because a tap on a phone is a thumb wide, and the yard
 * gate is not.
 */
export function leafletPage(initial: GeoPoint | null): string {
  const centre = initial ? [initial.lat, initial.lng] : DEFAULT_CENTRE;

  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" />
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
  html, body, #map { height: 100%; margin: 0; padding: 0; background: #DFF0FF; }
  .leaflet-container { font: 12px/1.4 -apple-system, system-ui, sans-serif; }
  /* The hint sits over the map until the first pin is down, because a map
     with no marker gives no clue that tapping it does anything. */
  #hint {
    position: absolute; z-index: 500; left: 8px; right: 8px; top: 8px;
    padding: 6px 10px; border-radius: 8px; text-align: center;
    background: rgba(255,255,255,0.92); color: #1F1F1F;
    font: 600 12px/1.4 -apple-system, system-ui, sans-serif;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
  }
</style>
</head>
<body>
<div id="map"></div>
<div id="hint">Tap the map to drop a pin</div>
<script>
  (function () {
    var map = L.map('map', {
      center: [${centre[0]}, ${centre[1]}],
      zoom: ${ZOOM},
      zoomControl: true,
      // Wheel zoom inside a sheet hijacks the scroll somebody meant for the
      // sheet. Pinch is untouched, which is how a phone zooms anyway.
      scrollWheelZoom: false,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    var marker = null;
    var hint = document.getElementById('hint');

    function send(payload) {
      var message = JSON.stringify(payload);

      if (window.ReactNativeWebView) {
        window.ReactNativeWebView.postMessage(message);
      } else if (window.parent !== window) {
        window.parent.postMessage(message, '*');
      }
    }

    function place(lat, lng) {
      if (hint) hint.style.display = 'none';

      if (marker === null) {
        marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        marker.on('dragend', function () {
          var at = marker.getLatLng();
          send({ type: 'pick', lat: at.lat, lng: at.lng });
        });

        return;
      }

      marker.setLatLng([lat, lng]);
    }

    // Host -> page. Moving the pin from a search result must not report back
    // as a pick, or the name just chosen would be looked up and overwritten.
    window.__setPin = function (lat, lng, recentre) {
      place(lat, lng);
      if (recentre) map.setView([lat, lng], Math.max(map.getZoom(), 14));
    };

    window.__clearPin = function () {
      if (marker !== null) { marker.remove(); marker = null; }
      if (hint) hint.style.display = '';
    };

    // The web build talks to the page through the iframe rather than by
    // injecting script into it, so the same two calls arrive as messages.
    window.addEventListener('message', function (event) {
      var data = event.data;
      try { data = typeof data === 'string' ? JSON.parse(data) : data; } catch (e) { return; }
      if (!data || typeof data !== 'object') return;

      if (data.type === 'set') window.__setPin(data.lat, data.lng, data.recentre);
      if (data.type === 'clear') window.__clearPin();
    });

    map.on('click', function (event) {
      place(event.latlng.lat, event.latlng.lng);
      send({ type: 'pick', lat: event.latlng.lat, lng: event.latlng.lng });
    });

    ${initial ? `place(${initial.lat}, ${initial.lng});` : ''}

    send({ type: 'ready' });
  })();
</script>
</body>
</html>`;
}

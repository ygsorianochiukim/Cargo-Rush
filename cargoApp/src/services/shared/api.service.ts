import Constants from 'expo-constants';
import { Platform } from 'react-native';

import { ApiError, Envelope, ListQuery } from '@/models/shared/envelope.model';

/**
 * The one place a request leaves this app.
 *
 * cargoApp authenticates with a Sanctum bearer token, not a cookie — a
 * handset has no browser session to ride on (DESIGN.md section 7.4). The
 * token is held here and attached to every call, so no module service has to
 * remember to.
 */

/**
 * Where the API lives.
 *
 * On a device, `localhost` is the handset — not the laptop running Laravel —
 * so the host is taken from whatever machine served the JavaScript bundle,
 * which is that laptop. `EXPO_PUBLIC_API_URL` overrides it outright.
 *
 * The API has to be listening on that address for this to reach it. Laravel's
 * `php artisan serve` binds to `127.0.0.1` by default, which a phone cannot
 * see however good the Wi-Fi is; it needs `--host=0.0.0.0`. That is the usual
 * cause of a driver app that cannot reach a server which is plainly running.
 */
function resolveBaseUrl(): string {
  const configured = process.env.EXPO_PUBLIC_API_URL;
  if (configured) return configured;

  if (Platform.OS === 'web') return 'http://localhost:8000';

  const host = Constants.expoConfig?.hostUri?.split(':')[0];

  return host ? `http://${host}:8000` : 'http://localhost:8000';
}

/** Exposed so an error message can name the address that failed. */
export const apiBaseUrl = resolveBaseUrl();

const BASE = `${apiBaseUrl}/api/v1`;

/** A failed call, carrying the status and the API's own error shape. */
export class ApiRequestError extends Error {
  constructor(
    readonly status: number,
    readonly body: ApiError,
  ) {
    super(body.message || `Request failed with ${status}`);
    this.name = 'ApiRequestError';
  }

  /** Field errors from a 422, ready to hang off form inputs. */
  get fieldErrors(): Record<string, string[]> {
    return this.body.errors ?? {};
  }
}

let token: string | null = null;

export const api = {
  /** Held in memory only; persisting it is the auth screen's decision. */
  setToken(next: string | null): void {
    token = next;
  },

  get token(): string | null {
    return token;
  },

  /** The full envelope, for a caller that needs `meta` as well as `data`. */
  async envelope<T>(path: string, query?: ListQuery): Promise<Envelope<T>> {
    const response = await fetch(url(path, query), { headers: headers() });

    return unwrap<T>(response);
  },

  /** Just the payload — what most callers want. */
  async get<T>(path: string, query?: ListQuery): Promise<T> {
    return (await api.envelope<T>(path, query)).data;
  },

  /** POST returning the full envelope — login needs `meta.token`. */
  async postEnvelope<T>(path: string, body: unknown): Promise<Envelope<T>> {
    const response = await fetch(url(path), {
      method: 'POST',
      headers: { ...headers(), 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });

    return unwrap<T>(response);
  },

  async post<T>(path: string, body: unknown): Promise<T> {
    return (await api.postEnvelope<T>(path, body)).data;
  },

  /**
   * A multipart POST, for a write whose substance is a file.
   *
   * Only proof of delivery needs it: the photograph taken at the door is the
   * evidence, and base64 in a JSON body would inflate a 3 MB phone photo by a
   * third over a connection that is often a warehouse's worth of concrete away
   * from a mast.
   *
   * `Content-Type` is deliberately not set. `fetch` writes it itself for a
   * `FormData` body, including the multipart boundary — setting it by hand
   * omits the boundary and the server cannot parse a single field.
   */
  async postForm<T>(path: string, body: FormData): Promise<T> {
    const response = await fetch(url(path), {
      method: 'POST',
      headers: headers(),
      body,
    });

    return (await unwrap<T>(response)).data;
  },

  async patch<T>(path: string, body: unknown): Promise<T> {
    const response = await fetch(url(path), {
      method: 'PATCH',
      headers: { ...headers(), 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });

    return (await unwrap<T>(response)).data;
  },
};

function headers(): Record<string, string> {
  return {
    Accept: 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };
}

function url(path: string, query?: ListQuery): string {
  const clean = `${BASE}/${path.replace(/^\//, '')}`;

  if (!query) return clean;

  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    // An empty `search=` would make the API filter on the empty string
    // rather than skip the filter.
    if (value === undefined || value === null || value === '') continue;
    if (Array.isArray(value)) value.forEach((v) => params.append(`${key}[]`, String(v)));
    else params.set(key, String(value));
  }

  const qs = params.toString();

  return qs ? `${clean}?${qs}` : clean;
}

async function unwrap<T>(response: Response): Promise<Envelope<T>> {
  // 204 has no body; parsing it would throw on an empty string.
  if (response.status === 204) return { data: undefined as T };

  const body = await response.json().catch(() => ({ message: 'Unreadable response.' }));

  if (!response.ok) throw new ApiRequestError(response.status, body as ApiError);

  return body as Envelope<T>;
}

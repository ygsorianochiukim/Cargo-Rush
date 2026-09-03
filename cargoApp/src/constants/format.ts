/**
 * Display formatting. The API sends ISO-8601 UTC and integer base units
 * (DESIGN.md section 7.1); the client decides how to read them.
 * Mirrors CargoUI/src/app/shared/format.ts.
 */
export const fmt = {
  dateTime(value: string | null | undefined): string {
    if (!value) return '—';
    return new Date(value).toLocaleString([], {
      day: '2-digit',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit',
    });
  },

  time(value: string | null | undefined): string {
    if (!value) return '—';
    return new Date(value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  },

  date(value: string | null | undefined): string {
    if (!value) return '—';
    return new Date(value).toLocaleDateString([], {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
    });
  },

  /**
   * Centavos on the wire, pesos on screen (DESIGN.md section 7.1).
   *
   * The API never formats money and never sends a float, so this is the only
   * place a peso sign appears. Written to match
   * `CargoUI/src/app/shared/format.ts` character for character: the same
   * amount has to read the same on the phone and on the desk, or the two
   * screens look like they disagree about the figure.
   */
  money(cents: number, currency = 'PHP'): string {
    const symbol = currency === 'PHP' ? '₱' : '';

    return `${symbol}${(cents / 100).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
  },

  kg(value: number): string {
    return `${value.toLocaleString()} kg`;
  },

  km(value: number): string {
    return `${value.toLocaleString()} km`;
  },

  /** Metres on the wire, kilometres on screen. */
  metresAsKm(value: number): string {
    return `${(value / 1000).toFixed(1)} km`;
  },
};

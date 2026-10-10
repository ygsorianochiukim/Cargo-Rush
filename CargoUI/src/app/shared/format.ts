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

  money(cents: number, currency = 'PHP'): string {
    const symbol = currency === 'PHP' ? '₱' : '';
    return `${symbol}${(cents / 100).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
  },

  pesos(cents: number, currency = 'PHP'): string {
    const symbol = currency === 'PHP' ? '₱' : '';

    return `${symbol}${(cents / 100).toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })}`;
  },

  kg(value: number): string {
    return `${value.toLocaleString()} kg`;
  },

  km(value: number): string {
    return `${value.toLocaleString()} km`;
  },

  litres(value: number): string {
    return `${value.toLocaleString()} L`;
  },
};

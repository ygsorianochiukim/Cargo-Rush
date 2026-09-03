import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import { Icon } from '@/components/ui/icon';
import { Brand, Radius, Spacing } from '@/constants/theme';
import { CurrentTrip } from '@/models/trip/trip.model';
import { trackingService } from '@/services/gps/tracking.service';

/**
 * Start and stop reporting position.
 *
 * Deliberately a switch a driver throws, not something the app does on their
 * behalf. Background location is the most invasive permission this app asks
 * for, and starting it silently because a trip happens to be in transit is
 * how an app gets uninstalled — and, on iOS, rejected. The state is visible
 * the whole time it is on.
 */
export function TrackingControl({ trip }: { trip: CurrentTrip }) {
  const [tracking, setTracking] = useState(false);
  const [busy, setBusy] = useState(false);
  const [queued, setQueued] = useState(0);
  const [notice, setNotice] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    const [isTracking, backlog, session] = await Promise.all([
      trackingService.isTracking(),
      trackingService.queued(),
      trackingService.session(),
    ]);

    // Tracking a different trip is stale — it belongs to a run that ended.
    setTracking(isTracking && session?.tripId === trip.id);
    setQueued(backlog);
  }, [trip.id]);

  useEffect(() => {
    void refresh();

    // The background task sends on its own schedule, so the count of held
    // readings is polled rather than pushed.
    const timer = setInterval(() => void refresh(), 15_000);

    return () => clearInterval(timer);
  }, [refresh]);

  const start = async () => {
    setBusy(true);
    setNotice(null);

    const permission = await trackingService.requestPermission();

    if (permission === 'denied') {
      setNotice('Location is off for Cargo Rush. Turn it on in your phone settings to report position.');
      setBusy(false);

      return;
    }

    if (permission === 'foreground-only') {
      setNotice(
        'Reporting only while the app is open. For a whole trip, set location to "Allow all the time".',
      );
    }

    try {
      await trackingService.start(trip);
      await refresh();
    } catch {
      setNotice('Could not start reporting. Check that location is switched on.');
    } finally {
      setBusy(false);
    }
  };

  const stop = async () => {
    setBusy(true);

    try {
      await trackingService.stop();
      await refresh();
    } finally {
      setBusy(false);
    }
  };

  return (
    <View style={[styles.card, tracking && styles.cardOn]}>
      <View style={styles.row}>
        <View style={[styles.dot, { backgroundColor: tracking ? Brand.success : Brand.inkMuted }]} />
        <View style={{ flex: 1, minWidth: 0 }}>
          <Text style={styles.title}>
            {tracking ? 'Reporting your position' : 'Not reporting'}
          </Text>
          <Text style={styles.sub} numberOfLines={2}>
            {tracking
              ? `The office can see ${trip.reference} on the map. Every minute, or every 300 m.`
              : 'Turn this on when you set off, so dispatch can see where you are.'}
          </Text>
        </View>
      </View>

      {queued > 0 ? (
        <View style={styles.queued}>
          <Icon name="clock" size={13} color={Brand.warning} />
          <Text style={styles.queuedText}>
            {queued} reading{queued === 1 ? '' : 's'} held — they will send when you have signal.
          </Text>
        </View>
      ) : null}

      {notice ? (
        <Text style={styles.notice} accessibilityLiveRegion="polite">
          {notice}
        </Text>
      ) : null}

      <Pressable
        accessibilityRole="button"
        accessibilityLabel={tracking ? 'Stop reporting position' : 'Start reporting position'}
        accessibilityState={{ disabled: busy }}
        disabled={busy}
        onPress={tracking ? stop : start}
        style={({ pressed }) => [
          styles.button,
          tracking ? styles.buttonStop : styles.buttonStart,
          pressed && { opacity: 0.85 },
          busy && { opacity: 0.5 },
        ]}>
        {busy ? (
          <ActivityIndicator color={tracking ? Brand.red : Brand.surface} />
        ) : (
          <>
            <Icon
              name={tracking ? 'close' : 'map-pin'}
              size={16}
              color={tracking ? Brand.red : Brand.surface}
            />
            <Text style={[styles.buttonText, tracking && { color: Brand.red }]}>
              {tracking ? 'Stop reporting' : 'Start reporting'}
            </Text>
          </>
        )}
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: Brand.surface,
    borderRadius: Radius.card,
    padding: Spacing.three,
    borderWidth: 1,
    borderColor: Brand.line,
    gap: Spacing.three,
  },
  cardOn: { borderColor: Brand.success },

  row: { flexDirection: 'row', alignItems: 'flex-start', gap: Spacing.three },
  dot: { width: 10, height: 10, borderRadius: Radius.full, marginTop: 5 },
  title: { fontSize: 15, fontWeight: '600', color: Brand.ink },
  sub: { marginTop: 2, fontSize: 12, color: Brand.inkMuted },

  queued: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    padding: Spacing.two,
    borderRadius: Radius.control,
    backgroundColor: Brand.warningBg,
  },
  queuedText: { flex: 1, fontSize: 12, fontWeight: '500', color: Brand.warning },

  notice: { fontSize: 12, color: Brand.inkMuted },

  button: {
    height: 48,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    borderRadius: Radius.control,
  },
  buttonStart: { backgroundColor: Brand.blue },
  buttonStop: { backgroundColor: Brand.redBg },
  buttonText: { color: Brand.surface, fontSize: 15, fontWeight: '600' },
});

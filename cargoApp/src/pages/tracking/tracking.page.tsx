import { StyleSheet, Text, View } from 'react-native';

import { TrackingControl } from '@/components/tracking-control';
import { gpsService } from '@/services/gps/gps.service';
import { Screen } from '@/components/screen';
import { Icon } from '@/components/ui/icon';
import { Card, EmptyState, SkeletonRows } from '@/components/ui/primitives';
import { Brand, Radius, Spacing } from '@/constants/theme';
import { fmt } from '@/constants/format';
import { useApi } from '@/hooks/use-api';
import { useCurrentTrip } from '@/hooks/use-current-trip';

/**
 * GPS Tracking — DESIGN.md section 5.2: average speed, location point A to B.
 * The handset is the position source that feeds the web GPS Dashboard (5.4).
 */
export function TrackingPage() {
  const trip = useCurrentTrip();

  // The track belongs to a trip, so this waits for one. Asking for the
  // tracking state of "no trip" would be a 404 dressed up as an error.
  const { data, loading, error, reload } = useApi(
    () => (trip.data ? gpsService.tracking(trip.data.id) : Promise.resolve(null)),
    [trip.data?.id],
  );

  if (trip.loading) {
    return (
      <Screen title="Tracking">
        <Card>
          <SkeletonRows count={4} />
        </Card>
      </Screen>
    );
  }

  // No run means nothing to report on. The control is deliberately not shown:
  // there is no trip to attach the positions to.
  if (trip.data === null) {
    return (
      <Screen title="Tracking">
        <Card>
          <EmptyState
            title="No active trip"
            body="Tracking starts once a trip is dispatched to you."
          />
        </Card>
      </Screen>
    );
  }

  // A trip with no positions yet is the normal state before the driver
  // presses start — not an error. Showing an error here would hide the only
  // control that can fix it.
  if (!data) {
    return (
      <Screen title="Tracking" subtitle={trip.data.reference}>
        <TrackingControl trip={trip.data} />

        <Card style={{ marginTop: Spacing.three }}>
          <EmptyState
            title="No positions reported yet"
            body={
              loading
                ? "Checking for positions…"
                : "Start reporting above and your position appears here, and on the office map."
            }
          />
        </Card>
      </Screen>
    );
  }

  const remaining = Math.max(0, data.distance_total_m - data.distance_done_m);

  return (
    <Screen title="Tracking" subtitle={data.reference}>
      <TrackingControl trip={trip.data} />

      {/* Speed */}
      <View style={styles.speedRow}>
        <Card style={styles.speedCard}>
          <Text style={styles.metaLabel}>CURRENT SPEED</Text>
          <View style={styles.speedValueRow}>
            <Text style={styles.speedValue}>{data.speed_kph}</Text>
            <Text style={styles.speedUnit}>km/h</Text>
          </View>
        </Card>
        <Card style={styles.speedCard}>
          <Text style={styles.metaLabel}>AVERAGE SPEED</Text>
          <View style={styles.speedValueRow}>
            <Text style={styles.speedValue}>{data.average_speed_kph}</Text>
            <Text style={styles.speedUnit}>km/h</Text>
          </View>
        </Card>
      </View>

      {/* Point A to B */}
      <Card heading="Point A to B" icon="route">
        <View style={styles.abRow}>
          <View style={[styles.abDot, { borderColor: Brand.success }]} />
          <View style={styles.abTrack}>
            <View style={[styles.abFill, { width: `${data.progress_pct}%` }]} />
            <View style={[styles.abMarker, { left: `${data.progress_pct}%` }]}>
              <Icon name="fleet" size={12} color={Brand.surface} />
            </View>
          </View>
          <View style={[styles.abDot, { borderColor: Brand.blue }]} />
        </View>

        <View style={styles.abLabels}>
          <View style={{ flex: 1, minWidth: 0 }}>
            <Text style={styles.metaLabel}>POINT A</Text>
            <Text style={styles.abPlace} numberOfLines={2}>
              {data.point_a}
            </Text>
          </View>
          <View style={{ flex: 1, minWidth: 0, alignItems: 'flex-end' }}>
            <Text style={styles.metaLabel}>POINT B</Text>
            <Text style={[styles.abPlace, { textAlign: 'right' }]} numberOfLines={2}>
              {data.point_b}
            </Text>
          </View>
        </View>

        <View style={styles.divider} />

        <View style={styles.statRow}>
          <Stat label="TRAVELLED" value={fmt.metresAsKm(data.distance_done_m)} />
          <Stat label="REMAINING" value={fmt.metresAsKm(remaining)} />
          <Stat label="PROGRESS" value={`${data.progress_pct}%`} />
        </View>
      </Card>

      {/* Current location */}
      <Card heading="Current location" icon="map-pin" hint={`ETA ${fmt.time(data.eta)}`}>
        <View style={styles.locRow}>
          <View style={styles.locPin}>
            <Icon name="map-pin" size={18} color={Brand.surface} />
          </View>
          <View style={{ flex: 1, minWidth: 0 }}>
            <Text style={styles.locPlace}>{data.current_location}</Text>
            <Text style={styles.locSub}>Position shared with dispatch</Text>
          </View>
        </View>
      </Card>
    </Screen>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <View style={{ flex: 1 }}>
      <Text style={styles.metaLabel}>{label}</Text>
      <Text style={styles.statValue}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  metaLabel: { fontSize: 10, fontWeight: '500', letterSpacing: 0.6, color: Brand.inkMuted },

  speedRow: { flexDirection: 'row', gap: Spacing.three },
  speedCard: { flex: 1, padding: Spacing.three },
  speedValueRow: { flexDirection: 'row', alignItems: 'baseline', gap: 4, marginTop: 6 },
  speedValue: { fontSize: 30, fontWeight: '600', color: Brand.ink, fontVariant: ['tabular-nums'] },
  speedUnit: { fontSize: 12, color: Brand.inkMuted },

  abRow: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two },
  abDot: { width: 14, height: 14, borderRadius: Radius.full, borderWidth: 3 },
  abTrack: {
    flex: 1,
    height: 8,
    borderRadius: Radius.full,
    backgroundColor: Brand.line,
    justifyContent: 'center',
  },
  abFill: { height: '100%', borderRadius: Radius.full, backgroundColor: Brand.blue },
  abMarker: {
    position: 'absolute',
    width: 24,
    height: 24,
    marginLeft: -12,
    borderRadius: Radius.full,
    backgroundColor: Brand.blue,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: Brand.surface,
  },
  abLabels: { flexDirection: 'row', gap: Spacing.three, marginTop: Spacing.three },
  abPlace: { marginTop: 3, fontSize: 13, color: Brand.ink },

  divider: {
    height: StyleSheet.hairlineWidth,
    backgroundColor: Brand.line,
    marginVertical: Spacing.three,
  },
  statRow: { flexDirection: 'row', gap: Spacing.two },
  statValue: {
    marginTop: 3,
    fontSize: 15,
    fontWeight: '600',
    color: Brand.ink,
    fontVariant: ['tabular-nums'],
  },

  locRow: { flexDirection: 'row', alignItems: 'center', gap: Spacing.three },
  locPin: {
    width: 40,
    height: 40,
    borderRadius: Radius.full,
    backgroundColor: Brand.blue,
    alignItems: 'center',
    justifyContent: 'center',
  },
  locPlace: { fontSize: 15, fontWeight: '600', color: Brand.ink },
  locSub: { marginTop: 2, fontSize: 12, color: Brand.inkMuted },
});

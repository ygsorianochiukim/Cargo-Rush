import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, Switch, Text, View } from 'react-native';

import { Trip } from '@/models/trip/trip.model';
import { identityService } from '@/services/identity/identity.service';
import { notificationService } from '@/services/notification/notification.service';
import { tripService } from '@/services/trip/trip.service';
import { DailyLogSheet } from '@/components/daily-log-sheet';
import { ProofOfDeliverySheet } from '@/components/proof-of-delivery-sheet';
import { Screen } from '@/components/screen';
import { Icon } from '@/components/ui/icon';
import { Card, EmptyState, ErrorState, SkeletonRows, StatusPill } from '@/components/ui/primitives';
import { TONE_COLORS } from '@/constants/status';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { fmt } from '@/constants/format';
import { useApi } from '@/hooks/use-api';
import { useMe } from '@/hooks/use-me';
import { useCurrentTrip } from '@/hooks/use-current-trip';

/**
 * Driver dashboard — DESIGN.md section 5.2.
 * Driver availability · notification for new delivery · pending delivery list ·
 * current trip · upcoming trips · current location.
 */
export function DashboardPage() {
  const me = useMe();
  const trip = useCurrentTrip();
  const pending = useApi(tripService.pending);
  const upcoming = useApi(tripService.upcoming);
  const notifications = useApi(() => notificationService.list(5));

  // Seeded from the driver record, then owned locally so the switch answers
  // immediately rather than after a round trip.
  const [available, setAvailable] = useState<boolean | null>(null);
  const isAvailable = available ?? me.data?.available ?? false;

  const setAvailability = (next: boolean) => {
    setAvailable(next);
    if (me.data?.driver_id) {
      // A failed write reverts the switch — it must not claim a state the
      // dispatcher cannot see.
      identityService
        .setAvailability(me.data.driver_id, next)
        .catch(() => setAvailable(!next));
    }
  };
  const [logOpen, setLogOpen] = useState(false);
  const [podOpen, setPodOpen] = useState(false);

  // Which run is being started, so its own row can say so rather than the
  // whole list going quiet.
  const [starting, setStarting] = useState<string | null>(null);
  const [startError, setStartError] = useState<string | null>(null);

  const startTrip = (t: Trip) => {
    setStarting(t.id);
    setStartError(null);

    tripService
      .start(t.id)
      .then(() => {
        // The run moves out of the queue and into Current, so both are stale.
        trip.reload();
        pending.reload();
      })
      .catch((e: Error) => setStartError(e.message))
      .finally(() => setStarting(null));
  };

  const greeting = () => {
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 18) return 'Good afternoon';
    return 'Good evening';
  };

  const today = new Date().toLocaleDateString([], {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  });

  const pendingCount = (pending.data ?? []).length;
  const upcomingCount = (upcoming.data ?? []).length;
  const summary = trip.data
    ? `${trip.data.reference} is ${trip.data.progress_pct}% of the way to ${trip.data.destination}.` +
      (pendingCount > 0 ? ` ${pendingCount} more waiting after it.` : '')
    : pendingCount + upcomingCount > 0
      ? `${pendingCount + upcomingCount} deliveries lined up for you.`
      : 'Nothing assigned yet — you are clear for now.';

  return (
    <Screen
      title="Dashboard"
      brand
      right={
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Notifications"
          style={styles.iconBtn}>
          <Icon name="bell" size={20} color={Brand.ink} />
          {(notifications.data ?? []).length > 0 ? <View style={styles.notifDot} /> : null}
        </Pressable>
      }>
      {/* Intro: who, when, and what today looks like at a glance */}
      <View style={styles.intro}>
        <Text style={styles.greeting}>
          {greeting()}
          {me.data ? `, ${me.data.name.split(' ')[0]}` : ''}
        </Text>
        <Text style={styles.introDate}>{today}</Text>
        <Text style={styles.introSummary}>{summary}</Text>
      </View>

      {/* Driver availability */}
      <Card>
        <View style={styles.availRow}>
          <View
            style={[
              styles.availDot,
              { backgroundColor: isAvailable ? Brand.success : Brand.inkMuted },
            ]}
          />
          <View style={{ flex: 1, minWidth: 0 }}>
            <Text style={styles.availTitle}>
              {isAvailable ? 'Available for dispatch' : 'Not available'}
            </Text>
            <Text style={styles.availSub} numberOfLines={1}>
              {me.data ? `${me.data.name} · ${me.data.role_label}` : 'Loading…'}
            </Text>
          </View>
          <Switch
            value={isAvailable}
            onValueChange={setAvailability}
            accessibilityLabel="Driver availability"
            trackColor={{ true: Brand.blue, false: Brand.line }}
            thumbColor={Brand.surface}
          />
        </View>
      </Card>

      {/* Notification for new delivery */}
      {(notifications.data ?? []).map((n) => (
        <View key={n.id} style={[styles.notice, { backgroundColor: TONE_COLORS[n.tone].bg }]}>
          <View style={[styles.noticeBar, { backgroundColor: TONE_COLORS[n.tone].fg }]} />
          <Icon name={n.icon} size={18} color={TONE_COLORS[n.tone].fg} />
          <View style={{ flex: 1, minWidth: 0, gap: 2 }}>
            <Text style={styles.noticeTitle} numberOfLines={1}>
              {n.title}
            </Text>
            <Text style={styles.noticeDetail} numberOfLines={2}>
              {n.detail}
            </Text>
          </View>
          <Text style={styles.noticeTime}>{n.at}</Text>
        </View>
      ))}

      {/* Current trip + current location */}
      <Card heading="Current trip" icon="route" hint={trip.data?.vehicle_plate ?? undefined}>
        {trip.loading ? (
          <SkeletonRows count={2} />
        ) : trip.error ? (
          <ErrorState message={trip.error.message} onRetry={trip.reload} />
        ) : trip.data ? (
          <>
            <View style={styles.tripHead}>
              <Text style={styles.tripRef}>{trip.data.reference}</Text>
              <StatusPill status={trip.data.status} />
            </View>
            <Text style={styles.tripRoute} numberOfLines={1}>
              {trip.data.origin} → {trip.data.destination}
            </Text>

            <View style={styles.track}>
              <View style={[styles.fill, { width: `${trip.data.progress_pct}%` }]} />
            </View>
            <View style={styles.trackMeta}>
              <Text style={styles.metaSmall}>{trip.data.progress_pct}% complete</Text>
              <Text style={styles.metaSmall}>ETA {fmt.time(trip.data.eta)}</Text>
            </View>

            <View style={styles.locRow}>
              <Icon name="map-pin" size={16} color={Brand.blue} />
              <Text style={styles.locText} numberOfLines={1}>
                {trip.data.current_location}
              </Text>
            </View>

            {/* The hand-off. Only offered while the run is actually on the
                road — `in_transit` is the one status this transition is
                available from, so anything else must not show a button that
                the API would only refuse. */}
            {trip.data.status === 'in_transit' ? (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel="Mark this delivery as delivered"
                onPress={() => setPodOpen(true)}
                style={({ pressed }) => [
                  styles.deliverBtn,
                  pressed && { opacity: 0.85 },
                ]}>
                <Icon name="check" size={16} color={Brand.surface} />
                <Text style={styles.primaryBtnText}>Mark delivered</Text>
              </Pressable>
            ) : null}

            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Record today's trip income and expenses"
              onPress={() => setLogOpen(true)}
              style={({ pressed }) => [styles.primaryBtn, pressed && { backgroundColor: Brand.blueHover }]}>
              <Icon name="clipboard" size={16} color={Brand.surface} />
              <Text style={styles.primaryBtnText}>Record today's trip</Text>
            </Pressable>

            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Open tracking"
              onPress={() => router.push('/tracking')}
              style={({ pressed }) => [styles.linkBtn, pressed && { backgroundColor: Brand.tint }]}>
              <Text style={styles.linkBtnText}>Open live tracking</Text>
              <Icon name="chevron-right" size={16} color={Brand.blue} />
            </Pressable>
          </>
        ) : (
          <EmptyState title="No trip in progress" body="Your next dispatch will show up here." />
        )}
      </Card>

      <TripList
        heading="Confirmed deliveries"
        icon="shipments"
        state={pending}
        emptyBody="Nothing waiting on you right now."
        // No Start while a run is already in transit. The API refuses a second
        // one anyway, and offering a button that can only fail is worse than
        // not offering it — the driver finishes the run they are on first.
        onStart={trip.data ? undefined : startTrip}
        starting={starting}
        error={startError}
      />

      <TripList
        heading="Upcoming trips"
        icon="calendar"
        state={upcoming}
        emptyBody="Your schedule is clear beyond today."
      />

      {trip.data ? (
        <ProofOfDeliverySheet
          open={podOpen}
          onClose={() => setPodOpen(false)}
          onDelivered={trip.reload}
          reference={trip.data.reference}
          destination={trip.data.destination}
        />
      ) : null}

      <DailyLogSheet
        open={logOpen}
        onClose={() => setLogOpen(false)}
        vehicleId={trip.data?.vehicle_id ?? null}
        plate={trip.data?.vehicle_plate ?? null}
        defaultRoute={trip.data?.destination ?? null}
      />
    </Screen>
  );
}

function TripList({
  heading,
  icon,
  state,
  emptyBody,
  onStart,
  starting,
  error,
}: {
  heading: string;
  icon: string;
  state: ReturnType<typeof useApi<Trip[]>>;
  emptyBody: string;
  /** Omitted where starting a run is not on offer — Upcoming, or mid-run. */
  onStart?: (trip: Trip) => void;
  /** The run currently being started, if any. */
  starting?: string | null;
  /** A refused start. Shown against the list it was attempted from. */
  error?: string | null;
}) {
  return (
    <Card heading={heading} icon={icon} hint={state.data ? `${state.data.length}` : undefined} padded={false}>
      {error ? (
        <Text style={styles.listError} accessibilityLiveRegion="polite">
          {error}
        </Text>
      ) : null}
      {state.loading ? (
        <View style={{ padding: Spacing.three }}>
          <SkeletonRows count={2} />
        </View>
      ) : state.error ? (
        <ErrorState message={state.error.message} onRetry={state.reload} />
      ) : (state.data ?? []).length === 0 ? (
        <EmptyState title="Nothing scheduled" body={emptyBody} />
      ) : (
        (state.data ?? []).map((t, i, arr) => (
          <Pressable
            key={t.id}
            accessibilityRole="button"
            accessibilityLabel={`${t.reference}, ${t.origin} to ${t.destination}`}
            style={({ pressed }) => [
              styles.row,
              i < arr.length - 1 && styles.rowDivider,
              pressed && { backgroundColor: Brand.tint },
            ]}>
            <View style={{ flex: 1, minWidth: 0, gap: 3 }}>
              <Text style={styles.rowTitle}>{t.reference}</Text>
              <Text style={styles.rowSub} numberOfLines={1}>
                {t.origin} → {t.destination}
              </Text>
              <Text style={styles.rowSub}>
                {fmt.dateTime(t.scheduled_at)} · {fmt.kg(t.weight_kg)}
              </Text>
            </View>
            <View style={styles.rowRight}>
              <StatusPill status={t.status} />

              {/* Only confirmed work can be started. `scheduled` is booked for
                  later and becomes `assigned` on its own once its time comes;
                  `pending` is a request the office has not confirmed yet, and
                  the API refuses to start one. Neither gets a button here —
                  offering one that can only fail is worse than not offering
                  it. */}
              {onStart && t.status === 'assigned' ? (
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={`Start ${t.reference}, ${t.origin} to ${t.destination}`}
                  disabled={starting !== null}
                  onPress={() => onStart(t)}
                  style={({ pressed }) => [
                    styles.startBtn,
                    (pressed || starting !== null) && { opacity: 0.6 },
                  ]}>
                  <Text style={styles.startBtnText}>
                    {starting === t.id ? 'Starting…' : 'Start'}
                  </Text>
                </Pressable>
              ) : null}
            </View>
          </Pressable>
        ))
      )}
    </Card>
  );
}

const styles = StyleSheet.create({
  iconBtn: {
    width: Hit.min,
    height: Hit.min,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.control,
  },
  notifDot: {
    position: 'absolute',
    top: 10,
    right: 10,
    width: 8,
    height: 8,
    borderRadius: Radius.full,
    backgroundColor: Brand.red,
    borderWidth: 1.5,
    borderColor: Brand.surface,
  },

  intro: { gap: 4, paddingHorizontal: Spacing.half },
  greeting: { fontSize: 24, fontWeight: '700', color: Brand.ink, letterSpacing: -0.3 },
  introDate: { fontSize: 13, fontWeight: '500', color: Brand.inkMuted },
  introSummary: { marginTop: 4, fontSize: 14, lineHeight: 20, color: Brand.ink },

  availRow: { flexDirection: 'row', alignItems: 'center', gap: Spacing.three },
  availDot: { width: 10, height: 10, borderRadius: Radius.full },
  availTitle: { fontSize: 15, fontWeight: '600', color: Brand.ink },
  availSub: { marginTop: 2, fontSize: 12, color: Brand.inkMuted },

  notice: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two + 2,
    overflow: 'hidden',
    borderRadius: Radius.card,
    paddingVertical: Spacing.two + 4,
    paddingRight: Spacing.three,
    paddingLeft: Spacing.three + 4,
  },
  noticeBar: { position: 'absolute', left: 0, top: 0, bottom: 0, width: 4 },
  noticeTitle: { fontSize: 13, fontWeight: '600', color: Brand.ink },
  noticeDetail: { fontSize: 12, color: Brand.inkMuted },
  noticeTime: { fontSize: 11, color: Brand.inkMuted },

  tripHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: Spacing.two },
  tripRef: { fontSize: 18, fontWeight: '700', color: Brand.ink, fontVariant: ['tabular-nums'] },
  tripRoute: { marginTop: 4, fontSize: 14, color: Brand.ink },

  track: {
    height: 8,
    borderRadius: Radius.full,
    backgroundColor: Brand.line,
    overflow: 'hidden',
    marginTop: Spacing.three,
  },
  fill: { height: '100%', borderRadius: Radius.full, backgroundColor: Brand.blue },
  trackMeta: { flexDirection: 'row', justifyContent: 'space-between', marginTop: 6 },
  metaSmall: { fontSize: 12, color: Brand.inkMuted, fontVariant: ['tabular-nums'] },

  locRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: Spacing.three },
  locText: { flex: 1, fontSize: 13, color: Brand.ink },

  primaryBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: Spacing.two,
    minHeight: 48,
    marginTop: Spacing.three,
    borderRadius: Radius.control,
    backgroundColor: Brand.blue,
  },
  rowRight: { alignItems: 'flex-end', gap: 6 },
  startBtn: {
    minHeight: 32,
    justifyContent: 'center',
    paddingHorizontal: Spacing.three,
    borderRadius: Radius.control,
    backgroundColor: Brand.blue,
  },
  startBtnText: { fontSize: 13, fontWeight: '600', color: Brand.surface },
  listError: {
    paddingHorizontal: Spacing.three,
    paddingTop: Spacing.three,
    fontSize: 13,
    fontWeight: '500',
    color: Brand.red,
  },

  deliverBtn: {
    marginTop: Spacing.three,
    minHeight: Hit.min,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: Spacing.two,
    borderRadius: Radius.control,
    backgroundColor: Brand.success,
  },
  primaryBtnText: { fontSize: 15, fontWeight: '600', color: Brand.surface },

  linkBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
    minHeight: 44,
    marginTop: Spacing.two,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.blue,
  },
  linkBtnText: { fontSize: 14, fontWeight: '600', color: Brand.blue },

  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    minHeight: Hit.rowTwoLine,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two + 2,
  },
  rowDivider: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: Brand.line },
  rowTitle: { fontSize: 14, fontWeight: '600', color: Brand.ink, fontVariant: ['tabular-nums'] },
  rowSub: { fontSize: 12, color: Brand.inkMuted },
});

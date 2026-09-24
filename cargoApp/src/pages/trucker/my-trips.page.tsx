import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { ProofOfDeliverySheet } from '@/components/proof-of-delivery-sheet';
import { Screen } from '@/components/screen';
import { Icon } from '@/components/ui/icon';
import {
  Card,
  EmptyState,
  ErrorState,
  SkeletonRows,
  StatusPill,
} from '@/components/ui/primitives';
import { fmt } from '@/constants/format';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { Trip } from '@/models/trip/trip.model';
import { truckerService } from '@/services/trucker/trucker.service';
import { useApi } from '@/hooks/use-api';

type Filter = 'active' | 'history';

/**
 * The partner's own runs: what they took, and what they have finished.
 *
 * The same two-view split the customer's deliveries screen uses, for the same
 * reason — a month of completed runs above the one somebody is actually driving
 * is how a list stops being read.
 *
 * **What is missing here is the pre-trip check, and its absence is the design.**
 * A driver cannot leave the yard until the truck has passed one, because that
 * is the fleet looking over the fleet's own unit. A partner's truck is not the
 * fleet's to clear — there is no checklist to open, no gate to lift, and
 * tapping Start leaves on the run.
 */
export function MyTripsPage() {
  const trips = useApi(truckerService.trips);
  const history = useApi(truckerService.history);

  const [filter, setFilter] = useState<Filter>('active');
  const [starting, setStarting] = useState<string | null>(null);
  const [handing, setHanding] = useState<Trip | null>(null);
  const [photographing, setPhotographing] = useState<Trip | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const rows = (filter === 'active' ? trips.data : history.data) ?? [];
  const state = filter === 'active' ? trips : history;

  const start = async (trip: Trip) => {
    if (starting) return;

    setStarting(trip.id);
    setNotice(null);

    try {
      await truckerService.start(trip.id);
      trips.reload();
    } catch (e) {
      setNotice(e instanceof Error ? e.message : 'That did not go through.');
    } finally {
      setStarting(null);
    }
  };

  return (
    <Screen title="My trips">
      {notice ? (
        <Text style={styles.notice} accessibilityLiveRegion="polite">
          {notice}
        </Text>
      ) : null}

      <View style={styles.filters}>
        {(
          [
            { key: 'active', label: 'On the go', count: trips.data?.length ?? 0 },
            { key: 'history', label: 'Finished', count: history.data?.length ?? 0 },
          ] as { key: Filter; label: string; count: number }[]
        ).map((option) => {
          const picked = filter === option.key;

          return (
            <Pressable
              key={option.key}
              accessibilityRole="tab"
              accessibilityState={{ selected: picked }}
              accessibilityLabel={`${option.label}, ${option.count}`}
              onPress={() => setFilter(option.key)}
              style={[styles.filter, picked && styles.filterPicked]}>
              <Text style={[styles.filterText, picked && styles.filterTextPicked]}>
                {option.label}
              </Text>
              <Text style={[styles.filterCount, picked && styles.filterTextPicked]}>
                {option.count}
              </Text>
            </Pressable>
          );
        })}
      </View>

      <Card padded={false}>
        {state.loading ? (
          <View style={{ padding: Spacing.three }}>
            <SkeletonRows count={3} />
          </View>
        ) : state.error ? (
          <ErrorState message={state.error.message} onRetry={state.reload} />
        ) : rows.length === 0 ? (
          <EmptyState
            title={filter === 'active' ? 'Nothing on the go' : 'Nothing finished yet'}
            body={
              filter === 'active'
                ? 'Take a job from the Jobs tab and it will appear here.'
                : 'Runs move here once you have handed them over.'
            }
          />
        ) : (
          rows.map((trip, index) => (
            <View key={trip.id} style={[styles.row, index < rows.length - 1 && styles.divider]}>
              <View style={styles.rowHead}>
                <Text style={styles.reference}>{trip.reference}</Text>
                <StatusPill status={trip.status} />
              </View>

              <View style={styles.routeRow}>
                <Icon name="map-pin" size={14} color={Brand.blue} />
                <Text style={styles.route} numberOfLines={2}>
                  {trip.origin} → {trip.destination}
                </Text>
              </View>

              <Text style={styles.cargo} numberOfLines={1}>
                {trip.cargo} · {fmt.kg(trip.weight_kg)}
              </Text>

              <View style={styles.metaRow}>
                <Meta label="Scheduled" value={fmt.dateTime(trip.scheduled_at)} />
                <Meta label="Billed" value={fmt.money(trip.price_cents, trip.currency)} />
              </View>

              {/*
                One action per state, and never two.

                A confirmed run can be started; one on the road can be handed
                over; one delivered without a photo can have it sent. Anything
                else gets no button — a disabled control is a question the
                screen is asking and then refusing to answer.
              */}
              {trip.status === 'assigned' || trip.status === 'overdue' ? (
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={`Start ${trip.reference}`}
                  disabled={starting !== null}
                  onPress={() => start(trip)}
                  style={({ pressed }) => [
                    styles.action,
                    pressed && { backgroundColor: Brand.blueHover },
                    starting !== null && { opacity: 0.5 },
                  ]}>
                  <Icon name="map-pin" size={15} color={Brand.surface} />
                  <Text style={styles.actionText}>
                    {starting === trip.id ? 'Starting…' : 'Start this run'}
                  </Text>
                </Pressable>
              ) : trip.status === 'in_transit' ? (
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={`Mark ${trip.reference} delivered`}
                  onPress={() => setHanding(trip)}
                  style={({ pressed }) => [
                    styles.action,
                    { backgroundColor: Brand.success },
                    pressed && { opacity: 0.85 },
                  ]}>
                  <Icon name="check" size={15} color={Brand.surface} />
                  <Text style={styles.actionText}>Mark delivered</Text>
                </Pressable>
              ) : trip.status === 'delivered' && trip.has_pod_photo === false ? (
                // Handed over without a picture — the gate had no signal. The
                // one thing left to do to a delivered run, so it gets a button.
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={`Add delivery photo for ${trip.reference}`}
                  onPress={() => setPhotographing(trip)}
                  style={({ pressed }) => [styles.actionOutline, pressed && { backgroundColor: Brand.tint }]}>
                  <Icon name="camera" size={15} color={Brand.blue} />
                  <Text style={styles.actionOutlineText}>Add delivery photo</Text>
                </Pressable>
              ) : null}
            </View>
          ))
        )}
      </Card>

      {/*
        The same sheet the driver uses, handed the partner's endpoint.

        A driver closes the run they are on, resolved from the token; a partner
        names the run, and the API checks it is theirs. Same photograph, same
        typed name, same single available transition — so it is the same screen
        rather than a second copy of it to keep in step.
      */}
      {handing ? (
        <ProofOfDeliverySheet
          open
          onClose={() => setHanding(null)}
          onDelivered={() => {
            trips.reload();
            history.reload();
            setNotice('Handed over. Your wallet has been updated.');
          }}
          reference={handing.reference}
          destination={handing.destination}
          deliver={(proof) => truckerService.deliver(handing.id, proof)}
        />
      ) : null}

      {photographing ? (
        <ProofOfDeliverySheet
          open
          late
          onClose={() => setPhotographing(null)}
          onDelivered={() => {
            history.reload();
            setNotice('Photo sent.');
          }}
          reference={photographing.reference}
          destination={photographing.destination}
          deliver={(proof) => truckerService.attachProof(photographing.id, proof)}
        />
      ) : null}
    </Screen>
  );
}

function Meta({ label, value }: { label: string; value: string }) {
  return (
    <View style={{ flex: 1, minWidth: 0, gap: 2 }}>
      <Text style={styles.metaLabel}>{label.toUpperCase()}</Text>
      <Text style={styles.metaValue} numberOfLines={1}>
        {value}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  notice: {
    padding: Spacing.two + 2,
    borderRadius: Radius.control,
    backgroundColor: Brand.tint,
    color: Brand.blue,
    fontSize: 13,
    fontWeight: '600',
  },

  filters: { flexDirection: 'row', gap: Spacing.two },
  filter: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    minHeight: Hit.min - 8,
    paddingHorizontal: Spacing.three,
    borderRadius: Radius.full,
    borderWidth: 1,
    borderColor: Brand.line,
    backgroundColor: Brand.surface,
  },
  filterPicked: { backgroundColor: Brand.tint, borderColor: Brand.blue },
  filterText: { fontSize: 13, fontWeight: '500', color: Brand.ink },
  filterTextPicked: { color: Brand.blue, fontWeight: '700' },
  filterCount: { fontSize: 12, color: Brand.inkMuted, fontVariant: ['tabular-nums'] },

  row: { paddingHorizontal: Spacing.three, paddingVertical: Spacing.three, gap: 6 },
  divider: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: Brand.line },
  rowHead: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: Spacing.two,
  },
  reference: { fontSize: 15, fontWeight: '700', color: Brand.ink, fontVariant: ['tabular-nums'] },

  routeRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 6 },
  route: { flex: 1, fontSize: 14, color: Brand.ink, lineHeight: 19 },
  cargo: { fontSize: 12, color: Brand.inkMuted },

  metaRow: { flexDirection: 'row', gap: Spacing.three, marginTop: 4 },
  metaLabel: { fontSize: 10, fontWeight: '600', letterSpacing: 0.6, color: Brand.inkMuted },
  metaValue: { fontSize: 13, color: Brand.ink, fontVariant: ['tabular-nums'] },

  action: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: Spacing.two,
    marginTop: Spacing.two,
    minHeight: Hit.min,
    borderRadius: Radius.control,
    backgroundColor: Brand.blue,
  },
  actionText: { color: Brand.surface, fontSize: 14, fontWeight: '700' },
  // Outlined rather than filled: sending a late photo is a follow-up, not the
  // run's next step, and should not shout like Start or Mark delivered do.
  actionOutline: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: Spacing.two,
    marginTop: Spacing.two,
    minHeight: Hit.min,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.blue,
  },
  actionOutlineText: { color: Brand.blue, fontSize: 14, fontWeight: '700' },
});

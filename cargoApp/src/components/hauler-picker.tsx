import { useCallback, useEffect, useState } from 'react';
import { Pressable, StyleSheet, Text, View, ViewStyle } from 'react-native';

import { Icon } from '@/components/ui/icon';
import { EmptyState, InlineSpinner } from '@/components/ui/primitives';
import { fmt } from '@/constants/format';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { GeoPoint } from '@/models/geo/geo.model';
import { Hauler } from '@/models/portal/hauler.model';
import { portalService } from '@/services/portal/portal.service';

/**
 * Who should carry this load — the fleet, or somebody nearby.
 *
 * The customer's half of the partner feature, and the mirror of the trucker's
 * job board: there, somebody with a truck sees the loads near them; here,
 * somebody with a load sees the trucks near it.
 *
 * ## The fleet is always the first card, and always selectable
 *
 * It has a yard, a roster and units it can send, so it answers for a load
 * across town and for one two provinces away alike. It is also the default —
 * chosen unless the customer actively picks somebody else — because "send it
 * with Cargo Rush" is what this screen did before partners existed and is what
 * most people want.
 *
 * A list with no truckers on it is therefore not an empty state. It is an
 * ordinary Tuesday with nobody free nearby, and the screen says so in a line
 * under the fleet card rather than drawing an error.
 *
 * ## What picking a trucker actually means
 *
 * An **offer**, held for that one person. It is on nobody else's board and is
 * not a job until they accept it. That is said on the card, before the choice,
 * because a customer who picks a name and silently gets somebody else would
 * have been better off never being offered the choice.
 */
export function HaulerPicker({
  selected,
  onChange,
  /** Where the load is going out from. The question is who is near *it*. */
  around = null,
  style,
}: {
  selected: Hauler | null;
  onChange: (hauler: Hauler | null) => void;
  around?: GeoPoint | null;
  style?: ViewStyle;
}) {
  const [haulers, setHaulers] = useState<Hauler[]>([]);
  const [loading, setLoading] = useState(true);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setFailure(null);

    try {
      const list = await portalService.haulers(
        around ? { lat: around.lat, lng: around.lng } : undefined,
      );

      setHaulers(list);

      // Default to the fleet, which is always first. Chosen for the customer
      // rather than left blank: this screen had no such question before
      // partners existed, and somebody who ignores it entirely should get the
      // behaviour they have always had.
      const fleet = list.find((h) => h.kind === 'company') ?? null;

      onChange(
        selected !== null && list.some((h) => h.id === selected.id) ? selected : fleet,
      );
    } catch {
      // A hauler list that fails to load must not block a pickup. The fleet is
      // still the default server-side, so sending nothing is a valid request.
      setHaulers([]);
      setFailure('Could not load the list. Your request will go to the fleet.');
    } finally {
      setLoading(false);
    }
    // `selected` is deliberately not a dependency: re-running on every pick
    // would refetch the list under the person's finger.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [around?.lat, around?.lng]);

  useEffect(() => {
    void load();
  }, [load]);

  const truckers = haulers.filter((h) => h.kind === 'trucker');

  if (loading) {
    return (
      <View style={[styles.loading, style]}>
        <InlineSpinner />
      </View>
    );
  }

  if (failure) {
    return (
      <View style={style}>
        <Text style={styles.failure}>{failure}</Text>
      </View>
    );
  }

  if (haulers.length === 0) {
    return (
      <View style={style}>
        <EmptyState
          title="Nobody to show"
          body="Send the request anyway — the office will pick somebody up for it."
        />
      </View>
    );
  }

  return (
    <View style={[{ gap: Spacing.two }, style]}>
      {haulers.map((hauler) => {
        const picked = selected?.id === hauler.id;
        const fleet = hauler.kind === 'company';

        return (
          <Pressable
            key={hauler.id}
            accessibilityRole="radio"
            accessibilityState={{ selected: picked }}
            accessibilityLabel={`${hauler.name}${
              hauler.distance_km === null ? '' : `, ${hauler.distance_km} kilometres away`
            }`}
            onPress={() => onChange(hauler)}
            style={[styles.card, picked && styles.cardPicked]}>
            <View style={[styles.badge, fleet && styles.badgeFleet]}>
              <Icon
                name={fleet ? 'fleet' : 'profile'}
                size={16}
                color={fleet ? Brand.surface : Brand.blue}
              />
            </View>

            <View style={{ flex: 1, minWidth: 0 }}>
              <View style={styles.nameRow}>
                <Text style={styles.name} numberOfLines={1}>
                  {hauler.name}
                </Text>
                {fleet ? <Text style={styles.tag}>THE FLEET</Text> : null}
              </View>

              <Text style={styles.meta} numberOfLines={1}>
                {fleet
                  ? `${hauler.vehicles_ready ?? 0} trucks ready · up to ${fmt.kg(hauler.capacity_kg)}`
                  : `${hauler.vehicle ?? 'Truck'} · up to ${fmt.kg(hauler.capacity_kg)}`}
              </Text>

              <Text style={styles.meta} numberOfLines={1}>
                {hauler.distance_km !== null ? `${hauler.distance_km} km away` : 'Distance unknown'}
                {!fleet && hauler.trips_completed !== null
                  ? ` · ${hauler.trips_completed} ${
                      hauler.trips_completed === 1 ? 'trip' : 'trips'
                    } done`
                  : ''}
              </Text>
            </View>

            {picked ? <Icon name="check" size={18} color={Brand.blue} /> : null}
          </Pressable>
        );
      })}

      {/*
        Two sentences that stop a surprise later.

        An empty trucker list is an ordinary Tuesday, not a fault — and picking
        somebody is an offer they can leave, not a booking. Both are said here,
        before the choice, rather than discovered afterwards.
      */}
      <Text style={styles.footnote}>
        {truckers.length === 0
          ? 'No independent truckers are near this pickup right now. The fleet will handle it.'
          : selected?.kind === 'trucker'
            ? `${selected.name} will be asked first. If they do not take it, the office picks it up.`
            : 'The office will assign one of its own trucks, or a trucker who takes it.'}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  loading: { paddingVertical: Spacing.four, alignItems: 'center' },
  failure: { fontSize: 12, lineHeight: 17, color: Brand.inkMuted },

  card: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two + 2,
    minHeight: Hit.min + 12,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two + 2,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
    backgroundColor: Brand.surface,
  },
  cardPicked: { borderColor: Brand.blue, backgroundColor: Brand.tint },

  badge: {
    width: 34,
    height: 34,
    borderRadius: Radius.full,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Brand.tint,
  },
  badgeFleet: { backgroundColor: Brand.blue },

  nameRow: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two },
  name: { flexShrink: 1, fontSize: 14, fontWeight: '700', color: Brand.ink },
  tag: { fontSize: 9, fontWeight: '700', letterSpacing: 0.5, color: Brand.blue },
  meta: { marginTop: 1, fontSize: 11, color: Brand.inkMuted },

  footnote: { marginTop: 2, fontSize: 11, lineHeight: 16, color: Brand.inkMuted },
});

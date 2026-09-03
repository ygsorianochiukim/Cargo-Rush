import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { StatusValue } from '@/constants/status';
import { Trip } from '@/models/trip/trip.model';
import { portalService } from '@/services/portal/portal.service';
import { Screen } from '@/components/screen';
import { Icon } from '@/components/ui/icon';
import {
  Card,
  EmptyState,
  ErrorState,
  PrimaryButton,
  SkeletonRows,
  StatusPill,
} from '@/components/ui/primitives';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { fmt } from '@/constants/format';
import { useApi } from '@/hooks/use-api';

/** What each status means to the person who asked for the delivery. */
const MEANING: Partial<Record<StatusValue, string>> = {
  pending: 'Waiting for the office to confirm a driver and a time',
  scheduled: 'Booked for a later day',
  assigned: 'Confirmed — a driver and a unit are on it',
  in_transit: 'On the road now',
  delivered: 'Delivered and signed for',
  overdue: 'Past its estimated arrival',
  cancelled: 'Cancelled',
};

type Filter = 'all' | 'open' | 'delivered';

/**
 * The customer's deliveries.
 *
 * The same trips the office sees on Trip Management, read from the endpoint
 * that scopes them to this account — never a filtered view of the whole board.
 *
 * Each row carries a plain-English line under the status pill. `assigned`
 * means something precise to a dispatcher and nothing at all to a customer,
 * and a status vocabulary shared across two very different audiences (DESIGN.md
 * section 5.3) is worth translating rather than diluting.
 */
export function OrdersPage() {
  const requests = useApi(portalService.requests);
  const [filter, setFilter] = useState<Filter>('all');

  const all = requests.data ?? [];

  const rows = all.filter((trip) =>
    filter === 'all'
      ? true
      : filter === 'delivered'
        ? trip.status === 'delivered'
        : trip.status !== 'delivered' && trip.status !== 'cancelled',
  );

  const counts: Record<Filter, number> = {
    all: all.length,
    open: all.filter((t) => t.status !== 'delivered' && t.status !== 'cancelled').length,
    delivered: all.filter((t) => t.status === 'delivered').length,
  };

  return (
    <Screen title="My deliveries">
      <View style={styles.filters}>
        {(
          [
            { key: 'all', label: 'All' },
            { key: 'open', label: 'In progress' },
            { key: 'delivered', label: 'Delivered' },
          ] as { key: Filter; label: string }[]
        ).map((option) => {
          const picked = filter === option.key;

          return (
            <Pressable
              key={option.key}
              accessibilityRole="tab"
              accessibilityState={{ selected: picked }}
              accessibilityLabel={`${option.label}, ${counts[option.key]}`}
              onPress={() => setFilter(option.key)}
              style={[styles.filter, picked && styles.filterPicked]}>
              <Text style={[styles.filterText, picked && styles.filterTextPicked]}>
                {option.label}
              </Text>
              <Text style={[styles.filterCount, picked && styles.filterTextPicked]}>
                {counts[option.key]}
              </Text>
            </Pressable>
          );
        })}
      </View>

      <Card padded={false}>
        {requests.loading ? (
          <View style={{ padding: Spacing.three }}>
            <SkeletonRows count={4} />
          </View>
        ) : requests.error ? (
          <ErrorState message={requests.error.message} onRetry={requests.reload} />
        ) : rows.length === 0 ? (
          <EmptyState
            title={all.length === 0 ? 'No deliveries yet' : 'Nothing in this view'}
            body={
              all.length === 0
                ? 'Ask for a pickup and it will appear here while the office confirms it.'
                : 'Switch the filter to see the rest.'
            }
          />
        ) : (
          rows.map((trip: Trip, index: number) => (
            <View key={trip.id} style={[styles.row, index < rows.length - 1 && styles.divider]}>
              <View style={styles.rowHead}>
                <Text style={styles.rowRef}>{trip.reference}</Text>
                <StatusPill status={trip.status} />
              </View>

              <View style={styles.routeRow}>
                <Icon name="map-pin" size={14} color={Brand.blue} />
                <Text style={styles.route} numberOfLines={1}>
                  {trip.origin} → {trip.destination}
                </Text>
              </View>

              <Text style={styles.meaning}>{MEANING[trip.status] ?? ''}</Text>

              <View style={styles.metaRow}>
                <Meta label="Cargo" value={trip.cargo} />
                <Meta label="Weight" value={fmt.kg(trip.weight_kg)} />
              </View>
              <View style={styles.metaRow}>
                <Meta
                  label={trip.status === 'delivered' ? 'Delivered' : 'Scheduled'}
                  value={fmt.dateTime(trip.scheduled_at)}
                />
                <Meta
                  label={trip.billed_at ? 'Invoiced' : 'Quoted'}
                  value={fmt.money(trip.price_cents, trip.currency)}
                />
              </View>

              {trip.driver_name ? (
                <Text style={styles.crew}>
                  Driver {trip.driver_name}
                  {trip.vehicle_plate ? ` · ${trip.vehicle_plate}` : ''}
                </Text>
              ) : null}
            </View>
          ))
        )}
      </Card>

      <PrimaryButton label="Request a pickup" icon="plus" onPress={() => router.push('/request')} />
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
  rowRef: { fontSize: 15, fontWeight: '700', color: Brand.ink, fontVariant: ['tabular-nums'] },
  routeRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  route: { flex: 1, fontSize: 14, color: Brand.ink },
  meaning: { fontSize: 12, color: Brand.inkMuted },

  metaRow: { flexDirection: 'row', gap: Spacing.three, marginTop: 4 },
  metaLabel: { fontSize: 10, fontWeight: '600', letterSpacing: 0.6, color: Brand.inkMuted },
  metaValue: { fontSize: 13, color: Brand.ink, fontVariant: ['tabular-nums'] },
  crew: { marginTop: 4, fontSize: 12, color: Brand.inkMuted },
});

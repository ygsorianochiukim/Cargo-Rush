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

/**
 * Two views, and no "all".
 *
 * A delivery that has been handed over and signed for is finished business: it
 * belongs in **History**, and showing it beside the loads somebody is waiting
 * on buries the ones that still need chasing. A month of completed runs at the
 * top of the list is exactly how a customer stops reading the list.
 *
 * So `active` is the screen's own answer to "what is happening to my freight"
 * — everything still in play — and `history` is where the finished ones live.
 * There is nowhere else for them to appear: the home screen shows active runs
 * too, for the same reason.
 */
type Filter = 'active' | 'history';

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
  const [filter, setFilter] = useState<Filter>('active');

  /**
   * Which delivery's checklist is open.
   *
   * One at a time, and closed by default: whether the truck was checked is a
   * line on the row, and which seven things were checked is a second question
   * that would otherwise turn a list of deliveries into a wall of ticks.
   */
  const [expanded, setExpanded] = useState<string | null>(null);

  const all = requests.data ?? [];

  /**
   * Finished business: delivered, or cancelled.
   *
   * Both are done with — one well, one not — and neither is something the
   * customer can act on. They go to History together rather than a cancelled
   * run sitting in the active list forever with nothing to do about it.
   */
  const isHistory = (status: StatusValue): boolean =>
    status === 'delivered' || status === 'cancelled';

  const rows = all.filter((trip) =>
    filter === 'history' ? isHistory(trip.status) : !isHistory(trip.status),
  );

  const counts: Record<Filter, number> = {
    active: all.filter((t) => !isHistory(t.status)).length,
    history: all.filter((t) => isHistory(t.status)).length,
  };

  return (
    <Screen title="My deliveries">
      <View style={styles.filters}>
        {(
          [
            { key: 'active', label: 'In progress' },
            { key: 'history', label: 'History' },
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
            title={
              all.length === 0
                ? 'No deliveries yet'
                : filter === 'history'
                  ? 'Nothing finished yet'
                  : 'Nothing on the move'
            }
            body={
              all.length === 0
                ? 'Ask for a pickup and it will appear here while the office confirms it.'
                : filter === 'history'
                  ? 'Deliveries appear here once they have been handed over.'
                  : 'Everything you have sent has been delivered — they are under History.'
            }
          />
        ) : (
          rows.map((trip: Trip, index: number) => (
            <View key={trip.id} style={[styles.row, index < rows.length - 1 && styles.divider]}>
              <View style={styles.rowHead}>
                <Text style={styles.rowRef}>{trip.reference}</Text>
                <StatusPill status={trip.status} />
              </View>

              {/* Who is holding it. A firm that signed itself up may be using
                  three hauliers at once, and a reference without the name
                  beside it is a number with no office to quote it to. */}
              {trip.carrier ? (
                <View style={styles.carrierRow}>
                  <Icon name="fleet" size={13} color={Brand.inkMuted} />
                  <Text style={styles.carrierName} numberOfLines={1}>
                    {trip.carrier}
                  </Text>
                </View>
              ) : null}

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
                  value={
                    trip.price_cents === null
                      ? 'Office to confirm'
                      : fmt.money(trip.price_cents, trip.currency)
                  }
                />
              </View>

              {trip.driver_name ? (
                <Text style={styles.crew}>
                  Driver {trip.driver_name}
                  {trip.vehicle_plate ? ` · ${trip.vehicle_plate}` : ''}
                </Text>
              ) : null}

              {/*
                Was the truck checked before it left?

                The pre-trip check is the haulier's own safety routine — tyres,
                brakes, lights, documents — and a run cannot start without
                passing one. Showing it here is showing the customer the answer
                to a question they would otherwise have to ring and ask about
                their own load.

                A held unit says so and stops there. Which brake failed is
                between the fleet and its mechanic, and the API does not send it
                to a customer — see `InspectionService::summaryFor()`.
              */}
              {trip.inspection ? (
                <Pressable
                  accessibilityRole="button"
                  accessibilityState={{ expanded: expanded === trip.id }}
                  accessibilityLabel={
                    trip.inspection.passed
                      ? `Pre-trip check passed, ${trip.inspection.passed_items} of ${trip.inspection.total_items} items`
                      : 'Pre-trip check not cleared yet'
                  }
                  onPress={() => setExpanded(expanded === trip.id ? null : trip.id)}
                  style={[
                    styles.checkRow,
                    {
                      backgroundColor: trip.inspection.passed ? Brand.successBg : Brand.tint,
                    },
                  ]}>
                  <Icon
                    name={trip.inspection.passed ? 'check' : 'clipboard'}
                    size={14}
                    color={trip.inspection.passed ? Brand.success : Brand.inkMuted}
                  />
                  <View style={{ flex: 1, minWidth: 0 }}>
                    <Text
                      style={[
                        styles.checkTitle,
                        { color: trip.inspection.passed ? Brand.success : Brand.inkMuted },
                      ]}>
                      {trip.inspection.passed
                        ? `Unit checked · ${trip.inspection.passed_items}/${trip.inspection.total_items} passed`
                        : 'Awaiting pre-trip check'}
                    </Text>
                    <Text style={styles.checkSub}>
                      {trip.inspection.passed
                        ? `${fmt.dateTime(trip.inspection.inspected_at)}${
                            trip.inspection.checked_by ? ' · ' + trip.inspection.checked_by : ''
                          }`
                        : 'The load leaves once the truck has passed its check.'}
                    </Text>
                  </View>
                  {trip.inspection.items.length > 0 ? (
                    <Icon
                      name={expanded === trip.id ? 'chevron-left' : 'chevron-right'}
                      size={14}
                      color={Brand.inkMuted}
                    />
                  ) : null}
                </Pressable>
              ) : null}

              {/* The checklist itself, when they ask for it. Collapsed by
                  default: seven items is the answer to "what was checked",
                  which is a second question. */}
              {expanded === trip.id && trip.inspection?.items.length ? (
                <View style={styles.checkList}>
                  {trip.inspection.items.map((item) => (
                    <View key={item.key} style={styles.checkItem}>
                      <Icon
                        name={item.passed === false ? 'close' : 'check'}
                        size={12}
                        color={item.passed === false ? Brand.red : Brand.success}
                      />
                      <Text style={styles.checkItemLabel}>{item.label}</Text>
                    </View>
                  ))}
                </View>
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
  checkRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
    marginTop: Spacing.two,
    paddingHorizontal: Spacing.two + 2,
    paddingVertical: Spacing.two,
    borderRadius: Radius.control,
    minHeight: Hit.min,
  },
  checkTitle: { fontSize: 12, fontWeight: '700' },
  checkSub: { marginTop: 1, fontSize: 11, color: Brand.inkMuted },

  checkList: {
    marginTop: Spacing.one,
    paddingHorizontal: Spacing.two + 2,
    paddingBottom: Spacing.two,
    gap: 4,
  },
  checkItem: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two },
  checkItemLabel: { fontSize: 12, color: Brand.ink },

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
  carrierRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  carrierName: { flex: 1, minWidth: 0, fontSize: 12, fontWeight: '600', color: Brand.inkMuted },
  routeRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  route: { flex: 1, fontSize: 14, color: Brand.ink },
  meaning: { fontSize: 12, color: Brand.inkMuted },

  metaRow: { flexDirection: 'row', gap: Spacing.three, marginTop: 4 },
  metaLabel: { fontSize: 10, fontWeight: '600', letterSpacing: 0.6, color: Brand.inkMuted },
  metaValue: { fontSize: 13, color: Brand.ink, fontVariant: ['tabular-nums'] },
  crew: { marginTop: 4, fontSize: 12, color: Brand.inkMuted },
});

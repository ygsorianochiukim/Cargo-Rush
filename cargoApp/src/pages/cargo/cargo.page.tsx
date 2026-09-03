import { ReactNode } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { tripService } from '@/services/trip/trip.service';
import { Screen } from '@/components/screen';
import { Icon } from '@/components/ui/icon';
import { Card, EmptyState, ErrorState, SkeletonRows, StatusPill } from '@/components/ui/primitives';
import { Brand, Radius, Spacing } from '@/constants/theme';
import { fmt } from '@/constants/format';
import { useApi } from '@/hooks/use-api';

/**
 * Cargo Details — DESIGN.md section 5.2.
 * Cargo information · location · ETA · dispatch and arrival · trip information ·
 * pickup and delivery information.
 */
export function CargoPage() {
  const { data, loading, error, reload } = useApi(tripService.cargo);

  if (loading) {
    return (
      <Screen title="Cargo">
        <Card>
          <SkeletonRows count={5} />
        </Card>
      </Screen>
    );
  }

  if (error) {
    return (
      <Screen title="Cargo">
        <Card>
          <ErrorState message={error.message} onRetry={reload} />
        </Card>
      </Screen>
    );
  }

  if (!data) {
    return (
      <Screen title="Cargo">
        <Card>
          <EmptyState
            title="No cargo assigned"
            body="Cargo details appear once a trip is dispatched to you."
          />
        </Card>
      </Screen>
    );
  }

  return (
    <Screen title="Cargo" subtitle={data.reference}>
      {/* Cargo information */}
      <Card heading="Cargo information" icon="shipments">
        <View style={styles.headRow}>
          <Text style={styles.desc}>{data.description}</Text>
          <StatusPill status={data.status} />
        </View>

        <View style={styles.grid}>
          <Field label="WEIGHT" value={fmt.kg(data.weight_kg)} />
          <Field label="PIECES" value={String(data.pieces)} />
          <Field label="CUSTOMER" value={data.customer ?? '—'} />
          <Field label="REFERENCE" value={data.reference} />
        </View>

        <View style={styles.handling}>
          <Icon name="incident" size={14} color={Brand.warning} />
          <Text style={styles.handlingText}>{data.handling ?? 'No special handling noted'}</Text>
        </View>
      </Card>

      {/* Pickup and delivery information */}
      <Card heading="Pickup and delivery" icon="map-pin">
        <Leg
          tone={Brand.success}
          label="PICKUP"
          place={data.pickup_place}
          time={fmt.dateTime(data.pickup_at)}
          connector
        />
        <Leg
          tone={Brand.blue}
          label="DROP-OFF"
          place={data.dropoff_place}
          time={data.dropoff_at ? fmt.dateTime(data.dropoff_at) : `ETA ${fmt.dateTime(data.eta)}`}
        />
      </Card>

      {/* Dispatch and arrival */}
      <Card heading="Dispatch and arrival" icon="dispatch">
        <View style={styles.grid}>
          <Field label="DISPATCHED" value={fmt.dateTime(data.dispatched_at)} />
          <Field label="ARRIVED" value={fmt.dateTime(data.arrived_at)} />
          <Field label="ETA" value={fmt.dateTime(data.eta)} />
          <Field
            label="ON TIME"
            value={data.status === 'overdue' ? 'Running late' : 'Yes'}
            tone={data.status === 'overdue' ? Brand.red : Brand.success}
          />
        </View>
      </Card>
    </Screen>
  );
}

function Field({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <View style={styles.cell}>
      <Text style={styles.cellLabel}>{label}</Text>
      <Text style={[styles.cellValue, tone ? { color: tone } : null]} numberOfLines={2}>
        {value}
      </Text>
    </View>
  );
}

function Leg({
  tone,
  label,
  place,
  time,
  connector,
}: {
  tone: string;
  label: string;
  place: string;
  time: string;
  connector?: boolean;
}): ReactNode {
  return (
    <View style={styles.leg}>
      <View style={styles.legRail}>
        <View style={[styles.legDot, { borderColor: tone }]} />
        {connector ? <View style={styles.legLine} /> : null}
      </View>
      <View style={{ flex: 1, minWidth: 0, paddingBottom: connector ? Spacing.four : 0 }}>
        <Text style={styles.cellLabel}>{label}</Text>
        <Text style={styles.legPlace}>{place}</Text>
        <Text style={styles.legTime}>{time}</Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  headRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    justifyContent: 'space-between',
    gap: Spacing.three,
  },
  desc: { flex: 1, fontSize: 15, fontWeight: '600', color: Brand.ink },

  grid: { flexDirection: 'row', flexWrap: 'wrap', rowGap: Spacing.three, marginTop: Spacing.three },
  cell: { width: '50%', paddingRight: Spacing.two, gap: 3 },
  cellLabel: { fontSize: 10, fontWeight: '500', letterSpacing: 0.6, color: Brand.inkMuted },
  cellValue: { fontSize: 14, color: Brand.ink, fontVariant: ['tabular-nums'] },

  handling: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    marginTop: Spacing.three,
    padding: Spacing.two + 2,
    borderRadius: Radius.control,
    backgroundColor: Brand.warningBg,
  },
  handlingText: { flex: 1, fontSize: 12, color: Brand.warning, fontWeight: '500' },

  leg: { flexDirection: 'row', gap: Spacing.three },
  legRail: { width: 14, alignItems: 'center' },
  legDot: { width: 14, height: 14, borderRadius: Radius.full, borderWidth: 3 },
  legLine: { flex: 1, width: 2, backgroundColor: Brand.line, marginVertical: 4 },
  legPlace: { marginTop: 3, fontSize: 14, fontWeight: '600', color: Brand.ink },
  legTime: { marginTop: 2, fontSize: 12, color: Brand.inkMuted, fontVariant: ['tabular-nums'] },
});

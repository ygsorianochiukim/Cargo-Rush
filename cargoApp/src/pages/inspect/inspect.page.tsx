import { useMemo, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { inspectionService } from '@/services/inspection/inspection.service';
import { Screen } from '@/components/screen';
import { Icon } from '@/components/ui/icon';
import {
  Card,
  ErrorState,
  PrimaryButton,
  SkeletonRows,
  StatusPill,
} from '@/components/ui/primitives';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { fmt } from '@/constants/format';
import { useApi } from '@/hooks/use-api';
import { useMe } from '@/hooks/use-me';
import { useCurrentTrip } from '@/hooks/use-current-trip';

type Verdict = 'pass' | 'fail' | null;

/**
 * Inspect hub — DESIGN.md section 5.2. Carries two modules:
 *  - On-Boarding Trips Inspection (pre-trip checklist + AI good-to-go)
 *  - Unit Maintenance and Inspection (assigned jobs)
 *
 * Capture lives here and nowhere else: the back office reads these results but
 * never records them (section 5.4).
 */
export function InspectPage() {
  const me = useMe();
  const trip = useCurrentTrip();
  const checklist = useApi(inspectionService.checklist);

  // Maintenance is booked against a unit, so this waits for one to be known.
  const vehicleId = me.data?.vehicle_id ?? null;
  const jobs = useApi(
    () => (vehicleId ? inspectionService.maintenance(vehicleId) : Promise.resolve([])),
    [vehicleId],
  );

  const [verdicts, setVerdicts] = useState<Record<string, Verdict>>({});
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState<string | null>(null);

  const items = checklist.data ?? [];
  const checked = useMemo(
    () => items.filter((i) => verdicts[i.key] != null).length,
    [items, verdicts],
  );
  const failed = useMemo(
    () => items.filter((i) => verdicts[i.key] === 'fail').length,
    [items, verdicts],
  );
  const complete = items.length > 0 && checked === items.length;
  const goodToGo = complete && failed === 0;

  const set = (key: string, v: Verdict) =>
    setVerdicts((prev) => ({ ...prev, [key]: prev[key] === v ? null : v }));

  /**
   * Submit the check.
   *
   * The `goodToGo` above is a live preview, so the driver can see where they
   * stand mid-checklist. The call that counts is the API's — it will not pass
   * a unit on a failed brake check however this screen feels about it — and it
   * is that answer which gets reported back here.
   */
  const submit = () => {
    if (!vehicleId || submitting) return;

    setSubmitting(true);
    setResult(null);

    const results = Object.fromEntries(
      items
        .filter((item) => verdicts[item.key] != null)
        .map((item) => [item.key, verdicts[item.key] === 'pass']),
    );

    inspectionService
      .submit({
        trip_id: trip.data?.id ?? null,
        vehicle_id: vehicleId,
        driver_id: me.data?.driver_id ?? null,
        results,
      })
      .then((inspection) => {
        setResult(
          inspection.good_to_go
            ? 'Recorded — the unit is cleared to roll.'
            : `Recorded — held on ${inspection.failures.join(', ')}.`,
        );
      })
      .catch((e: Error) => setResult(e.message))
      .finally(() => setSubmitting(false));
  };

  return (
    <Screen title="Inspect" subtitle="Pre-trip check and maintenance">
      {/* AI-assisted good-to-go */}
      <View
        style={[
          styles.verdict,
          {
            backgroundColor: goodToGo
              ? Brand.successBg
              : failed > 0
                ? Brand.redBg
                : Brand.tint,
          },
        ]}>
        <View
          style={[
            styles.verdictIcon,
            { backgroundColor: goodToGo ? Brand.success : failed > 0 ? Brand.red : Brand.blue },
          ]}>
          <Icon name={goodToGo ? 'check' : failed > 0 ? 'incident' : 'clipboard'} size={20} color={Brand.surface} />
        </View>
        <View style={{ flex: 1, minWidth: 0 }}>
          <Text
            style={[
              styles.verdictTitle,
              { color: goodToGo ? Brand.success : failed > 0 ? Brand.red : Brand.blue },
            ]}>
            {goodToGo ? 'Good to go' : failed > 0 ? `${failed} item needs attention` : 'Check in progress'}
          </Text>
          <Text style={styles.verdictSub}>
            AI-assisted monitoring · {checked} of {items.length} checked
          </Text>
        </View>
      </View>

      {/* On-boarding trips inspection */}
      <Card
        heading="Pre-trip inspection"
        icon="clipboard"
        hint={`${checked}/${items.length}`}
        padded={false}>
        {checklist.loading ? (
          <View style={{ padding: Spacing.three }}>
            <SkeletonRows count={5} />
          </View>
        ) : checklist.error ? (
          <ErrorState message={checklist.error.message} onRetry={checklist.reload} />
        ) : (
          items.map((item, i) => {
            const v = verdicts[item.key] ?? null;
            return (
              <View
                key={item.key}
                style={[styles.checkRow, i < items.length - 1 && styles.divider]}>
                <View style={{ flex: 1, minWidth: 0, gap: 2 }}>
                  <Text style={styles.checkLabel}>{item.label}</Text>
                  <Text style={styles.checkHint} numberOfLines={1}>
                    {item.hint}
                  </Text>
                </View>

                <View style={styles.toggle}>
                  <Pressable
                    onPress={() => set(item.key, 'pass')}
                    accessibilityRole="button"
                    accessibilityState={{ selected: v === 'pass' }}
                    accessibilityLabel={`${item.label} pass`}
                    style={[styles.toggleBtn, v === 'pass' && { backgroundColor: Brand.success }]}>
                    <Icon name="check" size={16} color={v === 'pass' ? Brand.surface : Brand.inkMuted} />
                  </Pressable>
                  <Pressable
                    onPress={() => set(item.key, 'fail')}
                    accessibilityRole="button"
                    accessibilityState={{ selected: v === 'fail' }}
                    accessibilityLabel={`${item.label} fail`}
                    style={[styles.toggleBtn, v === 'fail' && { backgroundColor: Brand.red }]}>
                    <Icon name="close" size={16} color={v === 'fail' ? Brand.surface : Brand.inkMuted} />
                  </Pressable>
                </View>
              </View>
            );
          })
        )}
      </Card>

      <View style={styles.actions}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Attach photo evidence"
          style={({ pressed }) => [styles.photoBtn, pressed && { backgroundColor: Brand.tint }]}>
          <Icon name="camera" size={18} color={Brand.blue} />
          <Text style={styles.photoBtnText}>Add photo</Text>
        </Pressable>
        <PrimaryButton
          label={
            submitting ? 'Submitting…' : goodToGo ? 'Submit and start trip' : 'Submit inspection'
          }
          icon="check"
          disabled={submitting || !complete || !vehicleId}
          onPress={submit}
          style={{ flex: 1 }}
        />
      </View>

      {result ? (
        <Text style={styles.result} accessibilityLiveRegion="polite">
          {result}
        </Text>
      ) : null}

      {/* Unit maintenance and inspection */}
      <Card heading="Assigned maintenance" icon="fleet" padded={false}>
        {jobs.loading ? (
          <View style={{ padding: Spacing.three }}>
            <SkeletonRows count={2} />
          </View>
        ) : (
          (jobs.data ?? []).map((j, i, arr) => (
            <View key={j.id} style={[styles.jobRow, i < arr.length - 1 && styles.divider]}>
              <View style={{ flex: 1, minWidth: 0, gap: 3 }}>
                <Text style={styles.jobKind}>{j.kind}</Text>
                <Text style={styles.jobMeta}>
                  {j.vehicle_plate} · due {fmt.date(j.due_at)}
                </Text>
                <Text style={styles.jobMeta}>
                  {fmt.km(Math.max(0, j.next_service_km - j.odometer_km))} until service
                </Text>
              </View>
              <StatusPill status={j.status} />
            </View>
          ))
        )}
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  result: {
    marginTop: Spacing.three,
    fontSize: 13,
    fontWeight: '500',
    color: Brand.ink,
  },
  verdict: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    padding: Spacing.three,
    borderRadius: Radius.card,
  },
  verdictIcon: {
    width: 40,
    height: 40,
    borderRadius: Radius.full,
    alignItems: 'center',
    justifyContent: 'center',
  },
  verdictTitle: { fontSize: 15, fontWeight: '700' },
  verdictSub: { marginTop: 2, fontSize: 12, color: Brand.inkMuted },

  divider: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: Brand.line },

  checkRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    minHeight: Hit.rowTwoLine,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two + 2,
  },
  checkLabel: { fontSize: 14, fontWeight: '600', color: Brand.ink },
  checkHint: { fontSize: 12, color: Brand.inkMuted },

  toggle: { flexDirection: 'row', gap: Spacing.two },
  toggleBtn: {
    width: 44,
    height: 36,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
    alignItems: 'center',
    justifyContent: 'center',
  },

  actions: { flexDirection: 'row', gap: Spacing.three, alignItems: 'stretch' },
  photoBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    minHeight: 48,
    paddingHorizontal: Spacing.three,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.blue,
    backgroundColor: Brand.surface,
  },
  photoBtnText: { fontSize: 14, fontWeight: '600', color: Brand.blue },

  jobRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.three,
  },
  jobKind: { fontSize: 14, fontWeight: '600', color: Brand.ink },
  jobMeta: { fontSize: 12, color: Brand.inkMuted, fontVariant: ['tabular-nums'] },
});

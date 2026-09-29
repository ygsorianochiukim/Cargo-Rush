import { useEffect, useMemo, useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import {
  DispatchAnswer,
  DispatchChecklistAnswers,
  DispatchChecklistSection,
  Trip,
} from '@/models/trip/trip.model';
import { tripService } from '@/services/trip/trip.service';
import { crewService } from '@/services/trucker/crew.service';
import { Icon } from '@/components/ui/icon';
import { ErrorState, PrimaryButton, SkeletonRows } from '@/components/ui/primitives';
import { Brand, Radius, Spacing } from '@/constants/theme';
import { useApi } from '@/hooks/use-api';

/** The three answers, each with the colour it takes when chosen. */
const CHOICES: { value: DispatchAnswer; label: string; tone: string }[] = [
  { value: 'yes', label: 'Yes', tone: Brand.success },
  { value: 'no', label: 'No', tone: Brand.red },
  { value: 'na', label: 'N/A', tone: Brand.inkMuted },
];

/** What the top of the printed sheet says, filled in from the run. */
export interface DispatchSheetHeader {
  company: string | null;
  logoUrl?: string | null;
  reference: string | null;
  date: string | null;
  driver: string | null;
  plate: string | null;
  helper1: string | null;
  helper2: string | null;
  client: string | null;
  route: string | null;
}

/**
 * The Safety, LTO and Warehouse Compliance Checklist, on the phone.
 *
 * The firm's dispatch form, answered at the truck: YES, NO or N/A on every
 * line, and remarks. What is saved here is exactly what the printed dispatch
 * sheet shows ticked — the same sections and lines in the same order — but it
 * is laid out for a phone rather than for A4: each line gets the full width
 * for its words and three big buttons under them, instead of a narrow ruled
 * column that wrapped every line onto four.
 *
 * Not the pre-trip inspection above it. That one is pass/fail and gates
 * Start; this one records the answers and gates nothing — a NO is for the
 * office to read.
 */
export function DispatchChecklistCard({
  tripId,
  crew,
  answered,
  header,
  onSaved,
}: {
  tripId: string;
  crew: boolean;
  /** What was answered before, so coming back shows it rather than a blank sheet. */
  answered: DispatchChecklistAnswers | null | undefined;
  header: DispatchSheetHeader;
  onSaved?: (trip: Trip) => void;
}) {
  const checklist = useApi(
    () => (crew ? crewService.dispatchChecklist() : tripService.dispatchChecklist()),
    [crew],
  );

  const [answers, setAnswers] = useState<Record<string, DispatchAnswer>>({});
  const [remarks, setRemarks] = useState('');
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [savedAt, setSavedAt] = useState<string | null>(null);

  // A different run is a different sheet: start from its own answers.
  useEffect(() => {
    setAnswers(answered?.answers ?? {});
    setRemarks(answered?.remarks ?? '');
    setSavedAt(answered?.checked_at ?? null);
    setMessage(null);
  }, [tripId, answered]);

  const sections: DispatchChecklistSection[] = checklist.data ?? [];
  const keys = useMemo(() => sections.flatMap((s) => s.items.map((i) => i.key)), [sections]);
  const done = keys.filter((k) => answers[k] != null).length;
  const complete = keys.length > 0 && done === keys.length;

  const count = (value: DispatchAnswer) => keys.filter((k) => answers[k] === value).length;
  const yes = count('yes');
  const no = count('no');
  const na = count('na');
  const share = (n: number) => (keys.length === 0 ? 0 : (n / keys.length) * 100);

  const pick = (key: string, value: DispatchAnswer) =>
    setAnswers((prev) => ({ ...prev, [key]: value }));

  /** Mark every line not yet answered YES — the usual case, one tap. */
  const allYes = () =>
    setAnswers((prev) => Object.fromEntries(keys.map((k) => [k, prev[k] ?? 'yes'])));

  const save = () => {
    if (!complete || saving) return;

    setSaving(true);
    setMessage(null);

    const trimmed = remarks.trim() === '' ? null : remarks.trim();

    (crew
      ? crewService.answerDispatchChecklist(tripId, answers, trimmed)
      : tripService.answerDispatchChecklist(tripId, answers, trimmed)
    )
      .then((trip) => {
        setSavedAt(trip.dispatch_checklist?.checked_at ?? new Date().toISOString());
        setMessage('Saved — the dispatch sheet prints with these ticks.');
        onSaved?.(trip);
      })
      .catch((e: Error) => setMessage(e.message))
      .finally(() => setSaving(false));
  };

  const details: [string, string | null][] = [
    ['Request ID', header.reference],
    ['Date', header.date],
    ['Driver', header.driver],
    ['Plate no.', header.plate],
    ['Helper 1', header.helper1],
    ['Helper 2', header.helper2],
    ['Client', header.client],
    ['Route', header.route],
  ];

  return (
    <View style={styles.panel}>
      {/* Heading, and how far through they are. */}
      <View style={styles.head}>
        <View style={styles.headIcon}>
          <Icon name="clipboard" size={20} color={Brand.blue} />
        </View>
        <View style={{ flex: 1, minWidth: 0 }}>
          <Text style={styles.title}>Dispatch checklist</Text>
          <Text style={styles.subtitle}>Safety, LTO and warehouse compliance</Text>
        </View>
        <Text style={styles.count}>
          {done}/{keys.length}
        </Text>
      </View>

      <View style={styles.track} accessibilityRole="progressbar">
        <View style={[styles.fill, { width: `${share(yes)}%`, backgroundColor: Brand.success }]} />
        <View style={[styles.fill, { width: `${share(no)}%`, backgroundColor: Brand.red }]} />
        <View style={[styles.fill, { width: `${share(na)}%`, backgroundColor: Brand.inkMuted }]} />
      </View>
      <View style={styles.legend}>
        <Legend color={Brand.success} label={`${yes} yes`} />
        <Legend color={Brand.red} label={`${no} no`} />
        <Legend color={Brand.inkMuted} label={`${na} n/a`} />
        <Legend color={Brand.line} label={`${keys.length - done} left`} />
      </View>

      {/* The run it is for — the top of the printed sheet. */}
      <View style={styles.details}>
        {details.map(([label, value]) => (
          <View key={label} style={styles.detail}>
            <Text style={styles.detailLabel}>{label}</Text>
            <Text style={styles.detailValue} numberOfLines={2}>
              {value || '—'}
            </Text>
          </View>
        ))}
      </View>

      {checklist.loading ? (
        <SkeletonRows count={6} />
      ) : checklist.error ? (
        <ErrorState message={checklist.error.message} onRetry={checklist.reload} />
      ) : (
        <>
          {!complete ? (
            <Pressable
              onPress={allYes}
              accessibilityRole="button"
              accessibilityLabel="Mark the rest YES"
              style={({ pressed }) => [styles.allYes, pressed && { opacity: 0.7 }]}>
              <Icon name="check" size={16} color={Brand.success} />
              <Text style={styles.allYesText}>Mark the rest Yes</Text>
            </Pressable>
          ) : null}

          {sections.map((section) => {
            const sectionDone = section.items.filter((i) => answers[i.key] != null).length;

            return (
              <View key={section.section} style={styles.section}>
                <View style={styles.sectionHead}>
                  <Text style={styles.sectionTitle}>{section.section}</Text>
                  <Text
                    style={[
                      styles.sectionCount,
                      sectionDone === section.items.length && { color: Brand.success },
                    ]}>
                    {sectionDone}/{section.items.length}
                  </Text>
                </View>

                {section.items.map((item) => {
                  const current = answers[item.key] ?? null;
                  const tone = CHOICES.find((c) => c.value === current)?.tone;

                  return (
                    <View
                      key={item.key}
                      style={[
                        styles.item,
                        current === 'yes' && styles.itemYes,
                        current === 'no' && styles.itemNo,
                      ]}>
                      <View style={styles.itemTop}>
                        <View
                          style={[
                            styles.dot,
                            tone ? { backgroundColor: tone, borderColor: tone } : null,
                          ]}>
                          {current === 'yes' ? (
                            <Icon name="check" size={12} color={Brand.surface} />
                          ) : current === 'no' ? (
                            <Icon name="close" size={12} color={Brand.surface} />
                          ) : null}
                        </View>
                        <Text style={styles.label}>{item.label}</Text>
                      </View>

                      <View style={styles.choices}>
                        {CHOICES.map((choice) => {
                          const on = current === choice.value;

                          return (
                            <Pressable
                              key={choice.value}
                              onPress={() => pick(item.key, choice.value)}
                              accessibilityRole="radio"
                              accessibilityState={{ selected: on }}
                              accessibilityLabel={`${item.label}: ${choice.label}`}
                              style={({ pressed }) => [
                                styles.choice,
                                on
                                  ? { backgroundColor: choice.tone, borderColor: choice.tone }
                                  : { borderColor: Brand.line },
                                pressed && { opacity: 0.75 },
                              ]}>
                              <Text
                                style={[
                                  styles.choiceText,
                                  { color: on ? Brand.surface : choice.tone },
                                ]}>
                                {choice.label}
                              </Text>
                            </Pressable>
                          );
                        })}
                      </View>
                    </View>
                  );
                })}
              </View>
            );
          })}

          <View style={{ gap: 6 }}>
            <Text style={styles.remarksLabel}>Remarks</Text>
            <TextInput
              value={remarks}
              onChangeText={setRemarks}
              placeholder="Anything the office should know"
              placeholderTextColor={Brand.inkMuted}
              multiline
              maxLength={500}
              style={styles.remarks}
            />
          </View>

          <View style={styles.warning}>
            <Icon name="incident" size={14} color={Brand.red} />
            <Text style={styles.warningText}>
              No funds released or units dispatched without complete approvals and compliance.
            </Text>
          </View>

          <View style={{ gap: Spacing.two }}>
            <PrimaryButton
              label={saving ? 'Saving…' : savedAt ? 'Save changes' : 'Save checklist'}
              icon="check"
              disabled={saving || !complete}
              onPress={save}
            />

            {!complete ? (
              <Text style={styles.note}>{keys.length - done} line(s) still to answer.</Text>
            ) : null}
            {message ? (
              <Text style={styles.note} accessibilityLiveRegion="polite">
                {message}
              </Text>
            ) : savedAt ? (
              <Text style={styles.note}>
                Last saved {new Date(savedAt).toLocaleString()}
                {answered?.checked_by ? ` by ${answered.checked_by}` : ''}.
              </Text>
            ) : null}
          </View>
        </>
      )}
    </View>
  );
}

function Legend({ color, label }: { color: string; label: string }) {
  return (
    <View style={styles.legendItem}>
      <View style={[styles.legendDot, { backgroundColor: color }]} />
      <Text style={styles.legendText}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  panel: {
    gap: Spacing.three,
    padding: Spacing.three,
    borderRadius: Radius.panel,
    backgroundColor: Brand.surface,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: Brand.line,
  },
  head: { flexDirection: 'row', alignItems: 'center', gap: Spacing.three },
  headIcon: {
    width: 40,
    height: 40,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.control,
    backgroundColor: Brand.tint,
  },
  title: { fontSize: 16, fontWeight: '700', color: Brand.ink },
  subtitle: { marginTop: 2, fontSize: 12, color: Brand.inkMuted },
  count: { fontSize: 15, fontWeight: '700', color: Brand.ink, fontVariant: ['tabular-nums'] },

  track: {
    flexDirection: 'row',
    height: 8,
    overflow: 'hidden',
    borderRadius: Radius.full,
    backgroundColor: Brand.line,
  },
  fill: { height: '100%' },
  legend: { flexDirection: 'row', flexWrap: 'wrap', gap: Spacing.three, marginTop: -Spacing.two },
  legendItem: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  legendDot: { width: 8, height: 8, borderRadius: Radius.full },
  legendText: { fontSize: 12, color: Brand.inkMuted, fontVariant: ['tabular-nums'] },

  details: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    rowGap: Spacing.two,
    padding: Spacing.three,
    borderRadius: Radius.card,
    backgroundColor: Brand.tint,
  },
  detail: { width: '50%', paddingRight: Spacing.two },
  detailLabel: { fontSize: 11, fontWeight: '600', color: Brand.inkMuted },
  detailValue: { marginTop: 1, fontSize: 13, fontWeight: '600', color: Brand.ink },

  allYes: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    minHeight: 44,
    borderRadius: Radius.control,
    borderWidth: 1.5,
    borderColor: Brand.success,
    backgroundColor: Brand.successBg,
  },
  allYesText: { fontSize: 14, fontWeight: '700', color: Brand.success },

  section: { gap: Spacing.two },
  sectionHead: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two + 2,
    borderRadius: Radius.control,
    backgroundColor: '#1f3b57',
  },
  sectionTitle: {
    flex: 1,
    fontSize: 12,
    fontWeight: '700',
    letterSpacing: 0.4,
    textTransform: 'uppercase',
    color: Brand.surface,
  },
  sectionCount: {
    fontSize: 12,
    fontWeight: '700',
    color: 'rgba(255,255,255,0.75)',
    fontVariant: ['tabular-nums'],
  },

  item: {
    gap: Spacing.three,
    padding: Spacing.three,
    borderRadius: Radius.card,
    borderWidth: 1,
    borderColor: Brand.line,
    backgroundColor: Brand.surface,
  },
  itemYes: { borderColor: Brand.success, backgroundColor: Brand.successBg },
  itemNo: { borderColor: Brand.red, backgroundColor: Brand.redBg },
  itemTop: { flexDirection: 'row', alignItems: 'flex-start', gap: Spacing.two + 2 },
  dot: {
    width: 20,
    height: 20,
    marginTop: 1,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.full,
    borderWidth: 1.5,
    borderColor: Brand.line,
    backgroundColor: Brand.surface,
  },
  label: { flex: 1, fontSize: 14, lineHeight: 20, fontWeight: '500', color: Brand.ink },

  choices: { flexDirection: 'row', gap: Spacing.two },
  choice: {
    flex: 1,
    minHeight: 40,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.control,
    borderWidth: 1.5,
    backgroundColor: Brand.surface,
  },
  choiceText: { fontSize: 14, fontWeight: '700' },

  remarksLabel: { fontSize: 13, fontWeight: '600', color: Brand.ink },
  remarks: {
    minHeight: 80,
    padding: Spacing.three,
    borderRadius: Radius.card,
    borderWidth: 1,
    borderColor: Brand.line,
    fontSize: 14,
    color: Brand.ink,
    backgroundColor: Brand.surface,
    textAlignVertical: 'top',
  },

  warning: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.two,
    padding: Spacing.three,
    borderRadius: Radius.card,
    backgroundColor: Brand.redBg,
  },
  warningText: { flex: 1, fontSize: 12, lineHeight: 17, fontWeight: '600', color: Brand.red },

  note: { fontSize: 12, color: Brand.inkMuted },
});

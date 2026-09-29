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
import { Card, ErrorState, PrimaryButton, SkeletonRows } from '@/components/ui/primitives';
import { Brand, Radius, Spacing } from '@/constants/theme';
import { useApi } from '@/hooks/use-api';

const CHOICES: { value: DispatchAnswer; label: string }[] = [
  { value: 'yes', label: 'YES' },
  { value: 'no', label: 'NO' },
  { value: 'na', label: 'N/A' },
];

/**
 * The Safety, LTO and Warehouse Compliance Checklist, on the phone.
 *
 * The firm's paper dispatch form, answered at the truck: YES, NO or N/A on
 * every line, and remarks. What is saved here is what the office's printed
 * dispatch sheet shows ticked — so the sheet no longer has to be filled in by
 * hand after the fact.
 *
 * Not the pre-trip inspection above it. That one is pass/fail and gates
 * Start; this one records the answers and gates nothing — a NO is for the
 * office to read.
 */
export function DispatchChecklistCard({
  tripId,
  crew,
  answered,
  onSaved,
}: {
  tripId: string;
  crew: boolean;
  /** What was answered before, so coming back shows it rather than a blank sheet. */
  answered: DispatchChecklistAnswers | null | undefined;
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

  return (
    <Card
      heading="Dispatch checklist"
      icon="clipboard"
      hint={`${done}/${keys.length}`}
      padded={false}>
      {checklist.loading ? (
        <View style={{ padding: Spacing.three }}>
          <SkeletonRows count={6} />
        </View>
      ) : checklist.error ? (
        <ErrorState message={checklist.error.message} onRetry={checklist.reload} />
      ) : (
        <>
          <View style={styles.intro}>
            <Text style={styles.introText}>
              Safety, LTO and warehouse compliance. Tick every line — it prints on the dispatch sheet.
            </Text>
            <Pressable
              onPress={allYes}
              accessibilityRole="button"
              accessibilityLabel="Mark the rest YES"
              style={({ pressed }) => [styles.allYes, pressed && { backgroundColor: Brand.tint }]}>
              <Text style={styles.allYesText}>Rest YES</Text>
            </Pressable>
          </View>

          {sections.map((section) => (
            <View key={section.section}>
              <Text style={styles.section}>{section.section.toUpperCase()}</Text>
              {section.items.map((item, i) => {
                const current = answers[item.key] ?? null;

                return (
                  <View
                    key={item.key}
                    style={[styles.row, i < section.items.length - 1 && styles.divider]}>
                    <Text style={styles.label}>{item.label}</Text>
                    <View style={styles.choices}>
                      {CHOICES.map((choice) => {
                        const on = current === choice.value;
                        const tone =
                          choice.value === 'yes'
                            ? Brand.success
                            : choice.value === 'no'
                              ? Brand.red
                              : Brand.inkMuted;

                        return (
                          <Pressable
                            key={choice.value}
                            onPress={() => pick(item.key, choice.value)}
                            accessibilityRole="radio"
                            accessibilityState={{ selected: on }}
                            accessibilityLabel={`${item.label}: ${choice.label}`}
                            style={[
                              styles.choice,
                              on && { backgroundColor: tone, borderColor: tone },
                            ]}>
                            <Text style={[styles.choiceText, on && { color: Brand.surface }]}>
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
          ))}

          <View style={styles.footer}>
            <Text style={styles.remarksLabel}>REMARKS</Text>
            <TextInput
              value={remarks}
              onChangeText={setRemarks}
              placeholder="Anything the office should know"
              placeholderTextColor={Brand.inkMuted}
              multiline
              maxLength={500}
              style={styles.remarks}
            />

            <PrimaryButton
              label={saving ? 'Saving…' : savedAt ? 'Save changes' : 'Save checklist'}
              icon="check"
              disabled={saving || !complete}
              onPress={save}
            />

            {!complete ? (
              <Text style={styles.note}>{keys.length - done} line(s) still to tick.</Text>
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
    </Card>
  );
}

const styles = StyleSheet.create({
  intro: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
    padding: Spacing.three,
  },
  introText: { flex: 1, fontSize: 12, lineHeight: 17, color: Brand.inkMuted },
  allYes: {
    paddingHorizontal: Spacing.three,
    minHeight: 36,
    justifyContent: 'center',
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.blue,
  },
  allYesText: { fontSize: 12, fontWeight: '700', color: Brand.blue },

  section: {
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two,
    fontSize: 11,
    fontWeight: '700',
    letterSpacing: 0.4,
    color: Brand.surface,
    backgroundColor: Brand.blue,
  },
  divider: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: Brand.line },
  row: {
    gap: Spacing.two,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two + 2,
  },
  label: { fontSize: 14, lineHeight: 19, color: Brand.ink },
  choices: { flexDirection: 'row', gap: Spacing.two },
  choice: {
    flex: 1,
    minHeight: 40,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
    backgroundColor: Brand.surface,
  },
  choiceText: { fontSize: 13, fontWeight: '700', color: Brand.inkMuted },

  footer: { gap: Spacing.two, padding: Spacing.three },
  remarksLabel: { fontSize: 11, fontWeight: '600', letterSpacing: 0.4, color: Brand.inkMuted },
  remarks: {
    minHeight: 72,
    padding: Spacing.two + 2,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
    fontSize: 14,
    color: Brand.ink,
    textAlignVertical: 'top',
  },
  note: { fontSize: 12, color: Brand.inkMuted },
});

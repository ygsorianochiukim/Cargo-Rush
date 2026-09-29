import { Pressable, StyleSheet, Text, View } from 'react-native';

import { Icon } from '@/components/ui/icon';
import { ErrorState, SkeletonRows } from '@/components/ui/primitives';
import { Brand, Radius, Spacing } from '@/constants/theme';

export type Verdict = 'pass' | 'fail' | null;

export interface PreTripItem {
  key: string;
  label: string;
  hint: string;
}

/**
 * The before-trip check, as the driver answers it at the truck.
 *
 * One card per item, numbered in the order you walk round the unit, with two
 * big buttons a gloved thumb can hit. The number turns into a tick or a cross
 * once answered and the card takes the colour of the answer, so a glance down
 * the list says what is left and what failed. Tapping the chosen answer again
 * clears it.
 */
export function PreTripChecklist({
  items,
  verdicts,
  onSet,
  loading,
  error,
  onRetry,
  subtitle,
}: {
  items: PreTripItem[];
  verdicts: Record<string, Verdict>;
  onSet: (key: string, verdict: Verdict) => void;
  loading: boolean;
  error: Error | null;
  onRetry: () => void;
  /** The run and truck being checked — "CR-24801 · ABC-1234". */
  subtitle: string | null;
}) {
  const passed = items.filter((i) => verdicts[i.key] === 'pass').length;
  const failed = items.filter((i) => verdicts[i.key] === 'fail').length;
  const left = items.length - passed - failed;
  const share = (n: number) => (items.length === 0 ? 0 : (n / items.length) * 100);

  return (
    <View style={styles.panel}>
      <View style={styles.head}>
        <View style={styles.headIcon}>
          <Icon name="clipboard" size={20} color={Brand.blue} />
        </View>
        <View style={{ flex: 1, minWidth: 0 }}>
          <Text style={styles.title}>Before-trip check</Text>
          {subtitle ? (
            <Text style={styles.subtitle} numberOfLines={1}>
              {subtitle}
            </Text>
          ) : null}
        </View>
        <Text style={styles.count}>
          {passed + failed}/{items.length}
        </Text>
      </View>

      {/* Progress: green for passed, red for failed, the rest still to do. */}
      <View style={styles.track} accessibilityRole="progressbar">
        <View style={[styles.fill, { width: `${share(passed)}%`, backgroundColor: Brand.success }]} />
        <View style={[styles.fill, { width: `${share(failed)}%`, backgroundColor: Brand.red }]} />
      </View>
      <View style={styles.legend}>
        <Legend color={Brand.success} label={`${passed} passed`} />
        <Legend color={Brand.red} label={`${failed} failed`} />
        <Legend color={Brand.line} label={`${left} left`} />
      </View>

      {loading ? (
        <SkeletonRows count={5} />
      ) : error ? (
        <ErrorState message={error.message} onRetry={onRetry} />
      ) : (
        <View style={styles.list}>
          {items.map((item, i) => {
            const v = verdicts[item.key] ?? null;

            return (
              <View
                key={item.key}
                style={[
                  styles.item,
                  v === 'pass' && styles.itemPass,
                  v === 'fail' && styles.itemFail,
                ]}>
                <View style={styles.itemTop}>
                  <View
                    style={[
                      styles.badge,
                      v === 'pass' && { backgroundColor: Brand.success, borderColor: Brand.success },
                      v === 'fail' && { backgroundColor: Brand.red, borderColor: Brand.red },
                    ]}>
                    {v === null ? (
                      <Text style={styles.badgeText}>{i + 1}</Text>
                    ) : (
                      <Icon name={v === 'pass' ? 'check' : 'close'} size={14} color={Brand.surface} />
                    )}
                  </View>
                  <View style={{ flex: 1, minWidth: 0 }}>
                    <Text style={styles.label}>{item.label}</Text>
                    <Text style={styles.hint}>{item.hint}</Text>
                  </View>
                </View>

                <View style={styles.choices}>
                  <Choice
                    label="Pass"
                    icon="check"
                    tone={Brand.success}
                    selected={v === 'pass'}
                    onPress={() => onSet(item.key, 'pass')}
                    accessibilityLabel={`${item.label} pass`}
                  />
                  <Choice
                    label="Fail"
                    icon="close"
                    tone={Brand.red}
                    selected={v === 'fail'}
                    onPress={() => onSet(item.key, 'fail')}
                    accessibilityLabel={`${item.label} fail`}
                  />
                </View>
              </View>
            );
          })}
        </View>
      )}
    </View>
  );
}

function Choice({
  label,
  icon,
  tone,
  selected,
  onPress,
  accessibilityLabel,
}: {
  label: string;
  icon: 'check' | 'close';
  tone: string;
  selected: boolean;
  onPress: () => void;
  accessibilityLabel: string;
}) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityState={{ selected }}
      accessibilityLabel={accessibilityLabel}
      style={({ pressed }) => [
        styles.choice,
        selected ? { backgroundColor: tone, borderColor: tone } : { borderColor: Brand.line },
        pressed && { opacity: 0.75 },
      ]}>
      <Icon name={icon} size={16} color={selected ? Brand.surface : tone} />
      <Text style={[styles.choiceText, { color: selected ? Brand.surface : tone }]}>{label}</Text>
    </Pressable>
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
  subtitle: { marginTop: 2, fontSize: 12, color: Brand.inkMuted, fontVariant: ['tabular-nums'] },
  count: { fontSize: 15, fontWeight: '700', color: Brand.ink, fontVariant: ['tabular-nums'] },

  track: {
    flexDirection: 'row',
    height: 8,
    overflow: 'hidden',
    borderRadius: Radius.full,
    backgroundColor: Brand.line,
  },
  fill: { height: '100%' },
  legend: { flexDirection: 'row', gap: Spacing.three, marginTop: -Spacing.two },
  legendItem: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  legendDot: { width: 8, height: 8, borderRadius: Radius.full },
  legendText: { fontSize: 12, color: Brand.inkMuted, fontVariant: ['tabular-nums'] },

  list: { gap: Spacing.two },
  item: {
    gap: Spacing.three,
    padding: Spacing.three,
    borderRadius: Radius.card,
    borderWidth: 1,
    borderColor: Brand.line,
    backgroundColor: Brand.surface,
  },
  itemPass: { borderColor: Brand.success, backgroundColor: Brand.successBg },
  itemFail: { borderColor: Brand.red, backgroundColor: Brand.redBg },
  itemTop: { flexDirection: 'row', alignItems: 'center', gap: Spacing.three },
  badge: {
    width: 28,
    height: 28,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.full,
    borderWidth: 1.5,
    borderColor: Brand.line,
    backgroundColor: Brand.surface,
  },
  badgeText: { fontSize: 13, fontWeight: '700', color: Brand.inkMuted },
  label: { fontSize: 15, fontWeight: '600', color: Brand.ink },
  hint: { marginTop: 2, fontSize: 12, lineHeight: 16, color: Brand.inkMuted },

  choices: { flexDirection: 'row', gap: Spacing.two },
  choice: {
    flex: 1,
    minHeight: 44,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    borderRadius: Radius.control,
    borderWidth: 1.5,
    backgroundColor: Brand.surface,
  },
  choiceText: { fontSize: 14, fontWeight: '700' },
});

import { ReactNode } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View, ViewStyle } from 'react-native';

import { Icon } from './icon';
import { StatusValue, TONE_COLORS, statusLabel, toneFor } from '@/constants/status';
import { Brand, Hit, Radius, Shadow, Spacing } from '@/constants/theme';

/* -------------------------------------------------------------------------- */
/* Card — DESIGN.md section 8                                                  */
/* -------------------------------------------------------------------------- */

export function Card({
  children,
  heading,
  icon,
  hint,
  padded = true,
  style,
}: {
  children?: ReactNode;
  heading?: string;
  icon?: string;
  hint?: string;
  padded?: boolean;
  style?: ViewStyle;
}) {
  return (
    <View style={[styles.card, style]}>
      {heading ? (
        <View style={styles.cardHeader}>
          {icon ? <Icon name={icon} size={18} color={Brand.blue} /> : null}
          <Text style={styles.cardHeading}>{heading}</Text>
          {hint ? <Text style={styles.cardHint}>{hint}</Text> : null}
        </View>
      ) : null}
      <View style={padded ? styles.cardBody : undefined}>{children}</View>
    </View>
  );
}

/* -------------------------------------------------------------------------- */
/* StatusPill — colour is never the only cue; the label always shows           */
/* -------------------------------------------------------------------------- */

export function StatusPill({ status }: { status: StatusValue }) {
  const tone = TONE_COLORS[toneFor(status)];

  return (
    <View style={[styles.pill, { backgroundColor: tone.bg }]}>
      <View style={[styles.pillDot, { backgroundColor: tone.fg }]} />
      <Text style={[styles.pillText, { color: tone.fg }]}>{statusLabel(status).toUpperCase()}</Text>
    </View>
  );
}

/* -------------------------------------------------------------------------- */
/* KpiTile — sign and arrow carry the direction, not just colour               */
/* -------------------------------------------------------------------------- */

export function KpiTile({
  label,
  value,
  delta,
  higherIsBetter,
  style,
}: {
  label: string;
  value: string;
  delta: number | null;
  higherIsBetter: boolean;
  style?: ViewStyle;
}) {
  const rising = (delta ?? 0) >= 0;
  const good = higherIsBetter ? rising : !rising;

  return (
    <View style={[styles.card, styles.kpi, style]}>
      <Text style={styles.meta}>{label.toUpperCase()}</Text>
      <View style={styles.kpiRow}>
        <Text style={styles.kpiValue}>{value}</Text>
        {delta !== null ? (
          <Text
            style={[styles.kpiDelta, { color: good ? Brand.success : Brand.red }]}
            accessibilityLabel={`${rising ? 'up' : 'down'} ${Math.abs(delta)} percent`}>
            {rising ? '▲' : '▼'} {rising ? '+' : ''}
            {delta}%
          </Text>
        ) : null}
      </View>
    </View>
  );
}

/* -------------------------------------------------------------------------- */
/* Buttons                                                                     */
/* -------------------------------------------------------------------------- */

export function PrimaryButton({
  label,
  icon,
  onPress,
  disabled,
  style,
}: {
  label: string;
  icon?: string;
  onPress?: () => void;
  /** Set while a request is in flight, so a double submit is impossible. */
  disabled?: boolean;
  style?: ViewStyle;
}) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled: !!disabled }}
      style={({ pressed }) => [
        styles.primaryBtn,
        pressed && { backgroundColor: Brand.blueHover },
        disabled && { opacity: 0.5 },
        style,
      ]}>
      {icon ? <Icon name={icon} size={16} color={Brand.surface} /> : null}
      <Text style={styles.primaryBtnText}>{label}</Text>
    </Pressable>
  );
}

/* -------------------------------------------------------------------------- */
/* The four list states                                                        */
/* -------------------------------------------------------------------------- */

export function SkeletonRows({ count = 4 }: { count?: number }) {
  return (
    <View
      accessibilityRole="progressbar"
      accessibilityLabel="Loading"
      style={{ gap: Spacing.three }}>
      {Array.from({ length: count }).map((_, i) => (
        <View key={i} style={styles.skeletonRow}>
          <View style={styles.skeletonAvatar} />
          <View style={{ flex: 1, gap: 6 }}>
            <View style={[styles.skeletonBar, { width: `${70 - (i % 3) * 12}%` }]} />
            <View style={[styles.skeletonBar, { width: '40%', height: 8 }]} />
          </View>
        </View>
      ))}
    </View>
  );
}

export function EmptyState({
  icon = 'shipments',
  title,
  body,
  children,
}: {
  icon?: string;
  title: string;
  body: string;
  children?: ReactNode;
}) {
  return (
    <View style={styles.state}>
      <Icon name={icon} size={32} color={Brand.inkMuted} />
      <Text style={styles.stateTitle}>{title}</Text>
      <Text style={styles.stateBody}>{body}</Text>
      {children}
    </View>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <View style={styles.state} accessibilityRole="alert">
      <Icon name="bell" size={32} color={Brand.red} />
      <Text style={styles.stateTitle}>Could not load</Text>
      <Text style={styles.stateBody}>{message}</Text>
      {onRetry ? (
        <Pressable onPress={onRetry} accessibilityRole="button" style={styles.secondaryBtn}>
          <Text style={styles.secondaryBtnText}>Try again</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

export function InlineSpinner() {
  return <ActivityIndicator color={Brand.blue} />;
}

/* -------------------------------------------------------------------------- */

export const styles = StyleSheet.create({
  card: {
    backgroundColor: Brand.surface,
    borderRadius: Radius.card,
    ...Shadow.card,
  },
  cardHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two + 4,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: Brand.line,
  },
  cardHeading: { fontSize: 16, fontWeight: '600', color: Brand.ink },
  cardHint: { marginLeft: 'auto', fontSize: 12, color: Brand.inkMuted },
  cardBody: { padding: Spacing.three },

  meta: {
    fontSize: 10,
    fontWeight: '500',
    letterSpacing: 0.6,
    color: Brand.inkMuted,
  },

  kpi: { padding: Spacing.three },
  kpiRow: { flexDirection: 'row', alignItems: 'baseline', flexWrap: 'wrap', gap: Spacing.one, marginTop: 6 },
  kpiValue: { fontSize: 24, fontWeight: '600', color: Brand.ink, fontVariant: ['tabular-nums'] },
  kpiDelta: { fontSize: 12, fontWeight: '600', fontVariant: ['tabular-nums'] },

  pill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    alignSelf: 'flex-start',
    borderRadius: Radius.full,
    paddingHorizontal: Spacing.two,
    paddingVertical: 3,
  },
  pillDot: { width: 6, height: 6, borderRadius: Radius.full },
  pillText: { fontSize: 10, fontWeight: '700', letterSpacing: 0.6 },

  primaryBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: Spacing.two,
    minHeight: 48,
    paddingHorizontal: Spacing.four,
    borderRadius: Radius.control,
    backgroundColor: Brand.blue,
  },
  primaryBtnText: { color: Brand.surface, fontSize: 15, fontWeight: '600' },

  secondaryBtn: {
    marginTop: Spacing.two,
    minHeight: Hit.min,
    justifyContent: 'center',
    paddingHorizontal: Spacing.four,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.blue,
  },
  secondaryBtnText: { color: Brand.blue, fontSize: 14, fontWeight: '600' },

  skeletonRow: { flexDirection: 'row', alignItems: 'center', gap: Spacing.three },
  skeletonAvatar: { width: 32, height: 32, borderRadius: Radius.full, backgroundColor: Brand.line },
  skeletonBar: { height: 10, borderRadius: 5, backgroundColor: Brand.line },

  state: {
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    paddingHorizontal: Spacing.four,
    paddingVertical: Spacing.six,
  },
  stateTitle: { marginTop: 4, fontSize: 16, fontWeight: '600', color: Brand.ink },
  stateBody: { fontSize: 14, color: Brand.inkMuted, textAlign: 'center' },
});

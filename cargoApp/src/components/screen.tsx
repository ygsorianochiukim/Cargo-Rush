import { ReactNode } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Wordmark } from './ui/wordmark';
import { Brand, Spacing, TabBarHeight } from '@/constants/theme';

/**
 * The mobile equivalent of the web canvas (DESIGN.md section 6): the screen
 * background is the tint, full bleed and without a radius, and the header sits
 * on the surface colour. Titles are sentence case on mobile, not uppercase.
 */
export function Screen({
  title,
  subtitle,
  brand = false,
  right,
  children,
}: {
  title: string;
  subtitle?: string;
  /** Home screen shows the lockup instead of a text title. */
  brand?: boolean;
  right?: ReactNode;
  children: ReactNode;
}) {
  const insets = useSafeAreaInsets();

  return (
    <View style={styles.root}>
      <View style={[styles.header, { paddingTop: insets.top + Spacing.two }]}>
        <View style={styles.headerRow}>
          {brand ? (
            <Wordmark size={20} />
          ) : (
            <View style={{ flex: 1 }}>
              <Text style={styles.title} numberOfLines={1}>
                {title}
              </Text>
              {subtitle ? (
                <Text style={styles.subtitle} numberOfLines={1}>
                  {subtitle}
                </Text>
              ) : null}
            </View>
          )}
          {right ? <View style={styles.headerRight}>{right}</View> : null}
        </View>
      </View>

      <ScrollView
        style={styles.scroll}
        contentContainerStyle={[
          styles.content,
          { paddingBottom: TabBarHeight + insets.bottom + Spacing.four },
        ]}
        showsVerticalScrollIndicator={false}>
        {children}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: Brand.tint },
  header: {
    backgroundColor: Brand.surface,
    paddingHorizontal: Spacing.four,
    paddingBottom: Spacing.three,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: Brand.line,
  },
  headerRow: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two, minHeight: 40 },
  headerRight: { marginLeft: 'auto', flexDirection: 'row', alignItems: 'center', gap: Spacing.two },
  title: { fontSize: 22, fontWeight: '600', color: Brand.ink },
  subtitle: { marginTop: 2, fontSize: 13, color: Brand.inkMuted },
  scroll: { flex: 1 },
  // Generous gutters and gaps: a driver reads this at arm's length in a cab.
  content: { paddingHorizontal: Spacing.four, paddingTop: Spacing.four, gap: Spacing.four },
});

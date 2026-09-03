import { Image } from 'expo-image';
import { StyleProp, StyleSheet, Text, View, ViewStyle } from 'react-native';

import { Brand, BrandFont, LogoMarkAspect } from '@/constants/theme';

const MARK = require('@/assets/images/brand/logo-mark.png');

export type WordmarkProps = {
  /** `full` is the mark plus the name; `mark` is the arrows on their own. */
  variant?: 'full' | 'mark';
  /** Cap height of the wordmark in points. The mark is sized from it. */
  size?: number;
  tagline?: boolean;
  style?: StyleProp<ViewStyle>;
};

/**
 * The Cargo Rush lockup: the mark as an image, the company name as live text
 * set in Race Sport.
 *
 * Text rather than a baked-in PNG so it stays crisp at any density, respects
 * OS font scaling, and reads correctly to a screen reader. The mark stays an
 * image because it is a drawn shape, not type.
 *
 * Race Sport is loaded in `app/_layout.tsx`; if it failed to load, the stack
 * falls back and the lockup still reads.
 */
export function Wordmark({
  variant = 'full',
  size = 22,
  tagline = true,
  style,
}: WordmarkProps) {
  // The mark reads as the same weight as the type a little above cap height.
  const markHeight = Math.round(size * 1.35);

  const mark = (
    <Image
      source={MARK}
      style={{ height: markHeight, width: markHeight * LogoMarkAspect }}
      contentFit="contain"
      accessibilityRole="image"
      accessibilityLabel={variant === 'mark' ? 'Cargo Rush' : ''}
    />
  );

  if (variant === 'mark') return <View style={style}>{mark}</View>;

  return (
    <View
      style={[styles.row, style]}
      accessible
      accessibilityRole="header"
      accessibilityLabel="Cargo Rush — Fleet Management System">
      {mark}
      <View style={styles.type}>
        <Text style={[styles.name, { fontSize: size, lineHeight: size * 1.1 }]}>
          CARGO<Text style={styles.accent}>RUSH</Text>
        </Text>
        {tagline ? <Text style={styles.tagline}>Fleet Management System</Text> : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  type: { minWidth: 0, justifyContent: 'center' },
  name: {
    // Scoped to the wordmark: this face never touches body copy or a table.
    fontFamily: BrandFont,
    color: Brand.blue,
    letterSpacing: 0.3,
  },
  accent: { color: Brand.red },
  tagline: { marginTop: 3, fontSize: 11, fontWeight: '500', color: Brand.inkMuted },
});

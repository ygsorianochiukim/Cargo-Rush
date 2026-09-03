/**
 * Cargo Rush design tokens for mobile — see /DESIGN.md sections 1, 3, 6 and 10.
 * These mirror the web tokens in CargoUI/src/styles.css exactly. Do not add new
 * hex values here; if a colour is missing, it belongs in DESIGN.md first.
 */

import '@/global.css';

import { Platform } from 'react-native';

/** The brand palette. The only colours the app may use. */
export const Brand = {
  blue: '#15589C',
  blueHover: '#12497F',
  red: '#A11807',
  redHover: '#851406',
  tint: '#DFF0FF',
  surface: '#FFFFFF',
  shell: '#1F1F1F',
  ink: '#1F1F1F',
  inkMuted: '#6B7280',
  line: '#E5E7EB',
  success: '#12805C',
  warning: '#B26A00',
  successBg: '#E6F4EF',
  warningBg: '#FDF3E3',
  redBg: '#FBE9E7',
} as const;

/**
 * v1 ships light only (DESIGN.md section 6), but the dark scaffolding from the
 * template stays in place so it can be turned on later without a refactor.
 */
export const Colors = {
  light: {
    text: Brand.ink,
    background: Brand.tint,
    backgroundElement: Brand.surface,
    backgroundSelected: Brand.tint,
    textSecondary: Brand.inkMuted,
  },
  dark: {
    text: Brand.ink,
    background: Brand.tint,
    backgroundElement: Brand.surface,
    backgroundSelected: Brand.tint,
    textSecondary: Brand.inkMuted,
  },
} as const;

export type ThemeColor = keyof typeof Colors.light & keyof typeof Colors.dark;

/**
 * The brand face — the company name and nothing else.
 *
 * Race Sport is the wordmark, not a UI face: one weight, no italic, drawn for
 * display sizes. It is referenced only by `components/ui/wordmark.tsx`, so it
 * cannot leak into body copy or a list row. Loaded in `app/_layout.tsx`.
 */
export const BrandFont = 'Race Sport';

export const Fonts = Platform.select({
  ios: {
    sans: 'system-ui',
    serif: 'ui-serif',
    rounded: 'ui-rounded',
    mono: 'ui-monospace',
  },
  default: {
    sans: 'normal',
    serif: 'serif',
    rounded: 'normal',
    mono: 'monospace',
  },
  web: {
    sans: 'var(--font-display)',
    serif: 'var(--font-serif)',
    rounded: 'var(--font-rounded)',
    mono: 'var(--font-mono)',
  },
});

/** 4pt scale — every spacing value is a multiple of 4 (DESIGN.md section 3). */
export const Spacing = {
  half: 2,
  one: 4,
  two: 8,
  three: 16,
  four: 24,
  five: 32,
  six: 64,
} as const;

export const Radius = { panel: 16, card: 12, control: 8, full: 9999 } as const;

/** Minimum touch target and list-row heights (DESIGN.md section 6). */
export const Hit = { min: 44, row: 56, rowTwoLine: 64 } as const;

export const Shadow = {
  card: {
    shadowColor: '#000',
    shadowOpacity: 0.08,
    shadowRadius: 3,
    shadowOffset: { width: 0, height: 1 },
    elevation: 2,
  },
  panel: {
    shadowColor: '#000',
    shadowOpacity: 0.1,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 2 },
    elevation: 8,
  },
} as const;

/** Intrinsic size of assets/images/brand/logo-full.png, for aspect-correct sizing. */
export const LogoFullAspect = 220 / 57;
export const LogoMarkAspect = 44 / 28;

export const TabBarHeight = 64;
export const MaxContentWidth = 800;
export const BottomTabInset = Platform.select({ ios: 50, android: 80 }) ?? 0;

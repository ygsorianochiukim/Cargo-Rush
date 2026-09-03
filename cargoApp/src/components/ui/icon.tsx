import { Image } from 'expo-image';
import { StyleProp, ImageStyle } from 'react-native';

import { Brand } from '@/constants/theme';

/**
 * The one `icon name -> asset` map for mobile (DESIGN.md section 7.3).
 * These are template PNGs in assets/images/icons, recoloured with `tintColor`.
 * They are generated from the same path data as the web SVG set, so both
 * clients draw identical shapes.
 */
const ICONS = {
  dashboard: require('@/assets/images/icons/dashboard.png'),
  shipments: require('@/assets/images/icons/shipments.png'),
  fleet: require('@/assets/images/icons/fleet.png'),
  profile: require('@/assets/images/icons/profile.png'),
  'chevron-right': require('@/assets/images/icons/chevron-right.png'),
  bell: require('@/assets/images/icons/bell.png'),
  search: require('@/assets/images/icons/search.png'),
  plus: require('@/assets/images/icons/plus.png'),
  'map-pin': require('@/assets/images/icons/map-pin.png'),
  clock: require('@/assets/images/icons/clock.png'),
  'chevron-left': require('@/assets/images/icons/chevron-left.png'),
  check: require('@/assets/images/icons/check.png'),
  close: require('@/assets/images/icons/close.png'),
  fuel: require('@/assets/images/icons/fuel.png'),
  billing: require('@/assets/images/icons/billing.png'),
  customers: require('@/assets/images/icons/customers.png'),
  incident: require('@/assets/images/icons/incident.png'),
  dispatch: require('@/assets/images/icons/dispatch.png'),
  route: require('@/assets/images/icons/route.png'),
  clipboard: require('@/assets/images/icons/clipboard.png'),
  camera: require('@/assets/images/icons/camera.png'),
  calendar: require('@/assets/images/icons/calendar.png'),
  gauge: require('@/assets/images/icons/gauge.png'),
} as const;

export type IconName = keyof typeof ICONS;

export function isIconName(name: string): name is IconName {
  return name in ICONS;
}

export type IconProps = {
  name: string;
  size?: number;
  color?: string;
  style?: StyleProp<ImageStyle>;
};

export function Icon({ name, size = 20, color = Brand.ink, style }: IconProps) {
  const source = isIconName(name) ? ICONS[name] : ICONS.dashboard;

  return (
    <Image
      source={source}
      tintColor={color}
      style={[{ width: size, height: size }, style]}
      contentFit="contain"
      accessible={false}
    />
  );
}

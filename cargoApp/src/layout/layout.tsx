import { TabList, TabSlot, TabTrigger, TabTriggerSlotProps, Tabs } from 'expo-router/ui';
import { Ref, useEffect, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Icon } from '@/components/ui/icon';
import { Brand, Hit, Radius, TabBarHeight } from '@/constants/theme';
import { identityService } from '@/services/identity/identity.service';
import { useSession } from '@/services/identity/session';

/**
 * The mobile translation of the sidebar (DESIGN.md section 6): a bottom tab bar
 * capped at five tabs, surface background, blue active state.
 *
 * This is the mobile Layout — the one piece of chrome every screen inherits,
 * the same role `layout/layout.ts` plays on the web.
 *
 * **Two tab sets, one bar.** `cargoApp` is one app holding two products: the
 * driver's six modules (section 5.2) and the customer's portal. Which set a
 * person gets follows from their role and nothing else — a customer has no
 * `drivers` row, so every driver screen would 404 for them, and a driver has
 * no `customers` row, so every portal screen would 404 for a driver. Neither
 * set is a subset of the other, which is why this is two lists rather than one
 * list with rows hidden.
 *
 * Built on the headless `expo-router/ui` tabs so the bar matches the design
 * system exactly on every platform rather than inheriting native chrome.
 *
 * **Which tabs exist is fixed; what they say is not.** expo-router resolves
 * `TabTrigger name` against route files at build time, so the sets below cannot
 * come from a network call — but the label, the icon, the order and the badge
 * all can, and they do (DESIGN.md section 7.3). The API is asked on mount and
 * merged in by `key`; until it answers, these values render, so a driver in a
 * dead spot still gets a working tab bar.
 */

type TabDef = {
  /** The route file name expo-router binds to. */
  name: string;
  /** The `key` the API returns for this module. */
  key: string;
  href: string;
  label: string;
  icon: string;
  badge?: number | null;
};

const DRIVER_TABS: TabDef[] = [
  { name: 'index', key: 'dashboard', href: '/', label: 'Dashboard', icon: 'dashboard' },
  { name: 'cargo', key: 'cargo', href: '/cargo', label: 'Cargo', icon: 'shipments' },
  { name: 'tracking', key: 'tracking', href: '/tracking', label: 'Tracking', icon: 'map-pin' },
  { name: 'inspect', key: 'inspect', href: '/inspect', label: 'Inspect', icon: 'clipboard' },
  { name: 'more', key: 'more', href: '/more', label: 'More', icon: 'profile' },
];

const CUSTOMER_TABS: TabDef[] = [
  { name: 'index', key: 'home', href: '/', label: 'Home', icon: 'dashboard' },
  { name: 'request', key: 'request', href: '/request', label: 'Request', icon: 'plus' },
  { name: 'orders', key: 'requests', href: '/orders', label: 'Deliveries', icon: 'shipments' },
  { name: 'invoices', key: 'invoices', href: '/invoices', label: 'Invoices', icon: 'billing' },
  { name: 'more', key: 'more', href: '/more', label: 'More', icon: 'profile' },
];

export function AppLayout() {
  const insets = useSafeAreaInsets();
  const { me } = useSession();

  // Read once at mount time from a session that was already verified before
  // these tabs rendered, so there is no frame where the wrong set is drawn.
  const local = me?.role === 'customer' ? CUSTOMER_TABS : DRIVER_TABS;

  const [tabs, setTabs] = useState<TabDef[]>(local);

  useEffect(() => {
    identityService
      .navigation()
      .then((items) => {
        // Merge by key, and keep the local order: the API sorts for a sidebar,
        // but a tab bar's order is also its muscle memory.
        setTabs(
          local.map((tab) => {
            const remote = items.find((item) => item.key === tab.key);

            return remote
              ? { ...tab, label: remote.label, icon: remote.icon, badge: remote.badge }
              : tab;
          }),
        );
      })
      // A tab bar that fails to render is worse than one with stale labels.
      .catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [me?.role]);

  return (
    <Tabs style={styles.root}>
      <TabSlot style={styles.slot} />
      <TabList asChild>
        {/* Slot children must receive a single flattened style object, not an array. */}
        <View
          style={StyleSheet.flatten([
            styles.bar,
            { height: TabBarHeight + insets.bottom, paddingBottom: insets.bottom },
          ])}>
          {tabs.map((tab) => (
            <TabTrigger key={tab.name} name={tab.name} href={tab.href as never} asChild>
              <TabButton label={tab.label} icon={tab.icon} badge={tab.badge ?? undefined} />
            </TabTrigger>
          ))}
        </View>
      </TabList>
    </Tabs>
  );
}

type TabButtonProps = TabTriggerSlotProps & {
  label: string;
  icon: string;
  badge?: number;
  ref?: Ref<View>;
};

function TabButton({ label, icon, badge, isFocused, ...props }: TabButtonProps) {
  const color = isFocused ? Brand.blue : Brand.inkMuted;

  return (
    <Pressable
      {...props}
      accessibilityRole="tab"
      accessibilityState={{ selected: !!isFocused }}
      accessibilityLabel={badge ? `${label}, ${badge} pending` : label}
      style={({ pressed }) => StyleSheet.flatten([styles.tab, pressed && styles.pressed])}>
      <View style={[styles.iconWrap, isFocused && styles.iconWrapActive]}>
        <Icon name={icon} size={22} color={color} />
        {badge ? (
          <View style={styles.badge}>
            <Text style={styles.badgeText}>{badge > 9 ? '9+' : badge}</Text>
          </View>
        ) : null}
      </View>
      <Text style={[styles.label, { color }, isFocused && styles.labelActive]} numberOfLines={1}>
        {label}
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1 },
  slot: { flex: 1, minHeight: 0 },
  bar: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    backgroundColor: Brand.surface,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Brand.line,
    paddingTop: 6,
  },
  tab: {
    flex: 1,
    minWidth: 0,
    alignItems: 'center',
    justifyContent: 'flex-start',
    gap: 2,
    minHeight: Hit.min,
  },
  pressed: { opacity: 0.6 },
  iconWrap: {
    minWidth: 44,
    height: 26,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.full,
  },
  iconWrapActive: { backgroundColor: Brand.tint },
  label: { fontSize: 11, fontWeight: '500' },
  labelActive: { fontWeight: '700' },
  badge: {
    position: 'absolute',
    top: -2,
    right: 6,
    minWidth: 15,
    height: 15,
    paddingHorizontal: 3,
    borderRadius: Radius.full,
    backgroundColor: Brand.red,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1.5,
    borderColor: Brand.surface,
  },
  badgeText: { color: Brand.surface, fontSize: 9, fontWeight: '700' },
});

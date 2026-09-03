import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { deliveryService } from '@/services/delivery/delivery.service';
import { useSession } from '@/services/identity/session';
import { Screen } from '@/components/screen';
import { Icon } from '@/components/ui/icon';
import { Card, EmptyState, SkeletonRows, StatusPill } from '@/components/ui/primitives';
import { Sheet } from '@/components/ui/sheet';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { fmt } from '@/constants/format';
import { useApi } from '@/hooks/use-api';
import { useMe } from '@/hooks/use-me';

/**
 * More hub — DESIGN.md section 5.2. Carries the Delivery Logs module
 * (trip history and proof of delivery) plus the driver's own profile.
 *
 * The one tab both products share, so it is the one screen that has to know
 * who is holding the phone. A customer has no licence and no delivery logs of
 * their own — those endpoints are scoped to a `drivers` row they do not have —
 * so for them this is the profile and the way out, and nothing else. Their
 * deliveries live on their own tab, where they can be shown properly rather
 * than as a driver's history with the wrong labels.
 */
export function MorePage() {
  // Straight from the session rather than a fresh fetch: it was already
  // verified on launch, and a second call would show a skeleton for data the
  // app is holding.
  const { signOut } = useSession();
  const me = useMe();

  const driving = me.data?.role !== 'customer';

  const [signOutOpen, setSignOutOpen] = useState(false);

  // Scoped to whoever is signed in, so this waits for the driver record
  // rather than asking the API for everybody's history.
  const logs = useApi(
    () => (me.data?.driver_id ? deliveryService.history(me.data.driver_id) : Promise.resolve([])),
    [me.data?.driver_id],
  );

  const initials = (me.data?.name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase() ?? '')
    .join('');

  const delivered = (logs.data ?? []).filter((l) => l.status === 'delivered').length;

  return (
    <Screen title="More">
      {/* Profile */}
      <Card>
        {me.loading ? (
          <SkeletonRows count={1} />
        ) : (
          <>
            <View style={styles.identity}>
              <View style={styles.avatar}>
                <Text style={styles.avatarText}>{initials}</Text>
              </View>
              <View style={{ flex: 1, minWidth: 0, gap: 2 }}>
                <Text style={styles.name} numberOfLines={1}>
                  {me.data?.name}
                </Text>
                <Text style={styles.role}>{me.data?.role_label?.toUpperCase()}</Text>
              </View>
            </View>

            {driving ? (
              <View style={styles.licence}>
                <View style={{ flex: 1, gap: 2 }}>
                  <Text style={styles.metaLabel}>LICENCE NO.</Text>
                  <Text style={styles.metaValue}>{me.data?.licence_no}</Text>
                </View>
                <View style={{ flex: 1, gap: 2 }}>
                  <Text style={styles.metaLabel}>VALID TO</Text>
                  <Text style={styles.metaValue}>{fmt.date(me.data?.licence_expiry)}</Text>
                </View>
              </View>
            ) : (
              <View style={styles.licence}>
                <View style={{ flex: 1, gap: 2 }}>
                  <Text style={styles.metaLabel}>ACCOUNT</Text>
                  <Text style={styles.metaValue}>{me.data?.customer_name ?? '—'}</Text>
                </View>
                <View style={{ flex: 1, gap: 2 }}>
                  <Text style={styles.metaLabel}>EMAIL</Text>
                  <Text style={styles.metaValue} numberOfLines={1}>
                    {me.data?.email}
                  </Text>
                </View>
              </View>
            )}
          </>
        )}
      </Card>

      {/* Delivery logs — trip history and proof of delivery. The driver's own,
          so a customer gets no card at all rather than an empty one that reads
          as "you have no deliveries" when they may well have several. */}
      {driving ? (
      <Card
        heading="Delivery logs"
        icon="clipboard"
        hint={`${delivered} delivered`}
        padded={false}>
        {logs.loading ? (
          <View style={{ padding: Spacing.three }}>
            <SkeletonRows count={4} />
          </View>
        ) : (logs.data ?? []).length === 0 ? (
          <EmptyState
            title="No trip history yet"
            body="Completed deliveries and their proof of delivery appear here."
          />
        ) : (
          (logs.data ?? []).map((l, i, arr) => (
            <Pressable
              key={l.id}
              accessibilityRole="button"
              accessibilityLabel={`${l.reference} for ${l.customer}`}
              style={({ pressed }) => [
                styles.row,
                i < arr.length - 1 && styles.divider,
                pressed && { backgroundColor: Brand.tint },
              ]}>
              <View style={{ flex: 1, minWidth: 0, gap: 3 }}>
                <Text style={styles.rowTitle}>{l.reference}</Text>
                <Text style={styles.rowSub} numberOfLines={1}>
                  {l.customer} · {l.destination}
                </Text>
                <View style={styles.podRow}>
                  <Icon
                    name={l.pod_ref ? 'check' : 'close'}
                    size={12}
                    color={l.pod_ref ? Brand.success : Brand.inkMuted}
                  />
                  <Text style={styles.rowSub}>
                    {l.pod_ref ? `${l.pod_ref} · ${fmt.date(l.delivered_at)}` : 'No proof of delivery'}
                  </Text>
                </View>
              </View>
              <StatusPill status={l.status} />
            </Pressable>
          ))
        )}
      </Card>
      ) : null}

      <Pressable
        accessibilityRole="button"
        accessibilityLabel="Sign out"
        onPress={() => setSignOutOpen(true)}
        style={({ pressed }) => [styles.signOut, pressed && { backgroundColor: Brand.redBg }]}>
        <Text style={styles.signOutText}>Sign out</Text>
      </Pressable>

      <Text style={styles.version}>
        Cargo Rush · {driving ? 'Driver' : 'Customer'} v1.0.0
      </Text>

      {/* Destructive actions confirm first (DESIGN.md section 8). */}
      <Sheet
        open={signOutOpen}
        onClose={() => setSignOutOpen(false)}
        title="Sign out?"
        subtitle="You will need your email and password to sign back in."
        icon="incident"
        danger
        footer={
          <>
            <Pressable
              accessibilityRole="button"
              onPress={() => {
                setSignOutOpen(false);
                // Drops this device's token and clears the keychain. The gate
                // in `_layout` swaps back to sign-in on its own.
                void signOut();
              }}
              style={styles.confirmDanger}>
              <Text style={styles.confirmDangerText}>Sign out</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              onPress={() => setSignOutOpen(false)}
              style={styles.confirmCancel}>
              <Text style={styles.confirmCancelText}>Stay signed in</Text>
            </Pressable>
          </>
        }
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  identity: { flexDirection: 'row', alignItems: 'center', gap: Spacing.three },
  avatar: {
    width: 56,
    height: 56,
    borderRadius: Radius.full,
    backgroundColor: Brand.blue,
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarText: { color: Brand.surface, fontSize: 18, fontWeight: '700' },
  name: { fontSize: 18, fontWeight: '600', color: Brand.ink },
  role: { fontSize: 10, fontWeight: '500', letterSpacing: 0.6, color: Brand.inkMuted },

  licence: {
    flexDirection: 'row',
    gap: Spacing.three,
    marginTop: Spacing.three,
    paddingTop: Spacing.three,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Brand.line,
  },
  metaLabel: { fontSize: 10, fontWeight: '500', letterSpacing: 0.6, color: Brand.inkMuted },
  metaValue: { fontSize: 14, color: Brand.ink, fontVariant: ['tabular-nums'] },

  divider: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: Brand.line },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    minHeight: Hit.rowTwoLine,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two + 2,
  },
  rowTitle: { fontSize: 14, fontWeight: '600', color: Brand.ink, fontVariant: ['tabular-nums'] },
  rowSub: { fontSize: 12, color: Brand.inkMuted },
  podRow: { flexDirection: 'row', alignItems: 'center', gap: 5 },

  signOut: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.red,
    backgroundColor: Brand.surface,
  },
  signOutText: { fontSize: 15, fontWeight: '600', color: Brand.red },

  version: { textAlign: 'center', fontSize: 11, color: Brand.inkMuted },

  confirmDanger: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.control,
    backgroundColor: Brand.red,
  },
  confirmDangerText: { fontSize: 15, fontWeight: '600', color: Brand.surface },
  confirmCancel: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.control,
  },
  confirmCancelText: { fontSize: 15, fontWeight: '600', color: Brand.ink },
});

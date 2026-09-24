import * as Location from 'expo-location';
import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, Switch, Text, View } from 'react-native';

import { Screen } from '@/components/screen';
import { Icon } from '@/components/ui/icon';
import { Card, ErrorState, SkeletonRows, StatusPill } from '@/components/ui/primitives';
import { fmt } from '@/constants/format';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { truckerService } from '@/services/trucker/trucker.service';
import { useApi } from '@/hooks/use-api';

/**
 * The partner's home screen — the third of the three this app opens on.
 *
 * What a driver's dashboard shows is the run they were given. What this shows
 * is the two things an owner-operator actually decides: **whether they are
 * working right now**, and **what the work has been worth**. Everything else is
 * one tap away.
 *
 * ## The switch is the screen
 *
 * It is the only control here and it sits at the top, because it is the one a
 * partner touches twice a day. It goes out with the handset's position
 * attached, because going online means "I am here, and available" — a partner
 * who went online yesterday two provinces away should not be at the top of
 * today's board, and a position sent only when the app feels like it would let
 * that happen.
 *
 * It is hidden entirely for somebody the fleet has not approved. A switch that
 * the server is going to refuse is worse than no switch: it looks like the app
 * is broken rather than like there is a queue.
 */
export function TruckerHomePage() {
  const profile = useApi(truckerService.me);
  const wallet = useApi(() => truckerService.wallet());
  const current = useApi(truckerService.current);

  const [toggling, setToggling] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  const me = profile.data;
  const balance = wallet.data?.balance_cents ?? 0;
  const owes = (wallet.data?.standing ?? 'settled') === 'owed_to_company';

  const flip = async (next: boolean) => {
    if (toggling) return;

    setToggling(true);
    setNotice(null);

    try {
      /**
       * Attach a fix if there is one to attach. Never block on it: a partner
       * who declined the location prompt, or is in a shed with no sky, must
       * still be able to say they are working — they simply do not get sorted
       * by distance, and the board says so rather than refusing to load.
       */
      let position: { lat: number; lng: number } | undefined;

      const permission = await Location.getForegroundPermissionsAsync();

      if (permission.granted) {
        const fix = await Location.getLastKnownPositionAsync();

        if (fix) position = { lat: fix.coords.latitude, lng: fix.coords.longitude };
      }

      await truckerService.setOnline(next, position);
      profile.reload();
    } catch (e) {
      setNotice(e instanceof Error ? e.message : 'Could not change that just now.');
    } finally {
      setToggling(false);
    }
  };

  return (
    <Screen title="Dashboard" brand>
      {notice ? (
        <Text style={styles.notice} accessibilityLiveRegion="polite">
          {notice}
        </Text>
      ) : null}

      {profile.loading ? (
        <Card>
          <SkeletonRows count={3} />
        </Card>
      ) : profile.error ? (
        <ErrorState message={profile.error.message} onRetry={profile.reload} />
      ) : !me ? null : (
        <>
          <Card>
            <View style={styles.identity}>
              <View style={{ flex: 1, minWidth: 0 }}>
                <Text style={styles.name} numberOfLines={1}>
                  {me.name}
                </Text>
                <Text style={styles.sub} numberOfLines={1}>
                  {me.vehicles?.[0]
                    ? `${me.vehicles[0].plate} · ${fmt.kg(me.vehicles[0].capacity_kg)}`
                    : 'No truck on file yet'}
                </Text>
              </View>
              <StatusPill status={me.status} />
            </View>

            {/*
              The switch, only for somebody who has been approved. For anybody
              else this is a queue to explain, not a control to offer.
            */}
            {me.status === 'active' ? (
              <View style={styles.switchRow}>
                <View style={{ flex: 1, minWidth: 0 }}>
                  <Text style={styles.switchLabel}>
                    {me.is_online ? 'You are online' : 'You are offline'}
                  </Text>
                  <Text style={styles.switchHint}>
                    {me.is_online
                      ? 'Customers near you can pick you for a load.'
                      : 'Go online so customers near you can find you.'}
                  </Text>
                </View>
                <Switch
                  value={me.is_online}
                  onValueChange={flip}
                  disabled={toggling}
                  accessibilityLabel="Available for work"
                  trackColor={{ true: Brand.blue, false: Brand.line }}
                />
              </View>
            ) : (
              <View style={styles.waiting}>
                <Icon
                  name={me.status === 'pending' ? 'clipboard' : 'incident'}
                  size={16}
                  color={me.status === 'pending' ? Brand.blue : Brand.red}
                />
                <Text style={styles.waitingText}>
                  {me.status === 'pending'
                    ? 'The fleet is reviewing your licence and truck. You will be notified when you are approved.'
                    : 'Your account is on hold. Give the office a ring — your wallet is untouched.'}
                </Text>
              </View>
            )}
          </Card>

          {/*
            What the work has been worth. Tapping it opens the statement — the
            balance alone invites the question this card cannot answer.
          */}
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Open wallet"
            onPress={() => router.push('/wallet')}>
            <Card>
              <View style={styles.walletRow}>
                <View style={{ flex: 1, minWidth: 0 }}>
                  <Text style={styles.walletLabel}>
                    {owes ? 'YOU OWE THE FLEET' : 'THE FLEET OWES YOU'}
                  </Text>
                  <Text
                    style={[styles.walletValue, { color: owes ? Brand.red : Brand.success }]}>
                    {fmt.money(Math.abs(balance))}
                  </Text>
                  <Text style={styles.walletHint}>
                    {me.trips_completed} {me.trips_completed === 1 ? 'trip' : 'trips'} completed ·{' '}
                    {(me.commission_bp / 100).toFixed(me.commission_bp % 100 === 0 ? 0 : 1)}% fee
                  </Text>
                </View>
                <Icon name="chevron-right" size={18} color={Brand.inkMuted} />
              </View>
            </Card>
          </Pressable>

          {/* The run they are on, when there is one. */}
          {current.data ? (
            <Card heading="On the road now" icon="map-pin">
              <Text style={styles.currentRef}>{current.data.reference}</Text>
              <Text style={styles.currentRoute}>
                {current.data.origin} → {current.data.destination}
              </Text>
              <Pressable
                accessibilityRole="button"
                onPress={() => router.push('/my-trips')}
                style={({ pressed }) => [styles.cta, pressed && { opacity: 0.85 }]}>
                <Text style={styles.ctaText}>Open it</Text>
              </Pressable>
            </Card>
          ) : me.can_take_work ? (
            <Pressable
              accessibilityRole="button"
              onPress={() => router.push('/jobs')}
              style={({ pressed }) => [styles.board, pressed && { opacity: 0.9 }]}>
              <Icon name="shipments" size={18} color={Brand.surface} />
              <Text style={styles.boardText}>See jobs offered to you</Text>
            </Pressable>
          ) : null}
        </>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  notice: {
    padding: Spacing.two + 2,
    borderRadius: Radius.control,
    backgroundColor: Brand.redBg,
    color: Brand.red,
    fontSize: 13,
    fontWeight: '600',
  },

  identity: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two },
  name: { fontSize: 18, fontWeight: '700', color: Brand.ink },
  sub: { marginTop: 2, fontSize: 12, color: Brand.inkMuted },

  switchRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    marginTop: Spacing.three,
    paddingTop: Spacing.three,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Brand.line,
  },
  switchLabel: { fontSize: 14, fontWeight: '700', color: Brand.ink },
  switchHint: { marginTop: 2, fontSize: 12, color: Brand.inkMuted },

  waiting: {
    flexDirection: 'row',
    gap: Spacing.two,
    marginTop: Spacing.three,
    padding: Spacing.two + 2,
    borderRadius: Radius.control,
    backgroundColor: Brand.tint,
  },
  waitingText: { flex: 1, fontSize: 12, lineHeight: 17, color: Brand.inkMuted },

  walletRow: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two },
  walletLabel: { fontSize: 9, fontWeight: '700', letterSpacing: 0.6, color: Brand.inkMuted },
  walletValue: { marginTop: 2, fontSize: 26, fontWeight: '700', fontVariant: ['tabular-nums'] },
  walletHint: { marginTop: 2, fontSize: 12, color: Brand.inkMuted },

  currentRef: { fontSize: 16, fontWeight: '700', color: Brand.ink, fontVariant: ['tabular-nums'] },
  currentRoute: { marginTop: 2, fontSize: 14, color: Brand.ink },
  cta: {
    marginTop: Spacing.three,
    minHeight: Hit.min,
    borderRadius: Radius.control,
    backgroundColor: Brand.blue,
    alignItems: 'center',
    justifyContent: 'center',
  },
  ctaText: { color: Brand.surface, fontSize: 14, fontWeight: '700' },

  board: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: Spacing.two,
    minHeight: 52,
    borderRadius: Radius.control,
    backgroundColor: Brand.blue,
  },
  boardText: { color: Brand.surface, fontSize: 15, fontWeight: '700' },
});

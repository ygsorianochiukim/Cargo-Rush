import { router } from 'expo-router';
import { StyleSheet, Text, View } from 'react-native';

import { Trip } from '@/models/trip/trip.model';
import { notificationService } from '@/services/notification/notification.service';
import { portalService } from '@/services/portal/portal.service';
import { Screen } from '@/components/screen';
import { Icon } from '@/components/ui/icon';
import {
  Card,
  EmptyState,
  ErrorState,
  PrimaryButton,
  SkeletonRows,
  StatusPill,
} from '@/components/ui/primitives';
import { TONE_COLORS } from '@/constants/status';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { fmt } from '@/constants/format';
import { useApi } from '@/hooks/use-api';
import { useMe } from '@/hooks/use-me';

/**
 * The customer's home screen — what `cargoApp` opens on when the account
 * signing in is a customer rather than a driver.
 *
 * It answers the two questions somebody who has asked for a delivery actually
 * has, in that order: *is anybody dealing with my request*, and *what do I
 * owe*. Everything else is one tap away.
 *
 * Note what it does not show: no fleet, no other firm's work, no availability
 * switch. This is the same app the driver holds and a deliberately different
 * product inside it (DESIGN.md section 5.2 draws the same line between the two
 * clients), which is why it is its own page rather than the driver dashboard
 * with rows hidden.
 */
export function CustomerHomePage() {
  const me = useMe();
  const summary = useApi(portalService.summary);
  const requests = useApi(portalService.requests);
  const notifications = useApi(() => notificationService.list(5));

  const greeting = () => {
    const hour = new Date().getHours();
    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';
    return 'Good evening';
  };

  const waiting = summary.data?.awaiting_confirmation ?? 0;
  const moving = (summary.data?.scheduled ?? 0) + (summary.data?.in_transit ?? 0);

  const headline =
    summary.data === null
      ? 'Loading your deliveries…'
      : waiting > 0
        ? `${waiting} request${waiting === 1 ? '' : 's'} waiting on confirmation.`
        : moving > 0
          ? `${moving} deliver${moving === 1 ? 'y' : 'ies'} booked and on the way.`
          : 'Nothing booked right now — ask for a pickup below.';

  /**
   * The three most recent loads still in play.
   *
   * Delivered runs are deliberately not here. They are finished business and
   * they live in History on the Deliveries screen — a home screen led by three
   * completed hauls answers "where is my stuff" with "it arrived last week",
   * which is the one thing the reader already knows. Cancelled goes with them
   * for the same reason.
   *
   * Three, so this stays a summary rather than becoming the list screen.
   */
  const recent = (requests.data ?? [])
    .filter((trip) => trip.status !== 'delivered' && trip.status !== 'cancelled')
    .slice(0, 3);

  return (
    <Screen title="Cargo Rush" brand>
      <View style={styles.intro}>
        <Text style={styles.greeting}>
          {greeting()}
          {me.data ? `, ${me.data.name.split(' ')[0]}` : ''}
        </Text>
        <Text style={styles.introSub}>{me.data?.customer_name ?? ''}</Text>
        <Text style={styles.introSummary}>{headline}</Text>
      </View>

      <PrimaryButton label="Request a pickup" icon="plus" onPress={() => router.push('/request')} />

      {/* Anything the office raised against this account. */}
      {(notifications.data ?? []).map((notification) => (
        <View
          key={notification.id}
          style={[styles.notice, { backgroundColor: TONE_COLORS[notification.tone].bg }]}>
          <View
            style={[styles.noticeBar, { backgroundColor: TONE_COLORS[notification.tone].fg }]}
          />
          <Icon name={notification.icon} size={18} color={TONE_COLORS[notification.tone].fg} />
          <View style={{ flex: 1, minWidth: 0, gap: 2 }}>
            <Text style={styles.noticeTitle} numberOfLines={1}>
              {notification.title}
            </Text>
            <Text style={styles.noticeDetail} numberOfLines={2}>
              {notification.detail}
            </Text>
          </View>
        </View>
      ))}

      {/* Money. Two figures, because "we have billed you" and "you have paid"
          are different pieces of news and a single balance hides one of them. */}
      <Card heading="Your account" icon="billing">
        {summary.loading ? (
          <SkeletonRows count={2} />
        ) : summary.error ? (
          <ErrorState message={summary.error.message} onRetry={summary.reload} />
        ) : summary.data ? (
          <>
            <View style={styles.moneyRow}>
              <View style={styles.moneyCell}>
                <Text style={styles.moneyLabel}>PENDING PAYMENT</Text>
                <Text style={styles.moneyValue}>
                  {fmt.money(summary.data.pending_payment_cents, summary.data.currency)}
                </Text>
              </View>
              <View style={styles.moneyCell}>
                <Text style={styles.moneyLabel}>ALREADY PAID</Text>
                <Text style={[styles.moneyValue, { color: Brand.success }]}>
                  {fmt.money(summary.data.successful_payment_cents, summary.data.currency)}
                </Text>
              </View>
            </View>

            <View style={styles.counts}>
              <Count label="Awaiting" value={summary.data.awaiting_confirmation} />
              <Count label="Booked" value={summary.data.scheduled} />
              <Count label="On the road" value={summary.data.in_transit} />
              <Count label="Delivered" value={summary.data.delivered} />
            </View>
          </>
        ) : null}
      </Card>

      {/* Who this firm sends with, and what is outstanding to each.
          One row for a customer the office added — they have one haulier and
          will only ever have one, and the card still earns its place by naming
          them. Several for a business that signed itself up and shopped
          around, where "you owe ₱48,000" means nothing until it says to whom. */}
      {(summary.data?.carriers ?? []).length > 0 ? (
        <Card
          heading={
            (summary.data?.carriers.length ?? 0) > 1 ? 'Your carriers' : 'Your carrier'
          }
          icon="fleet"
          padded={false}>
          {(summary.data?.carriers ?? []).map((carrier, index, all) => (
            <View
              key={carrier.id}
              style={[styles.row, index < all.length - 1 && styles.rowDivider]}>
              <View style={{ flex: 1, minWidth: 0, gap: 3 }}>
                <Text style={styles.rowTitle} numberOfLines={1}>
                  {carrier.name}
                </Text>
                <Text style={styles.rowSub}>
                  {carrier.awaiting_confirmation > 0
                    ? `${carrier.awaiting_confirmation} awaiting confirmation · `
                    : ''}
                  {carrier.in_transit > 0 ? `${carrier.in_transit} on the road · ` : ''}
                  {carrier.delivered} delivered
                </Text>
              </View>
              <View style={{ alignItems: 'flex-end', gap: 2 }}>
                <Text style={styles.rowOwed}>
                  {fmt.money(carrier.pending_payment_cents, summary.data?.currency ?? 'PHP')}
                </Text>
                <Text style={styles.rowSub}>owed</Text>
              </View>
            </View>
          ))}
        </Card>
      ) : null}

      <Card
        heading="Recent deliveries"
        icon="shipments"
        hint={requests.data ? `${requests.data.length}` : undefined}
        padded={false}>
        {requests.loading ? (
          <View style={{ padding: Spacing.three }}>
            <SkeletonRows count={3} />
          </View>
        ) : requests.error ? (
          <ErrorState message={requests.error.message} onRetry={requests.reload} />
        ) : recent.length === 0 ? (
          <EmptyState
            title={
              (requests.data ?? []).length === 0 ? 'No deliveries yet' : 'Nothing on the move'
            }
            body={
              (requests.data ?? []).length === 0
                ? 'Ask for a pickup and it will show up here while the office confirms it.'
                : 'Everything you have sent has arrived. The finished ones are under History on Deliveries.'
            }
          />
        ) : (
          recent.map((trip: Trip, index: number) => (
            <View
              key={trip.id}
              style={[styles.row, index < recent.length - 1 && styles.rowDivider]}>
              <View style={{ flex: 1, minWidth: 0, gap: 3 }}>
                <Text style={styles.rowTitle}>{trip.reference}</Text>
                <Text style={styles.rowSub} numberOfLines={1}>
                  {trip.origin} → {trip.destination}
                </Text>
                <Text style={styles.rowSub}>
                  {fmt.dateTime(trip.scheduled_at)} ·{' '}
                  {trip.price_cents === null
                    ? 'Price to be confirmed'
                    : fmt.money(trip.price_cents, trip.currency)}
                </Text>
              </View>
              <StatusPill status={trip.status} />
            </View>
          ))
        )}
      </Card>
    </Screen>
  );
}

function Count({ label, value }: { label: string; value: number }) {
  return (
    <View style={styles.count}>
      <Text style={styles.countValue}>{value}</Text>
      <Text style={styles.countLabel}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  intro: { gap: 4, paddingHorizontal: Spacing.half },
  greeting: { fontSize: 24, fontWeight: '700', color: Brand.ink, letterSpacing: -0.3 },
  introSub: { fontSize: 13, fontWeight: '500', color: Brand.inkMuted },
  introSummary: { marginTop: 4, fontSize: 14, lineHeight: 20, color: Brand.ink },

  notice: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two + 2,
    overflow: 'hidden',
    borderRadius: Radius.card,
    paddingVertical: Spacing.two + 4,
    paddingRight: Spacing.three,
    paddingLeft: Spacing.three + 4,
  },
  noticeBar: { position: 'absolute', left: 0, top: 0, bottom: 0, width: 4 },
  noticeTitle: { fontSize: 13, fontWeight: '600', color: Brand.ink },
  noticeDetail: { fontSize: 12, color: Brand.inkMuted },

  moneyRow: { flexDirection: 'row', gap: Spacing.three },
  moneyCell: { flex: 1, minWidth: 0, gap: 4 },
  moneyLabel: { fontSize: 10, fontWeight: '600', letterSpacing: 0.6, color: Brand.inkMuted },
  moneyValue: {
    fontSize: 20,
    fontWeight: '700',
    color: Brand.ink,
    fontVariant: ['tabular-nums'],
  },

  counts: {
    flexDirection: 'row',
    marginTop: Spacing.four,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Brand.line,
    paddingTop: Spacing.three,
  },
  count: { flex: 1, alignItems: 'center', gap: 2 },
  countValue: { fontSize: 18, fontWeight: '700', color: Brand.ink, fontVariant: ['tabular-nums'] },
  countLabel: { fontSize: 11, color: Brand.inkMuted, textAlign: 'center' },

  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    minHeight: Hit.rowTwoLine,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two + 2,
  },
  rowDivider: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: Brand.line },
  rowTitle: { fontSize: 14, fontWeight: '600', color: Brand.ink, fontVariant: ['tabular-nums'] },
  rowSub: { fontSize: 12, color: Brand.inkMuted },
  rowOwed: { fontSize: 14, fontWeight: '700', color: Brand.ink, fontVariant: ['tabular-nums'] },
});

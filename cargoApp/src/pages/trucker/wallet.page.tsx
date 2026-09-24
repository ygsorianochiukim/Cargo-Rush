import { StyleSheet, Text, View } from 'react-native';

import { Screen } from '@/components/screen';
import { Icon } from '@/components/ui/icon';
import { Card, EmptyState, ErrorState, SkeletonRows } from '@/components/ui/primitives';
import { fmt } from '@/constants/format';
import { Brand, Radius, Spacing } from '@/constants/theme';
import { WalletEntry } from '@/models/trucker/trucker.model';
import { truckerService } from '@/services/trucker/trucker.service';
import { useApi } from '@/hooks/use-api';

/**
 * The partner's money.
 *
 * ## The balance means two different things, and the screen says which
 *
 * A positive balance is the fleet owing them for work it billed and collected.
 * A negative one is them owing the fleet its cut of a run they collected
 * themselves. Both are ordinary, most partners are both at once across a week,
 * and the two net — which is the point of one account rather than a payable
 * and a receivable that never meet.
 *
 * What the screen must never do is print a figure and leave the direction to be
 * inferred from a minus sign. So the headline is worded from `standing`, in a
 * sentence, and the colour is a second cue rather than the only one.
 *
 * ## Why the commission is shown at all
 *
 * It would be easy to show a partner only their net and never mention the cut.
 * Every row therefore carries what the run billed, what the rate was, and what
 * came off — frozen at the moment it was written, so a figure from March still
 * explains itself in November whatever has been renegotiated since. A platform
 * that hides its own percentage is one people stop trusting the first time they
 * work it out.
 *
 * ## Read-only, deliberately
 *
 * There is nothing on this screen that moves a peso. Payouts and remittances
 * are recorded by the office against a named person; a wallet a partner could
 * write to would not be a record of anything.
 */
export function WalletPage() {
  const wallet = useApi(() => truckerService.wallet());

  const data = wallet.data;
  const balance = data?.balance_cents ?? 0;

  /** Whose money it is, in a sentence rather than a sign. */
  const headline =
    data?.standing === 'owed_to_company'
      ? 'You owe the fleet'
      : data?.standing === 'owed_to_trucker'
        ? 'The fleet owes you'
        : 'All settled up';

  const tone =
    data?.standing === 'owed_to_company'
      ? { fg: Brand.red, bg: Brand.redBg }
      : data?.standing === 'owed_to_trucker'
        ? { fg: Brand.success, bg: Brand.successBg }
        : { fg: Brand.inkMuted, bg: Brand.tint };

  return (
    <Screen title="Wallet">
      {wallet.loading ? (
        <Card>
          <SkeletonRows count={4} />
        </Card>
      ) : wallet.error ? (
        <ErrorState message={wallet.error.message} onRetry={wallet.reload} />
      ) : !data ? null : (
        <>
          <Card>
            <View style={[styles.balance, { backgroundColor: tone.bg }]}>
              <Text style={styles.balanceLabel}>{headline.toUpperCase()}</Text>
              {/*
                The magnitude, never the raw signed figure. "−₱1,200" against
                "You owe the fleet" is the same fact stated twice, once
                confusingly.
              */}
              <Text style={[styles.balanceValue, { color: tone.fg }]}>
                {fmt.money(Math.abs(balance))}
              </Text>
              <Text style={styles.balanceHint}>
                {data.standing === 'owed_to_company'
                  ? 'Settle this at the office, or it comes off your next payout.'
                  : data.standing === 'owed_to_trucker'
                    ? 'The office pays this out — it will show here as a payout.'
                    : 'Nothing owed either way.'}
              </Text>
            </View>

            <View style={styles.figures}>
              <Figure label="Earned" value={fmt.money(data.earned_cents)} />
              <Figure label="Fees charged" value={fmt.money(data.commission_cents)} />
              <Figure label="Paid out" value={fmt.money(data.paid_out_cents)} />
            </View>

            {/*
              What is still waiting to be paid, run by run.

              Deliberately separate from the balance above. The balance folds in
              adjustments — a damaged pallet, a correction agreed by phone — so
              it can be smaller than the work outstanding, and "what am I still
              owed for" is the question somebody opens this screen to answer.
            */}
            {/*
              Money the fleet has sent that has not landed.

              Beside the balance rather than taken off it: a transfer takes a
              day and can bounce, and a wallet that dropped to nought the
              moment the office typed it would be telling somebody they had
              been paid while their bank says otherwise.
            */}
            {data.in_flight_cents > 0 ? (
              <View style={styles.waiting}>
                <Text style={styles.waitingLabel}>On the way to you</Text>
                <Text style={[styles.waitingValue, { color: Brand.blue }]}>
                  {fmt.money(data.in_flight_cents)}
                </Text>
              </View>
            ) : null}

            {data.unpaid_earnings_count > 0 ? (
              <View style={styles.waiting}>
                <Text style={styles.waitingLabel}>
                  {data.unpaid_earnings_count}{' '}
                  {data.unpaid_earnings_count === 1 ? 'run' : 'runs'} not paid yet
                </Text>
                <Text style={styles.waitingValue}>
                  {fmt.money(data.unpaid_earnings_cents)}
                </Text>
              </View>
            ) : null}

            {data.unremitted_commission_count > 0 ? (
              <View style={styles.waiting}>
                <Text style={styles.waitingLabel}>
                  Commission you still owe on {data.unremitted_commission_count}{' '}
                  {data.unremitted_commission_count === 1 ? 'run' : 'runs'}
                </Text>
                <Text style={[styles.waitingValue, { color: Brand.red }]}>
                  {fmt.money(data.unremitted_commission_cents)}
                </Text>
              </View>
            ) : null}

            <Text style={styles.settled}>
              {data.trips_settled} {data.trips_settled === 1 ? 'trip' : 'trips'} settled
            </Text>
          </Card>

          <Card heading="Statement" icon="wallet" padded={false}>
            {data.entries.length === 0 ? (
              <EmptyState
                title="Nothing yet"
                body="Every run you finish shows up here the moment it is handed over."
              />
            ) : (
              data.entries.map((entry, index) => (
                <EntryRow
                  key={entry.id}
                  entry={entry}
                  last={index === data.entries.length - 1}
                />
              ))
            )}
          </Card>
        </>
      )}
    </Screen>
  );
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <View style={{ flex: 1, minWidth: 0, gap: 2 }}>
      <Text style={styles.figureLabel}>{label.toUpperCase()}</Text>
      <Text style={styles.figureValue} numberOfLines={1}>
        {value}
      </Text>
    </View>
  );
}

/**
 * One line of the statement.
 *
 * The sign decides the colour and the leading symbol; the description explains
 * itself. The second line is the arithmetic — what the run billed and what came
 * off it — shown only where there was a run, because a payout has no gross.
 */
function EntryRow({ entry, last }: { entry: WalletEntry; last: boolean }) {
  const credited = entry.amount_cents > 0;

  return (
    <View style={[styles.entry, !last && styles.divider]}>
      <View style={[styles.entryIcon, { backgroundColor: credited ? Brand.successBg : Brand.tint }]}>
        <Icon
          name={iconFor(entry)}
          size={15}
          color={credited ? Brand.success : Brand.inkMuted}
        />
      </View>

      <View style={{ flex: 1, minWidth: 0, gap: 2 }}>
        <Text style={styles.entryTitle} numberOfLines={1}>
          {entry.description}
        </Text>
        <Text style={styles.entryMeta} numberOfLines={1}>
          {fmt.date(entry.occurred_on)}
          {entry.gross_cents !== null && entry.rate_bp !== null
            ? ` · ${fmt.money(entry.gross_cents)} billed · ${(entry.rate_bp / 100).toFixed(
                entry.rate_bp % 100 === 0 ? 0 : 1,
              )}% fee`
            : ''}
        </Text>
        {/*
          Where the work came from.

          The audit line, and the thing that makes the direction of the amount
          make sense a year later: a charge against a run they billed
          themselves reads as arbitrary without it.
        */}
        {entry.source_label ? (
          <Text style={styles.entrySource}>{entry.source_label}</Text>
        ) : null}

        {/*
          Paid, or still waiting.

          The line a partner scans the list for. Only on rows that can be paid
          for — a payout is the payment itself, and an adjustment is a
          correction — so neither is labelled either way.
        */}
        {entry.payment_state === 'paid' ? (
          <Text style={[styles.entryState, styles.paid]}>
            {`PAID ${fmt.date(entry.settled_at)}${
              entry.settlement_reference ? ` · ${entry.settlement_reference}` : ''
            }`}
          </Text>
        ) : entry.payment_state === 'processing' ? (
          // Sent, not landed. Said plainly, because "paid" here while their
          // bank says otherwise is how a partner stops trusting the wallet.
          <Text style={[styles.entryState, styles.processing]}>
            {`ON THE WAY${
              entry.settlement_reference ? ` · ${entry.settlement_reference}` : ''
            }`}
          </Text>
        ) : entry.payment_state === 'unpaid' ? (
          <Text style={[styles.entryState, styles.unpaid]}>NOT PAID YET</Text>
        ) : null}
      </View>

      <Text style={[styles.entryAmount, { color: credited ? Brand.success : Brand.ink }]}>
        {credited ? '+' : '−'}
        {fmt.money(Math.abs(entry.amount_cents))}
      </Text>
    </View>
  );
}

function iconFor(entry: WalletEntry): string {
  switch (entry.kind) {
    case 'earning':
      return 'shipments';
    case 'commission':
      return 'tag';
    case 'payout':
      return 'wallet';
    case 'remittance':
      return 'billing';
    default:
      return 'clipboard';
  }
}

const styles = StyleSheet.create({
  balance: {
    alignItems: 'center',
    gap: 4,
    padding: Spacing.four,
    borderRadius: Radius.control,
  },
  balanceLabel: { fontSize: 10, fontWeight: '700', letterSpacing: 0.8, color: Brand.inkMuted },
  balanceValue: { fontSize: 32, fontWeight: '700', fontVariant: ['tabular-nums'] },
  balanceHint: {
    fontSize: 12,
    lineHeight: 17,
    color: Brand.inkMuted,
    textAlign: 'center',
    paddingHorizontal: Spacing.two,
  },

  figures: { flexDirection: 'row', gap: Spacing.three, marginTop: Spacing.three },
  figureLabel: { fontSize: 9, fontWeight: '700', letterSpacing: 0.6, color: Brand.inkMuted },
  figureValue: { fontSize: 15, fontWeight: '600', color: Brand.ink, fontVariant: ['tabular-nums'] },

  waiting: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
    marginTop: Spacing.three,
    paddingTop: Spacing.two,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Brand.line,
  },
  waitingLabel: { flex: 1, minWidth: 0, fontSize: 12, color: Brand.inkMuted },
  waitingValue: {
    fontSize: 14,
    fontWeight: '700',
    color: Brand.warning,
    fontVariant: ['tabular-nums'],
  },

  settled: { marginTop: Spacing.three, fontSize: 12, color: Brand.inkMuted },

  entry: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two + 2,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.three,
  },
  divider: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: Brand.line },
  entryIcon: {
    width: 32,
    height: 32,
    borderRadius: Radius.full,
    alignItems: 'center',
    justifyContent: 'center',
  },
  entryTitle: { fontSize: 14, fontWeight: '600', color: Brand.ink },
  entryMeta: { fontSize: 11, color: Brand.inkMuted, fontVariant: ['tabular-nums'] },
  entrySource: { fontSize: 10, fontWeight: '600', color: Brand.blue },
  entryState: { marginTop: 1, fontSize: 10, fontWeight: '700', letterSpacing: 0.4 },
  paid: { color: Brand.success },
  processing: { color: Brand.blue },
  unpaid: { color: Brand.warning },
  entryAmount: { fontSize: 15, fontWeight: '700', fontVariant: ['tabular-nums'] },
});

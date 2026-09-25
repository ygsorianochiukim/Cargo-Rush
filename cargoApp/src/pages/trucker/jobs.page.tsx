import * as Location from 'expo-location';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { Screen } from '@/components/screen';
import { Icon } from '@/components/ui/icon';
import {
  Card,
  EmptyState,
  ErrorState,
  SkeletonRows,
} from '@/components/ui/primitives';
import { fmt } from '@/constants/format';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { Job } from '@/models/trucker/trucker.model';
import { ApiRequestError } from '@/services/shared/api.service';
import { truckerService } from '@/services/trucker/trucker.service';

/**
 * Jobs offered to this trucker — the screen a partner opens the app for.
 *
 * **Not an open board.** Every row is a request a customer held for them by
 * name, waiting on them to accept. Work nobody has directed anywhere stays with
 * the office until a human places it, and a job offered to somebody else is
 * invisible here — so a trucker never sees a load that was not meant for them.
 *
 * Work the *fleet* assigned is not here either: that is the office exercising a
 * standing arrangement, so it is already theirs and sits on My Trips with
 * nothing left to decide.
 *
 * ## The card leads with the money, and with the right number
 *
 * A board showing what the run bills would be quoting a figure nobody
 * receives. Each card leads with **what lands with them**, states the fleet's
 * percentage under it in plain words, and shows the gross beside it — a
 * percentage somebody discovers after their first payout is how a platform
 * loses the people it depends on.
 *
 * ## Three reasons this screen is empty, and they are not the same screen
 *
 * Waiting on approval, switched off, and simply nobody having asked yet. The
 * API sends the standing alongside the list precisely so this screen can tell
 * them apart — an empty list with no explanation reads as a broken app, and
 * somebody who believes the app is broken stops opening it.
 */
export function JobsPage() {
  const [jobs, setJobs] = useState<Job[]>([]);
  const [canTakeWork, setCanTakeWork] = useState(false);
  const [standing, setStanding] = useState<string>('pending');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);

  /** Which card is mid-accept, so only that one shows a spinner. */
  const [accepting, setAccepting] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      /**
       * The handset's own position, when it will give one.
       *
       * Better than the partner's last reported pin for the obvious reason: it
       * is where they are now. A refusal is not an error — the board still
       * works, it simply arrives unsorted, and the header says so rather than
       * nagging somebody who already said no.
       */
      let position: { lat: number; lng: number } | undefined;

      const permission = await Location.getForegroundPermissionsAsync();

      if (permission.granted) {
        const fix = await Location.getLastKnownPositionAsync();

        if (fix) {
          position = { lat: fix.coords.latitude, lng: fix.coords.longitude };
        }
      }

      const board = await truckerService.jobs(position);

      setJobs(board.jobs);
      setCanTakeWork(board.canTakeWork);
      setStanding(board.status);
    } catch (e) {
      setError(e instanceof Error ? e : new Error(String(e)));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const accept = async (job: Job) => {
    if (accepting) return;

    setAccepting(job.id);
    setNotice(null);

    try {
      await truckerService.accept(job.id);
      setNotice(`${job.reference} is yours. It is on My Trips.`);
    } catch (e) {
      /**
       * A 409 means the offer is gone — the customer cancelled it, or the desk
       * placed the load elsewhere while this screen was open. Nobody's fault,
       * and the honest response is to say so and reload rather than leave a
       * card sitting there that cannot be taken.
       */
      setNotice(
        e instanceof ApiRequestError
          ? e.status === 409
            ? 'That job is no longer available.'
            : e.message
          : 'That did not go through. Check your signal and try again.',
      );
    } finally {
      setAccepting(null);
      void load();
    }
  };

  return (
    <Screen
      title="Jobs"
      subtitle={canTakeWork ? `${jobs.length} available near you` : undefined}>
      {notice ? (
        <Text style={styles.notice} accessibilityLiveRegion="polite">
          {notice}
        </Text>
      ) : null}

      {loading ? (
        <Card>
          <SkeletonRows count={3} />
        </Card>
      ) : error ? (
        <ErrorState message={error.message} onRetry={load} />
      ) : !canTakeWork ? (
        <Standing status={standing} onRefresh={load} />
      ) : jobs.length === 0 ? (
        <EmptyState
          title="No jobs for you yet"
          body="Jobs show up here when a customer picks you, or the office gives you one. Stay online so customers near you can find you."
        />
      ) : (
        jobs.map((job) => (
          <JobCard
            key={job.id}
            job={job}
            busy={accepting === job.id}
            disabled={accepting !== null}
            onAccept={() => accept(job)}
          />
        ))
      )}
    </Screen>
  );
}

/**
 * Why the board is empty, when the reason is about them rather than the work.
 *
 * Two states, and they need different words and different buttons: one is a
 * queue somebody else has to work, the other is a switch they can flip.
 */
function Standing({ status, onRefresh }: { status: string; onRefresh: () => void }) {
  if (status === 'pending') {
    return (
      <Card>
        <View style={styles.standing}>
          <Icon name="clipboard" size={28} color={Brand.blue} />
          <Text style={styles.standingTitle}>We are checking your details</Text>
          <Text style={styles.standingBody}>
            Somebody at the fleet is reviewing your licence and your truck. You will get a
            notification the moment you are approved, and jobs will appear here.
          </Text>
          <Pressable accessibilityRole="button" onPress={onRefresh} style={styles.standingBtn}>
            <Text style={styles.standingBtnText}>Check again</Text>
          </Pressable>
        </View>
      </Card>
    );
  }

  if (status === 'inactive') {
    return (
      <Card>
        <View style={styles.standing}>
          <Icon name="incident" size={28} color={Brand.red} />
          <Text style={styles.standingTitle}>Your account is on hold</Text>
          <Text style={styles.standingBody}>
            The fleet has paused your account. Give the office a ring — your wallet and your
            history are untouched.
          </Text>
        </View>
      </Card>
    );
  }

  // Approved, but either switched off or between trucks. Both are theirs to
  // fix, and the Dashboard is where the switch lives.
  return (
    <Card>
      <View style={styles.standing}>
        <Icon name="profile" size={28} color={Brand.inkMuted} />
        <Text style={styles.standingTitle}>You are offline</Text>
        <Text style={styles.standingBody}>
          Go online from the Dashboard so customers near you can pick you. If your only truck
          is marked as in the shop, put it back on the road there too.
        </Text>
        <Pressable accessibilityRole="button" onPress={onRefresh} style={styles.standingBtn}>
          <Text style={styles.standingBtnText}>Refresh</Text>
        </Pressable>
      </View>
    </Card>
  );
}

function JobCard({
  job,
  busy,
  disabled,
  onAccept,
}: {
  job: Job;
  busy: boolean;
  disabled: boolean;
  onAccept: () => void;
}) {
  const rate = (job.commission_bp / 100).toFixed(
    // 12, not 12.00 — a whole percentage is the ordinary case and the decimals
    // are noise. A negotiated 7.5% still reads correctly.
    job.commission_bp % 100 === 0 ? 0 : 1,
  );

  return (
    <Card padded={false}>
      <View style={styles.card}>
        <View style={styles.head}>
          <Text style={styles.reference}>{job.reference}</Text>
          {/*
            Every row here is an offer somebody made to this trucker by name —
            there is no open board — so the badge is unconditional. It is the
            most useful thing this screen says: you were chosen.
          */}
          <View style={styles.offered}>
            <Icon name="check" size={11} color={Brand.blue} />
            <Text style={styles.offeredText}>ASKED FOR YOU</Text>
          </View>
          {job.distance_from_m !== null ? (
            <Text style={styles.away}>{fmt.metresAsKm(job.distance_from_m)} away</Text>
          ) : null}
        </View>

        <View style={styles.routeRow}>
          <Icon name="map-pin" size={14} color={Brand.blue} />
          <Text style={styles.route} numberOfLines={2}>
            {job.origin} → {job.destination}
          </Text>
        </View>

        <Text style={styles.cargo} numberOfLines={1}>
          {job.cargo} · {fmt.kg(job.weight_kg)}
          {job.truck_category ? ` · ${job.truck_category}` : ''}
        </Text>

        {/*
          The money, and the whole of it.

          What they clear is the headline because it is the figure the decision
          is actually made on. The gross and the fleet's cut are stated under
          it rather than hidden — the arithmetic should be checkable on the
          card, by the person it is about, before they press anything.
        */}
        <View style={styles.money}>
          <View style={{ flex: 1, minWidth: 0 }}>
            <Text style={styles.takeLabel}>YOU EARN</Text>
            <Text style={styles.take}>{fmt.money(job.your_take_cents, job.currency)}</Text>
          </View>
          <View style={styles.breakdown}>
            <Text style={styles.breakdownLine}>
              Billed {fmt.money(job.price_cents, job.currency)}
            </Text>
            <Text style={styles.breakdownLine}>Fleet keeps {rate}%</Text>
          </View>
        </View>

        {/*
          Who collects, said plainly and before they press.

          Everything on this board is work they found themselves, so the money
          is between them and the customer — which is not the same deal as a run
          the fleet hands them, and a partner who learns the difference after
          their first payout is a partner who stops trusting the figures.
        */}
        <Text style={styles.terms}>
          A customer asked for you. You bill them and collect; {rate}% is charged to your
          wallet.
        </Text>

        <View style={styles.footer}>
          <Text style={styles.when}>
            {job.scheduled_at ? fmt.dateTime(job.scheduled_at) : 'As soon as possible'}
          </Text>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={`Take ${job.reference}`}
            accessibilityState={{ disabled: disabled || busy }}
            disabled={disabled || busy}
            onPress={onAccept}
            style={({ pressed }) => [
              styles.take2,
              pressed && { backgroundColor: Brand.blueHover },
              (disabled || busy) && { opacity: 0.5 },
            ]}>
            <Text style={styles.takeText}>{busy ? 'Taking…' : 'Take this job'}</Text>
          </Pressable>
        </View>
      </View>
    </Card>
  );
}

const styles = StyleSheet.create({
  notice: {
    padding: Spacing.two + 2,
    borderRadius: Radius.control,
    backgroundColor: Brand.tint,
    color: Brand.blue,
    fontSize: 13,
    fontWeight: '600',
  },

  card: { padding: Spacing.three, gap: 6 },
  head: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two },
  reference: { fontSize: 15, fontWeight: '700', color: Brand.ink, fontVariant: ['tabular-nums'] },
  offered: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 3,
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: Radius.full,
    backgroundColor: Brand.tint,
  },
  offeredText: { fontSize: 9, fontWeight: '700', letterSpacing: 0.5, color: Brand.blue },
  away: { marginLeft: 'auto', fontSize: 12, color: Brand.inkMuted, fontVariant: ['tabular-nums'] },

  routeRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 6 },
  route: { flex: 1, fontSize: 15, fontWeight: '600', color: Brand.ink, lineHeight: 20 },
  cargo: { fontSize: 12, color: Brand.inkMuted },

  money: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    marginTop: Spacing.two,
    padding: Spacing.two + 2,
    borderRadius: Radius.control,
    backgroundColor: Brand.successBg,
  },
  takeLabel: { fontSize: 9, fontWeight: '700', letterSpacing: 0.6, color: Brand.inkMuted },
  take: { fontSize: 22, fontWeight: '700', color: Brand.success, fontVariant: ['tabular-nums'] },
  breakdown: { alignItems: 'flex-end', gap: 2 },
  breakdownLine: { fontSize: 11, color: Brand.inkMuted, fontVariant: ['tabular-nums'] },

  terms: { marginTop: 4, fontSize: 11, lineHeight: 16, color: Brand.inkMuted },

  footer: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
    marginTop: Spacing.two,
  },
  when: { flex: 1, minWidth: 0, fontSize: 12, color: Brand.inkMuted },
  take2: {
    minHeight: Hit.min,
    paddingHorizontal: Spacing.four,
    borderRadius: Radius.control,
    backgroundColor: Brand.blue,
    alignItems: 'center',
    justifyContent: 'center',
  },
  takeText: { color: Brand.surface, fontSize: 14, fontWeight: '700' },

  standing: { alignItems: 'center', gap: Spacing.two, paddingVertical: Spacing.three },
  standingTitle: { fontSize: 16, fontWeight: '700', color: Brand.ink, textAlign: 'center' },
  standingBody: {
    fontSize: 13,
    lineHeight: 19,
    color: Brand.inkMuted,
    textAlign: 'center',
    paddingHorizontal: Spacing.two,
  },
  standingBtn: {
    marginTop: Spacing.two,
    minHeight: Hit.min,
    paddingHorizontal: Spacing.five,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.blue,
    alignItems: 'center',
    justifyContent: 'center',
  },
  standingBtnText: { color: Brand.blue, fontSize: 14, fontWeight: '600' },
});

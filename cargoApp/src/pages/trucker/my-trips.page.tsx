import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { ProofOfDeliverySheet } from '@/components/proof-of-delivery-sheet';
import { Screen } from '@/components/screen';
import { Icon } from '@/components/ui/icon';
import {
  Card,
  EmptyState,
  ErrorState,
  SkeletonRows,
  StatusPill,
} from '@/components/ui/primitives';
import { fmt } from '@/constants/format';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { Sheet } from '@/components/ui/sheet';
import { Trip } from '@/models/trip/trip.model';
import { TruckerDriver } from '@/models/trucker/trucker.model';
import { crewService } from '@/services/trucker/crew.service';
import { truckerService } from '@/services/trucker/trucker.service';
import { useApi } from '@/hooks/use-api';
import { useRefreshOnFocus } from '@/hooks/use-refresh-on-focus';

type Filter = 'active' | 'history';

/**
 * The partner's own runs: what they took, and what they have finished.
 *
 * The same two-view split the customer's deliveries screen uses, for the same
 * reason — a month of completed runs above the one somebody is actually driving
 * is how a list stops being read.
 *
 * **What is missing here is the pre-trip check, and its absence is the design.**
 * A driver cannot leave the yard until the truck has passed one, because that
 * is the fleet looking over the fleet's own unit. A partner's truck is not the
 * fleet's to clear — there is no checklist to open, no gate to lift, and
 * tapping Start leaves on the run.
 *
 * **Two people use it.** The owner sees every run they took, and can hand an
 * unstarted one to one of their drivers. A driver (`crew`) sees only the runs
 * handed to them, through the `crew/*` endpoints — no prices, no handing out.
 */
export function MyTripsPage({ crew = false }: { crew?: boolean }) {
  const service = crew ? crewService : truckerService;
  const trips = useApi(service.trips);
  const history = useApi(service.history);
  // Back from Inspect, or from another tab: a run checked, started or
  // handed over meanwhile shows as it now is.
  useRefreshOnFocus(trips.reload, history.reload);
  /** The run whose driver the owner is choosing. */
  const [assigning, setAssigning] = useState<Trip | null>(null);

  const [filter, setFilter] = useState<Filter>('active');
  const [starting, setStarting] = useState<string | null>(null);
  const [handing, setHanding] = useState<Trip | null>(null);
  const [photographing, setPhotographing] = useState<Trip | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const rows = (filter === 'active' ? trips.data : history.data) ?? [];
  const state = filter === 'active' ? trips : history;

  const start = async (trip: Trip) => {
    if (starting) return;

    // A trucker's driver checks the truck first, exactly as a Cargo Rush
    // driver does — and a pass there starts the run and opens the map.
    if (crew && !trip.inspection?.passed) {
      router.push({ pathname: '/inspect', params: { trip: trip.id } });

      return;
    }

    setStarting(trip.id);
    setNotice(null);

    try {
      await service.start(trip.id);
      trips.reload();
      // On the road: the map is what they need next, and where GPS is turned on.
      if (crew) router.push('/tracking');
    } catch (e) {
      const message = e instanceof Error ? e.message : 'That did not go through.';

      if (crew && /pre-trip check/i.test(message)) {
        router.push({ pathname: '/inspect', params: { trip: trip.id } });
      }

      if (!crew && /choose which truck/i.test(message)) {
        setAssigning(trip);
      }

      setNotice(message);
    } finally {
      setStarting(null);
    }
  };

  return (
    <Screen
      title="My trips"
      onRefresh={() => {
        trips.reload();
        history.reload();
      }}>
      {notice ? (
        <Text style={styles.notice} accessibilityLiveRegion="polite">
          {notice}
        </Text>
      ) : null}

      <View style={styles.filters}>
        {(
          [
            { key: 'active', label: 'On the go', count: trips.data?.length ?? 0 },
            { key: 'history', label: 'Finished', count: history.data?.length ?? 0 },
          ] as { key: Filter; label: string; count: number }[]
        ).map((option) => {
          const picked = filter === option.key;

          return (
            <Pressable
              key={option.key}
              accessibilityRole="tab"
              accessibilityState={{ selected: picked }}
              accessibilityLabel={`${option.label}, ${option.count}`}
              onPress={() => setFilter(option.key)}
              style={[styles.filter, picked && styles.filterPicked]}>
              <Text style={[styles.filterText, picked && styles.filterTextPicked]}>
                {option.label}
              </Text>
              <Text style={[styles.filterCount, picked && styles.filterTextPicked]}>
                {option.count}
              </Text>
            </Pressable>
          );
        })}
      </View>

      <Card padded={false}>
        {state.loading ? (
          <View style={{ padding: Spacing.three }}>
            <SkeletonRows count={3} />
          </View>
        ) : state.error ? (
          <ErrorState message={state.error.message} onRetry={state.reload} />
        ) : rows.length === 0 ? (
          <EmptyState
            title={filter === 'active' ? 'Nothing on the go' : 'Nothing finished yet'}
            body={
              filter === 'active'
                ? crew
                  ? 'Runs your trucker hands you appear here.'
                  : 'Take a job from the Jobs tab and it will appear here.'
                : 'Runs move here once you have handed them over.'
            }
          />
        ) : (
          rows.map((trip, index) => (
            <View key={trip.id} style={[styles.row, index < rows.length - 1 && styles.divider]}>
              <View style={styles.rowHead}>
                <Text style={styles.reference}>{trip.reference}</Text>
                <StatusPill status={trip.status} />
              </View>

              <View style={styles.routeRow}>
                <Icon name="map-pin" size={14} color={Brand.blue} />
                <Text style={styles.route} numberOfLines={2}>
                  {trip.origin} → {trip.destination}
                </Text>
              </View>

              <Text style={styles.cargo} numberOfLines={1}>
                {trip.cargo} · {fmt.kg(trip.weight_kg)}
              </Text>

              <View style={styles.metaRow}>
                <Meta label="Scheduled" value={fmt.dateTime(trip.scheduled_at)} />
                {/* The money is the owner's, so a driver is not shown it. */}
                {crew ? null : (
                  <Meta
                    label="Billed"
                    value={
                      trip.price_cents === null
                        ? 'Not priced yet'
                        : fmt.money(trip.price_cents, trip.currency)
                    }
                  />
                )}
              </View>

              {/*
                Who is driving it, for the owner — and the way to change that
                before it leaves. Once on the road the person in the cab is the
                person on it, so the button goes away.
              */}
              {crew ? null : (
                <View style={styles.driverRow}>
                  <Icon name="profile" size={14} color={Brand.inkMuted} />
                  <Text style={styles.driverText} numberOfLines={1}>
                    {trip.trucker_driver_name ? `Driver: ${trip.trucker_driver_name}` : 'You are driving'}
                    {' · '}
                    {trip.trucker_plate ? `Truck: ${trip.trucker_plate}` : 'Truck not chosen yet'}
                  </Text>
                  {['assigned', 'scheduled', 'overdue'].includes(trip.status) ? (
                    <Pressable
                      accessibilityRole="button"
                      accessibilityLabel={`Choose a driver for ${trip.reference}`}
                      onPress={() => setAssigning(trip)}
                      style={({ pressed }) => [styles.driverBtn, pressed && { backgroundColor: Brand.tint }]}>
                      <Text style={styles.driverBtnText}>Change</Text>
                    </Pressable>
                  ) : null}
                </View>
              )}

              {/*
                One action per state, and never two.

                A confirmed run can be started; one on the road can be handed
                over; one delivered without a photo can have it sent. Anything
                else gets no button — a disabled control is a question the
                screen is asking and then refusing to answer.
              */}
              {!crew && trip.trucker_driver_id && (trip.status === 'assigned' || trip.status === 'overdue') ? (
                // Handed to a driver: theirs to check and start, from their own
                // phone. The owner takes it back with Change to drive it.
                <Text style={styles.handedNote}>
                  {trip.trucker_driver_name ?? 'Your driver'} starts this run from their phone.
                </Text>
              ) : trip.status === 'assigned' || trip.status === 'overdue' ? (
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={`Start ${trip.reference}`}
                  disabled={starting !== null}
                  onPress={() => start(trip)}
                  style={({ pressed }) => [
                    styles.action,
                    pressed && { backgroundColor: Brand.blueHover },
                    starting !== null && { opacity: 0.5 },
                  ]}>
                  <Icon name="map-pin" size={15} color={Brand.surface} />
                  <Text style={styles.actionText}>
                    {starting === trip.id
                      ? 'Starting…'
                      : crew && !trip.inspection?.passed
                        ? 'Check the truck, then start'
                        : 'Start this run'}
                  </Text>
                </Pressable>
              ) : trip.status === 'in_transit' ? (
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={`Mark ${trip.reference} delivered`}
                  onPress={() => setHanding(trip)}
                  style={({ pressed }) => [
                    styles.action,
                    { backgroundColor: Brand.success },
                    pressed && { opacity: 0.85 },
                  ]}>
                  <Icon name="check" size={15} color={Brand.surface} />
                  <Text style={styles.actionText}>Mark delivered</Text>
                </Pressable>
              ) : trip.status === 'delivered' && trip.has_pod_photo === false ? (
                // Handed over without a picture — the gate had no signal. The
                // one thing left to do to a delivered run, so it gets a button.
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={`Add delivery photo for ${trip.reference}`}
                  onPress={() => setPhotographing(trip)}
                  style={({ pressed }) => [styles.actionOutline, pressed && { backgroundColor: Brand.tint }]}>
                  <Icon name="camera" size={15} color={Brand.blue} />
                  <Text style={styles.actionOutlineText}>Add delivery photo</Text>
                </Pressable>
              ) : null}
            </View>
          ))
        )}
      </Card>

      {/*
        The same sheet the driver uses, handed the partner's endpoint.

        A driver closes the run they are on, resolved from the token; a partner
        names the run, and the API checks it is theirs. Same photograph, same
        typed name, same single available transition — so it is the same screen
        rather than a second copy of it to keep in step.
      */}
      {handing ? (
        <ProofOfDeliverySheet
          open
          onClose={() => setHanding(null)}
          onDelivered={() => {
            trips.reload();
            history.reload();
            setNotice(crew ? 'Handed over.' : 'Handed over. Your wallet has been updated.');
          }}
          reference={handing.reference}
          destination={handing.destination}
          deliver={(proof) => service.deliver(handing.id, proof)}
        />
      ) : null}

      {photographing ? (
        <ProofOfDeliverySheet
          open
          late
          onClose={() => setPhotographing(null)}
          onDelivered={() => {
            history.reload();
            setNotice('Photo sent.');
          }}
          reference={photographing.reference}
          destination={photographing.destination}
          deliver={(proof) => service.attachProof(photographing.id, proof)}
        />
      ) : null}

      {assigning ? (
        <AssignDriverSheet
          trip={assigning}
          onClose={() => setAssigning(null)}
          onAssigned={(message) => {
            setAssigning(null);
            trips.reload();
            setNotice(message);
          }}
        />
      ) : null}
    </Screen>
  );
}

/**
 * The owner choosing who drives a run — themselves or one of their drivers —
 * and which of their trucks it goes out on.
 *
 * Only drivers the owner has on the road, and only trucks that are not in the
 * shop, are offered. Both lists are fetched when the sheet opens, so a driver
 * or truck added a minute ago is in them. Changing the truck after a driver
 * checked it means a fresh check before the run can leave; the API holds it.
 */
function AssignDriverSheet({
  trip,
  onClose,
  onAssigned,
}: {
  trip: Trip;
  onClose: () => void;
  onAssigned: (message: string) => void;
}) {
  const drivers = useApi(truckerService.drivers);
  const trucks = useApi(truckerService.vehicles);
  const [driverId, setDriverId] = useState<string | null>(trip.trucker_driver_id ?? null);
  const [truckId, setTruckId] = useState<string | null>(trip.trucker_vehicle_id ?? null);
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);

  const available = (drivers.data ?? []).filter((d: TruckerDriver) => d.status === 'active');
  const running = (trucks.data ?? []).filter((t) => t.status === 'available');

  /** Big enough, and of the kind the load asks for — the API's own rule. */
  const fits = (t: { capacity_kg: number; truck_category_id: string | null }) =>
    t.capacity_kg >= trip.weight_kg &&
    (!trip.truck_category_id || !t.truck_category_id || t.truck_category_id === trip.truck_category_id);

  // Nothing chosen yet and only one truck can take it: that one, already
  // ticked. With two or more, the choice is left to the owner.
  const fitting = running.filter(fits);
  const effectiveTruck = truckId ?? (fitting.length === 1 ? fitting[0].id : null);

  const save = async () => {
    if (busy) return;

    if (driverId !== null && effectiveTruck === null) {
      setFailure('Choose which truck takes this run too.');

      return;
    }

    setBusy(true);
    setFailure(null);

    try {
      await truckerService.assignDriver(
        trip.id,
        driverId,
        effectiveTruck !== trip.trucker_vehicle_id ? effectiveTruck : null,
      );
      const driver = available.find((d) => d.id === driverId);
      onAssigned(driver ? `${trip.reference} handed to ${driver.name}.` : `You are driving ${trip.reference}.`);
    } catch (e) {
      setFailure(e instanceof Error ? e.message : 'That did not go through.');
      setBusy(false);
    }
  };

  return (
    <Sheet
      open
      onClose={onClose}
      title="Who is driving?"
      subtitle={trip.reference}
      icon="profile"
      footer={
        <>
          <Pressable
            accessibilityRole="button"
            accessibilityState={{ disabled: busy }}
            disabled={busy}
            onPress={() => void save()}
            style={[styles.save, busy && { opacity: 0.5 }]}>
            <Text style={styles.saveText}>{busy ? 'Saving…' : 'Save'}</Text>
          </Pressable>
          <Pressable accessibilityRole="button" onPress={onClose} style={styles.cancel}>
            <Text style={styles.cancelText}>Cancel</Text>
          </Pressable>
        </>
      }>
      {failure ? <Text style={styles.failure}>{failure}</Text> : null}

      <Text style={styles.sheetSection}>DRIVER</Text>
      <DriverOption
        label="I will drive it"
        picked={driverId === null}
        disabled={busy}
        onPress={() => setDriverId(null)}
      />

      {drivers.loading ? (
        <SkeletonRows count={2} />
      ) : drivers.error ? (
        <Text style={styles.failure}>{drivers.error.message}</Text>
      ) : available.length === 0 ? (
        <Text style={styles.sheetHint}>
          No drivers yet. Add them from the More tab, under My drivers.
        </Text>
      ) : (
        available.map((driver) => (
          <DriverOption
            key={driver.id}
            label={driver.name}
            sub={driver.licence_no}
            picked={driverId === driver.id}
            disabled={busy}
            onPress={() => setDriverId(driver.id)}
          />
        ))
      )}

      <Text style={styles.sheetSection}>TRUCK</Text>
      {trucks.loading ? (
        <SkeletonRows count={2} />
      ) : trucks.error ? (
        <Text style={styles.failure}>{trucks.error.message}</Text>
      ) : running.length === 0 ? (
        <Text style={styles.sheetHint}>No truck on the road. Add one, or put one back, from More.</Text>
      ) : (
        running.map((truck) => {
          const ok = fits(truck);

          return (
            <DriverOption
              key={truck.id}
              label={truck.plate}
              sub={`${truck.model} · ${fmt.kg(truck.capacity_kg)}${ok ? '' : ' · too small for this load'}`}
              picked={effectiveTruck === truck.id}
              disabled={busy || !ok}
              onPress={() => setTruckId(truck.id)}
            />
          );
        })
      )}
    </Sheet>
  );
}

function DriverOption({
  label,
  sub,
  picked,
  disabled,
  onPress,
}: {
  label: string;
  sub?: string;
  picked: boolean;
  disabled: boolean;
  onPress: () => void;
}) {
  return (
    <Pressable
      accessibilityRole="radio"
      accessibilityState={{ selected: picked, disabled }}
      disabled={disabled}
      onPress={onPress}
      style={({ pressed }) => [
        styles.option,
        picked && styles.optionPicked,
        pressed && { backgroundColor: Brand.tint },
      ]}>
      <View style={{ flex: 1, minWidth: 0 }}>
        <Text style={styles.optionText}>{label}</Text>
        {sub ? <Text style={styles.optionSub}>{sub}</Text> : null}
      </View>
      {picked ? <Icon name="check" size={16} color={Brand.blue} /> : null}
    </Pressable>
  );
}

function Meta({ label, value }: { label: string; value: string }) {
  return (
    <View style={{ flex: 1, minWidth: 0, gap: 2 }}>
      <Text style={styles.metaLabel}>{label.toUpperCase()}</Text>
      <Text style={styles.metaValue} numberOfLines={1}>
        {value}
      </Text>
    </View>
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

  filters: { flexDirection: 'row', gap: Spacing.two },
  filter: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    minHeight: Hit.min - 8,
    paddingHorizontal: Spacing.three,
    borderRadius: Radius.full,
    borderWidth: 1,
    borderColor: Brand.line,
    backgroundColor: Brand.surface,
  },
  filterPicked: { backgroundColor: Brand.tint, borderColor: Brand.blue },
  filterText: { fontSize: 13, fontWeight: '500', color: Brand.ink },
  filterTextPicked: { color: Brand.blue, fontWeight: '700' },
  filterCount: { fontSize: 12, color: Brand.inkMuted, fontVariant: ['tabular-nums'] },

  row: { paddingHorizontal: Spacing.three, paddingVertical: Spacing.three, gap: 6 },
  divider: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: Brand.line },
  rowHead: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: Spacing.two,
  },
  reference: { fontSize: 15, fontWeight: '700', color: Brand.ink, fontVariant: ['tabular-nums'] },

  routeRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 6 },
  route: { flex: 1, fontSize: 14, color: Brand.ink, lineHeight: 19 },
  cargo: { fontSize: 12, color: Brand.inkMuted },

  metaRow: { flexDirection: 'row', gap: Spacing.three, marginTop: 4 },
  metaLabel: { fontSize: 10, fontWeight: '600', letterSpacing: 0.6, color: Brand.inkMuted },
  metaValue: { fontSize: 13, color: Brand.ink, fontVariant: ['tabular-nums'] },

  action: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: Spacing.two,
    marginTop: Spacing.two,
    minHeight: Hit.min,
    borderRadius: Radius.control,
    backgroundColor: Brand.blue,
  },
  actionText: { color: Brand.surface, fontSize: 14, fontWeight: '700' },
  // Outlined rather than filled: sending a late photo is a follow-up, not the
  // run's next step, and should not shout like Start or Mark delivered do.
  actionOutline: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: Spacing.two,
    marginTop: Spacing.two,
    minHeight: Hit.min,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.blue,
  },
  actionOutlineText: { color: Brand.blue, fontSize: 14, fontWeight: '700' },

  handedNote: { marginTop: Spacing.two, fontSize: 13, color: Brand.inkMuted, fontStyle: 'italic' },
  driverRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 2 },
  driverText: { flex: 1, fontSize: 12, color: Brand.inkMuted },
  driverBtn: {
    minHeight: 32,
    paddingHorizontal: Spacing.two + 2,
    justifyContent: 'center',
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
  },
  driverBtnText: { fontSize: 12, fontWeight: '600', color: Brand.blue },

  failure: {
    marginTop: Spacing.two,
    padding: Spacing.two + 2,
    borderRadius: Radius.control,
    backgroundColor: Brand.redBg,
    color: Brand.red,
    fontSize: 13,
    fontWeight: '500',
  },
  sheetHint: { marginTop: Spacing.three, fontSize: 13, lineHeight: 18, color: Brand.inkMuted },
  option: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
    marginTop: Spacing.two,
    minHeight: Hit.min,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
  },
  sheetSection: {
    marginTop: Spacing.three,
    fontSize: 11,
    fontWeight: '700',
    letterSpacing: 0.8,
    color: Brand.blue,
  },
  save: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.control,
    backgroundColor: Brand.blue,
  },
  saveText: { fontSize: 15, fontWeight: '600', color: Brand.surface },
  cancel: { minHeight: 48, alignItems: 'center', justifyContent: 'center', borderRadius: Radius.control },
  cancelText: { fontSize: 15, fontWeight: '600', color: Brand.ink },
  optionPicked: { borderColor: Brand.blue, backgroundColor: Brand.tint },
  optionText: { fontSize: 14, fontWeight: '600', color: Brand.ink },
  optionSub: { fontSize: 12, color: Brand.inkMuted, fontVariant: ['tabular-nums'] },
});

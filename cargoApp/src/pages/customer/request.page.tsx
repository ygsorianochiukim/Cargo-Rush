import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import { BLANK_LOCATION, TripLocation, isPinned } from '@/models/geo/geo.model';
import { Trip } from '@/models/trip/trip.model';
import { portalService } from '@/services/portal/portal.service';
import { ApiRequestError } from '@/services/shared/api.service';
import { LocationField } from '@/components/location-field';
import { Screen } from '@/components/screen';
import { Icon } from '@/components/ui/icon';
import { Card, PrimaryButton } from '@/components/ui/primitives';
import { Brand, Radius, Spacing } from '@/constants/theme';
import { fmt } from '@/constants/format';

/**
 * Ask for a pickup — the customer's one write.
 *
 * Five fields, and no more, because five is everything a customer can honestly
 * answer: where the load is, where it is going, what it is, what it weighs, and
 * when they want it collected. Driver, helper, unit and the agreed time are the
 * office's, and putting them on this form would either ask the customer to
 * guess or let a request arrive already assigned — which would skip the
 * confirmation step it exists to ask for.
 *
 * The two ends are the same field the back office books with: a name to type,
 * and a map to pin it on. Typing stays the fast path — most requests are for a
 * town somebody can name — and the pin is what answers a yard with no address,
 * which the old plain text box could only answer with a sentence for the desk
 * to interpret. It is worth more than the map: the tariff prices a run on the
 * distance between the two pins, so a pinned request is quoted on the run it
 * actually is, and the driver is given the spot rather than the neighbourhood.
 *
 * What comes back is the trip, `pending`, with its reference and its price. Both
 * are shown before the screen is dismissed: the reference is what they quote on
 * the phone, and the price is the difference between a request and a hopeful
 * message.
 */
export function RequestPage() {
  /**
   * The two ends, each a name and an optional pin.
   *
   * Held as one value apiece rather than as sibling fields, exactly as the
   * office form holds them: a location is three values that only mean anything
   * together, and three separate pieces of state would let a latitude survive
   * a change of place.
   */
  const [origin, setOrigin] = useState<TripLocation>(BLANK_LOCATION);
  const [destination, setDestination] = useState<TripLocation>(BLANK_LOCATION);
  const [cargo, setCargo] = useState('');
  const [weight, setWeight] = useState('');
  const [whenDays, setWhenDays] = useState(1);

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [filed, setFiled] = useState<Trip | null>(null);

  /**
   * The clock, read once when the screen opens.
   *
   * Reading it during render instead would give a different answer every time
   * React re-renders — which here is on every keystroke — so the time shown
   * under the buttons and the time actually sent could differ by however long
   * somebody took to type the weight.
   */
  const [openedAt] = useState(() => Date.now());

  /**
   * The pickup time, as a choice rather than a picker.
   *
   * A native date-time picker is four taps and a scroll wheel to say
   * "tomorrow", which is what almost every request means. The desk can move it
   * when confirming anyway, so precision here would be false precision — this
   * is a wish, not a booking.
   *
   * Nine in the morning, except when nine has already gone: "Today" tapped at
   * two in the afternoon has to mean a time that has not happened, because the
   * API refuses a pickup in the past and being told so after filling in the
   * whole form is worse than the form quietly meaning something sensible. Two
   * hours is the shortest notice worth offering a fleet.
   */
  const pickupAt = () => {
    const at = new Date(openedAt);
    at.setDate(at.getDate() + whenDays);
    at.setHours(9, 0, 0, 0);

    const soonest = openedAt + 2 * 60 * 60 * 1000;

    return at.getTime() < soonest ? new Date(soonest) : at;
  };

  /**
   * The first thing the API objected to about one end of the trip.
   *
   * A location is three fields to the API and one control here, so all three
   * of its messages have to land on that control — otherwise a rule about
   * `origin_lng` is a 422 the screen shows nowhere.
   */
  const errorFor = (...keys: string[]) => keys.map((key) => fieldErrors[key]?.[0]).find(Boolean);

  const reset = () => {
    setOrigin(BLANK_LOCATION);
    setDestination(BLANK_LOCATION);
    setCargo('');
    setWeight('');
    setWhenDays(1);
    setError(null);
    setFieldErrors({});
  };

  const submit = () => {
    const kg = Number(weight);

    // Checked here as well as by the API, so somebody standing in a warehouse
    // is told before the request goes out rather than after a round trip.
    if (!origin.place.trim() || !destination.place.trim() || !cargo.trim()) {
      setError('Fill in where it is going from, where to, and what is being moved.');

      return;
    }

    if (!Number.isFinite(kg) || kg <= 0) {
      setError('Enter the weight in kilograms.');

      return;
    }

    setSaving(true);
    setError(null);
    setFieldErrors({});

    portalService
      .submit({
        origin: origin.place.trim(),
        // Each end travels as a pair or not at all — half a coordinate is not
        // a location, and the API says so with a 422 rather than storing one.
        origin_lat: origin.lat,
        origin_lng: origin.lng,
        destination: destination.place.trim(),
        destination_lat: destination.lat,
        destination_lng: destination.lng,
        cargo: cargo.trim(),
        weight_kg: Math.round(kg),
        preferred_at: pickupAt().toISOString(),
      })
      .then((trip) => {
        setFiled(trip);
        reset();
      })
      .catch((e: Error) => {
        if (e instanceof ApiRequestError) setFieldErrors(e.fieldErrors);
        setError(e.message);
      })
      .finally(() => setSaving(false));
  };

  // The confirmation. A screen of its own rather than a toast, because the
  // reference and the price are the two things the customer came away for and
  // a message that fades is a message they have to ask about later.
  if (filed) {
    return (
      <Screen title="Request filed" subtitle={filed.reference}>
        <Card>
          <View style={styles.doneIcon}>
            <Icon name="check" size={28} color={Brand.success} />
          </View>
          <Text style={styles.doneTitle}>We have your request</Text>
          <Text style={styles.doneBody}>
            {filed.origin} → {filed.destination}
          </Text>

          <View style={styles.quote}>
            <Text style={styles.quoteLabel}>QUOTED</Text>
            <Text style={styles.quoteValue}>
              {fmt.money(filed.price_cents, filed.currency)}
            </Text>
          </View>

          <Text style={styles.doneNote}>
            The office confirms the driver, the vehicle and the time. You will see it move to
            Booked on your deliveries.
          </Text>

          <PrimaryButton
            label="See my deliveries"
            icon="shipments"
            onPress={() => {
              setFiled(null);
              router.push('/orders');
            }}
            style={{ marginTop: Spacing.four }}
          />
          <Pressable
            accessibilityRole="button"
            onPress={() => setFiled(null)}
            style={styles.linkBtn}>
            <Text style={styles.linkBtnText}>Request another pickup</Text>
          </Pressable>
        </Card>
      </Screen>
    );
  }

  return (
    <Screen title="Request a pickup" subtitle="The office confirms the crew and the time">
      <Card>
        <LocationField
          label="Pick up from"
          value={origin}
          onChange={setOrigin}
          placeholder="e.g. Bacolod warehouse"
          error={errorFor('origin', 'origin_lat', 'origin_lng')}
        />

        <LocationField
          label="Deliver to"
          value={destination}
          onChange={setDestination}
          placeholder="e.g. Iloilo depot"
          error={errorFor('destination', 'destination_lat', 'destination_lng')}
          style={{ marginTop: Spacing.four }}
        />

        {/* Said once, under both, rather than as a hint on each: pinning is
            the same offer for either end, and it changes the price as well as
            the map — the quote is worked out from the distance between them. */}
        <Text style={styles.hintText}>
          {isPinned(origin) && isPinned(destination)
            ? 'Both ends pinned. The quote will be worked out from the distance between them.'
            : 'Tap Map to pin either end. A pinned request is quoted on the actual distance, and the driver gets the exact spot.'}
        </Text>

        <FormField
          label="WHAT IS BEING MOVED"
          value={cargo}
          onChange={setCargo}
          placeholder="e.g. Chilled produce, 8 crates"
          error={fieldErrors['cargo']?.[0]}
          style={{ marginTop: Spacing.four }}
        />

        <FormField
          label="WEIGHT (KG)"
          value={weight}
          onChange={setWeight}
          placeholder="e.g. 1800"
          keyboard="number-pad"
          error={fieldErrors['weight_kg']?.[0]}
          style={{ marginTop: Spacing.four }}
        />

        <Text style={[styles.label, { marginTop: Spacing.four }]}>WHEN</Text>
        <View style={styles.whenRow}>
          {[
            { days: 0, label: 'Today' },
            { days: 1, label: 'Tomorrow' },
            { days: 3, label: 'In 3 days' },
            { days: 7, label: 'Next week' },
          ].map((option) => {
            const picked = whenDays === option.days;

            return (
              <Pressable
                key={option.days}
                accessibilityRole="radio"
                accessibilityState={{ selected: picked }}
                accessibilityLabel={`Pick up ${option.label}`}
                onPress={() => setWhenDays(option.days)}
                style={[styles.when, picked && styles.whenPicked]}>
                <Text style={[styles.whenText, picked && styles.whenTextPicked]}>
                  {option.label}
                </Text>
              </Pressable>
            );
          })}
        </View>
        <Text style={styles.hintText}>
          {/* Today at nine has already gone by lunchtime, and the API refuses a
              pickup in the past — so the screen says what it will actually
              send rather than letting the driver of the form find out. */}
          Collection from {fmt.dateTime(pickupAt().toISOString())}. The office may move it.
        </Text>

        {error ? (
          <Text style={styles.error} accessibilityLiveRegion="polite">
            {error}
          </Text>
        ) : null}

        <PrimaryButton
          label={saving ? 'Sending…' : 'Request pickup'}
          icon="plus"
          disabled={saving}
          onPress={submit}
          style={{ marginTop: Spacing.four }}
        />
      </Card>
    </Screen>
  );
}

function FormField({
  label,
  value,
  onChange,
  placeholder,
  keyboard,
  error,
  style,
}: {
  label: string;
  value: string;
  onChange: (next: string) => void;
  placeholder: string;
  keyboard?: 'default' | 'number-pad';
  error?: string;
  style?: object;
}) {
  return (
    <View style={style}>
      <Text style={styles.label}>{label}</Text>
      <TextInput
        value={value}
        onChangeText={onChange}
        placeholder={placeholder}
        placeholderTextColor={Brand.inkMuted}
        keyboardType={keyboard ?? 'default'}
        accessibilityLabel={label}
        style={[styles.input, error ? { borderColor: Brand.red } : null]}
      />
      {error ? (
        <Text style={styles.fieldError} accessibilityLiveRegion="polite">
          {error}
        </Text>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  label: { fontSize: 10, fontWeight: '600', letterSpacing: 0.6, color: Brand.inkMuted },
  input: {
    marginTop: 6,
    minHeight: 46,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
    paddingHorizontal: Spacing.three,
    fontSize: 15,
    color: Brand.ink,
    backgroundColor: Brand.surface,
  },
  fieldError: { marginTop: 4, fontSize: 12, fontWeight: '500', color: Brand.red },

  whenRow: { flexDirection: 'row', flexWrap: 'wrap', gap: Spacing.two, marginTop: 6 },
  when: {
    minHeight: 40,
    justifyContent: 'center',
    paddingHorizontal: Spacing.three,
    borderRadius: Radius.full,
    borderWidth: 1,
    borderColor: Brand.line,
  },
  whenPicked: { backgroundColor: Brand.tint, borderColor: Brand.blue },
  whenText: { fontSize: 13, fontWeight: '500', color: Brand.ink },
  whenTextPicked: { color: Brand.blue, fontWeight: '700' },

  hintText: { marginTop: Spacing.two, fontSize: 12, color: Brand.inkMuted },
  error: { marginTop: Spacing.three, fontSize: 13, fontWeight: '500', color: Brand.red },

  doneIcon: {
    alignSelf: 'center',
    width: 56,
    height: 56,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.full,
    backgroundColor: Brand.successBg,
  },
  doneTitle: {
    marginTop: Spacing.three,
    fontSize: 18,
    fontWeight: '700',
    color: Brand.ink,
    textAlign: 'center',
  },
  doneBody: { marginTop: 4, fontSize: 14, color: Brand.inkMuted, textAlign: 'center' },
  quote: {
    marginTop: Spacing.four,
    alignItems: 'center',
    gap: 4,
    borderRadius: Radius.card,
    backgroundColor: Brand.tint,
    paddingVertical: Spacing.three,
  },
  quoteLabel: { fontSize: 10, fontWeight: '600', letterSpacing: 0.6, color: Brand.inkMuted },
  quoteValue: {
    fontSize: 26,
    fontWeight: '700',
    color: Brand.ink,
    fontVariant: ['tabular-nums'],
  },
  doneNote: {
    marginTop: Spacing.three,
    fontSize: 13,
    lineHeight: 19,
    color: Brand.inkMuted,
    textAlign: 'center',
  },
  linkBtn: {
    minHeight: 44,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: Spacing.two,
  },
  linkBtnText: { fontSize: 14, fontWeight: '600', color: Brand.blue },
});

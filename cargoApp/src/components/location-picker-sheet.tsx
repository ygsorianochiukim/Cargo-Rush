import * as Location from 'expo-location';
import { useEffect, useRef, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { MapCanvas } from '@/components/map/map-canvas';
import { Icon } from '@/components/ui/icon';
import { PrimaryButton } from '@/components/ui/primitives';
import { Sheet } from '@/components/ui/sheet';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { GeoPoint, GeoResult, TripLocation, asPoint, coordinateLabel } from '@/models/geo/geo.model';
import { geoService } from '@/services/geo/geo.service';

/** Nominatim asks for no more than a request a second; a search per keystroke is not that. */
const DEBOUNCE_MS = 450;

/**
 * Put a place on the map — the handset's half of the back office's location
 * dialog (`CargoUI/src/app/shared/map-picker.ts`).
 *
 * Three ways to the same answer, because a customer filing a request is in one
 * of three situations. They know what the place is called, so they search for
 * it. They are standing in it, so they take the phone's own position — the one
 * route the desk does not have and the reason geotagging from a handset is
 * worth anything. Or it is a gate on a road with no name, so they tap the map,
 * which is the only thing that works for most depots.
 *
 * Whatever they do, the pin is looked up and **named**: a request that reaches
 * the desk as a pair of coordinates is a request somebody has to ring back
 * about. The name is a neighbourhood rather than a building, and it is said
 * that way — the pin is the precise part.
 *
 * Nothing is committed until "Use this location". Closing the sheet leaves the
 * field exactly as it was, because on a phone a stray tap on the scrim is a
 * miss, not a decision.
 */
export function LocationPickerSheet({
  open,
  label,
  value,
  onClose,
  onPicked,
}: {
  open: boolean;
  /** "Pick up from", "Deliver to" — the field this is standing in for. */
  label: string;
  value: TripLocation;
  onClose: () => void;
  /** The chosen point, or null to clear the pin off the field. */
  onPicked: (point: GeoPoint | null) => void;
}) {
  const [pin, setPin] = useState<GeoPoint | null>(null);
  const [term, setTerm] = useState('');
  const [results, setResults] = useState<GeoResult[]>([]);
  const [searching, setSearching] = useState(false);
  const [resolving, setResolving] = useState(false);
  const [locating, setLocating] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  /**
   * Which lookup is the current one.
   *
   * Two pins dropped in quick succession are two reverse lookups, and they can
   * come back in either order. Without this the first pin's name lands on the
   * second pin.
   */
  const lookup = useRef(0);

  // Opening starts from whatever the field holds, so re-opening a pinned
  // place shows that pin rather than an empty map.
  useEffect(() => {
    if (!open) return;

    setPin(asPoint(value));
    setTerm('');
    setResults([]);
    setNotice(null);
    setResolving(false);
    // `open` alone, and not the value it reads: this is the reset that runs
    // when the sheet appears. Re-running it on every change to the field would
    // drag the pin back to the saved one while somebody is still choosing.
  }, [open]);

  useEffect(() => {
    const query = term.trim();

    if (query.length < 3) {
      setResults([]);
      setSearching(false);

      return;
    }

    setSearching(true);

    // `live` as well as the timer: a search already in flight when the term
    // changes still resolves, and its results would land under the new one.
    let live = true;

    const timer = setTimeout(() => {
      geoService.search(query).then((found) => {
        if (!live) return;

        setResults(found);
        setSearching(false);
      });
    }, DEBOUNCE_MS);

    return () => {
      live = false;
      clearTimeout(timer);
    };
  }, [term]);

  /**
   * Name the pin.
   *
   * The point is set first and the name follows, so a lookup that fails — or a
   * warehouse with no signal — still leaves a usable pin. A place the geocoder
   * has never heard of is still a place the truck has to get to; it keeps its
   * coordinates as its name rather than a word that names nothing.
   */
  const identify = (lat: number, lng: number) => {
    const ticket = ++lookup.current;

    setPin({ place: '', lat, lng });
    setResolving(true);
    setNotice(null);

    geoService.reverse(lat, lng).then((found) => {
      if (ticket !== lookup.current) return;

      setResolving(false);
      setPin({ place: found?.place ?? coordinateLabel(lat, lng), lat, lng });
    });
  };

  const choose = (result: GeoResult) => {
    lookup.current++;
    setResults([]);
    setTerm('');
    setResolving(false);
    setPin(result);
  };

  /**
   * The phone's own position.
   *
   * Foreground permission only: this is a person standing somewhere pressing a
   * button, not the background reporting a driver's truck does.
   */
  const here = async () => {
    setLocating(true);
    setNotice(null);

    try {
      const permission = await Location.requestForegroundPermissionsAsync();

      if (!permission.granted) {
        setNotice('Location is turned off for this app. Search for the place, or tap the map.');

        return;
      }

      const position = await Location.getCurrentPositionAsync({
        accuracy: Location.Accuracy.Balanced,
      });

      identify(position.coords.latitude, position.coords.longitude);
    } catch {
      setNotice('Could not read your position. Search for the place, or tap the map.');
    } finally {
      setLocating(false);
    }
  };

  const clear = () => {
    lookup.current++;
    setPin(null);
    setResolving(false);
    onPicked(null);
    onClose();
  };

  return (
    <Sheet
      open={open}
      title={label}
      subtitle="Search, use your position, or tap the map"
      icon="map-pin"
      onClose={onClose}
      footer={
        <>
          <PrimaryButton
            label="Use this location"
            icon="check"
            disabled={pin === null}
            onPress={() => {
              if (pin === null) return;

              onPicked(pin);
              onClose();
            }}
          />
          {pin !== null ? (
            <Pressable onPress={clear} accessibilityRole="button" style={styles.clearBtn}>
              <Text style={styles.clearBtnText}>Remove the pin</Text>
            </Pressable>
          ) : null}
        </>
      }>
      <View style={styles.searchRow}>
        <Icon name="search" size={16} color={Brand.inkMuted} style={styles.searchIcon} />
        <TextInput
          value={term}
          onChangeText={setTerm}
          placeholder="Search a town, depot or landmark…"
          placeholderTextColor={Brand.inkMuted}
          accessibilityLabel="Search for a place"
          autoCorrect={false}
          returnKeyType="search"
          style={styles.searchInput}
        />
      </View>

      {results.length > 0 ? (
        <ScrollView style={styles.results} keyboardShouldPersistTaps="handled">
          {results.map((result) => (
            <Pressable
              key={result.id}
              accessibilityRole="button"
              onPress={() => choose(result)}
              style={styles.result}>
              <Text style={styles.resultName} numberOfLines={1}>
                {result.place}
              </Text>
              {result.detail ? (
                <Text style={styles.resultDetail} numberOfLines={1}>
                  {result.detail}
                </Text>
              ) : null}
            </Pressable>
          ))}
        </ScrollView>
      ) : searching ? (
        <Text style={styles.searchingText}>Searching…</Text>
      ) : null}

      {/* 220 rather than the desk's 280: the sheet also carries a search box,
          a button and the readout, and a phone that has to scroll to reach
          "Use this location" has buried the only thing it is asking for. */}
      <View style={{ marginTop: Spacing.three }}>
        <MapCanvas point={pin} onPick={identify} height={220} />
      </View>

      <Pressable
        onPress={here}
        disabled={locating}
        accessibilityRole="button"
        style={[styles.hereBtn, locating && { opacity: 0.5 }]}>
        <Icon name="map-pin" size={15} color={Brand.blue} />
        <Text style={styles.hereBtnText}>
          {locating ? 'Reading your position…' : 'Use my current location'}
        </Text>
      </Pressable>

      {/* What is actually pinned, in words. The coordinates are the answer the
          fleet uses; the name is the answer a person can check it against. */}
      <View style={styles.readout}>
        <Icon name="map-pin" size={14} color={pin ? Brand.blue : Brand.inkMuted} />
        {pin === null ? (
          <Text style={styles.readoutEmpty}>Nothing pinned yet.</Text>
        ) : (
          <View style={{ flex: 1, minWidth: 0 }}>
            <Text style={styles.readoutName} numberOfLines={2}>
              {resolving ? 'Looking up the place name…' : pin.place || 'Unnamed point'}
            </Text>
            <Text style={styles.readoutCoords}>{coordinateLabel(pin.lat, pin.lng)}</Text>
          </View>
        )}
      </View>

      {notice ? (
        <Text style={styles.notice} accessibilityLiveRegion="polite">
          {notice}
        </Text>
      ) : null}
    </Sheet>
  );
}

const styles = StyleSheet.create({
  searchRow: { justifyContent: 'center' },
  searchIcon: { position: 'absolute', left: Spacing.three, zIndex: 1 },
  searchInput: {
    minHeight: Hit.min,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
    paddingLeft: 40,
    paddingRight: Spacing.three,
    fontSize: 15,
    color: Brand.ink,
    backgroundColor: Brand.surface,
  },

  results: {
    // Three rows, then it scrolls. More than that and the map goes off the
    // bottom of the sheet, which is where the person was heading next.
    maxHeight: 140,
    marginTop: Spacing.two,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
  },
  result: {
    minHeight: Hit.min,
    justifyContent: 'center',
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two,
    borderBottomWidth: 1,
    borderBottomColor: Brand.line,
  },
  resultName: { fontSize: 14, fontWeight: '600', color: Brand.ink },
  resultDetail: { marginTop: 2, fontSize: 12, color: Brand.inkMuted },
  searchingText: { marginTop: Spacing.two, fontSize: 12, color: Brand.inkMuted },

  hereBtn: {
    marginTop: Spacing.two,
    minHeight: Hit.min,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: Spacing.two,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
  },
  hereBtnText: { fontSize: 14, fontWeight: '600', color: Brand.blue },

  readout: {
    marginTop: Spacing.two,
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
    minHeight: 40,
  },
  readoutEmpty: { flex: 1, fontSize: 13, color: Brand.inkMuted },
  readoutName: { fontSize: 14, fontWeight: '600', color: Brand.ink },
  readoutCoords: {
    marginTop: 2,
    fontSize: 12,
    color: Brand.inkMuted,
    fontVariant: ['tabular-nums'],
  },

  notice: { marginTop: Spacing.two, fontSize: 12, color: Brand.warning },

  clearBtn: { minHeight: Hit.min, alignItems: 'center', justifyContent: 'center' },
  clearBtnText: { fontSize: 14, fontWeight: '600', color: Brand.red },
});

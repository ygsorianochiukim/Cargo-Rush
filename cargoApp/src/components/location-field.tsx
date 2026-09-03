import { useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View, ViewStyle } from 'react-native';

import { LocationPickerSheet } from '@/components/location-picker-sheet';
import { Icon } from '@/components/ui/icon';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { GeoPoint, TripLocation, coordinateLabel, isPinned } from '@/models/geo/geo.model';

/**
 * A place on a form: type the name, or pin it on the map.
 *
 * The handset's copy of the back office's location field, and typing is the
 * primary path here for the same reason it is there: most requests are for a
 * town somebody already knows the name of, and making them open a map to write
 * "Ozamis" would be slower than the phone call this replaces. The map answers
 * what typing cannot — a depot gate with no address, two towns of the same
 * name, or a customer standing in a yard they cannot name.
 *
 * Pinning fills an empty name in from the lookup, so the two never drift
 * apart. Editing the name afterwards keeps the pin: renaming "Poblacion" to
 * "Ozamis depot" is labelling the same point, not moving it.
 */
export function LocationField({
  label,
  value,
  onChange,
  placeholder,
  error,
  style,
}: {
  label: string;
  value: TripLocation;
  onChange: (next: TripLocation) => void;
  placeholder: string;
  error?: string;
  style?: ViewStyle;
}) {
  const [open, setOpen] = useState(false);
  const pinned = isPinned(value);

  const onPicked = (point: GeoPoint | null) => {
    if (point === null) {
      onChange({ place: value.place, lat: null, lng: null });

      return;
    }

    // The looked-up name fills an empty field and never overwrites a written
    // one — "Ozamis depot" beats "Poblacion".
    onChange({ place: value.place.trim() || point.place, lat: point.lat, lng: point.lng });
  };

  return (
    <View style={style}>
      <Text style={styles.label}>{label.toUpperCase()}</Text>

      <View style={styles.row}>
        <TextInput
          value={value.place}
          // Coordinates are kept: renaming a pinned place is labelling the
          // point, not moving it.
          onChangeText={(place) => onChange({ ...value, place })}
          placeholder={placeholder}
          placeholderTextColor={Brand.inkMuted}
          accessibilityLabel={label}
          style={[styles.input, error ? { borderColor: Brand.red } : null]}
        />

        <Pressable
          onPress={() => setOpen(true)}
          accessibilityRole="button"
          accessibilityLabel={`${pinned ? 'Change the map pin for' : 'Pin'} ${label.toLowerCase()}`}
          style={[styles.mapBtn, pinned && styles.mapBtnPinned]}>
          <Icon name="map-pin" size={15} color={pinned ? Brand.blue : Brand.inkMuted} />
          <Text style={[styles.mapBtnText, pinned && styles.mapBtnTextPinned]}>
            {pinned ? 'Pinned' : 'Map'}
          </Text>
        </Pressable>
      </View>

      {pinned ? (
        <Text style={styles.coords}>{coordinateLabel(value.lat!, value.lng!)}</Text>
      ) : null}

      {error ? (
        <Text style={styles.error} accessibilityLiveRegion="polite">
          {error}
        </Text>
      ) : null}

      <LocationPickerSheet
        open={open}
        label={label}
        value={value}
        onClose={() => setOpen(false)}
        onPicked={onPicked}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  label: { fontSize: 10, fontWeight: '600', letterSpacing: 0.6, color: Brand.inkMuted },
  row: { marginTop: 6, flexDirection: 'row', gap: Spacing.two },
  input: {
    flex: 1,
    minWidth: 0,
    minHeight: 46,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
    paddingHorizontal: Spacing.three,
    fontSize: 15,
    color: Brand.ink,
    backgroundColor: Brand.surface,
  },
  mapBtn: {
    minHeight: Hit.min,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: Spacing.three,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
  },
  mapBtnPinned: { borderColor: Brand.blue, backgroundColor: Brand.tint },
  mapBtnText: { fontSize: 13, fontWeight: '600', color: Brand.inkMuted },
  mapBtnTextPinned: { color: Brand.blue },

  coords: { marginTop: 4, fontSize: 11, color: Brand.inkMuted, fontVariant: ['tabular-nums'] },
  error: { marginTop: 4, fontSize: 12, fontWeight: '500', color: Brand.red },
});

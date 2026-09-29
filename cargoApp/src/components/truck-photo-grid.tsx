import { Image } from 'expo-image';
import * as ImagePicker from 'expo-image-picker';
import { useState } from 'react';
import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';

import { Icon } from '@/components/ui/icon';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { ProofPhoto } from '@/models/delivery/delivery.model';
import { TRUCK_PHOTO_SLOTS, TruckPhotoSlot } from '@/models/trucker/trucker.model';

export type TruckPhotos = Partial<Record<TruckPhotoSlot, ProofPhoto>>;

/**
 * The six photographs Cargo Rush checks a truck from — front, both sides,
 * back, plate, and the engine if the trucker has it to hand.
 *
 * One tile per slot. Tapping an empty tile asks camera or gallery; tapping a
 * filled one offers to retake it. `optional` makes every slot optional, for
 * re-sending just the ones the office asked for.
 */
export function TruckPhotoGrid({
  photos,
  onChange,
  onError,
  optional = false,
}: {
  photos: TruckPhotos;
  onChange: (next: TruckPhotos) => void;
  onError: (message: string) => void;
  optional?: boolean;
}) {
  const [cameraPermission, requestCamera] = ImagePicker.useCameraPermissions();
  const [libraryPermission, requestLibrary] = ImagePicker.useMediaLibraryPermissions();

  const take = (slot: TruckPhotoSlot, result: ImagePicker.ImagePickerResult) => {
    const asset = result.canceled ? null : result.assets?.[0];

    if (!asset) return;

    onChange({
      ...photos,
      [slot]: {
        uri: asset.uri,
        // An asset from the camera often has no filename or MIME type.
        name: asset.fileName ?? `truck-${slot}.jpg`,
        type: asset.mimeType ?? 'image/jpeg',
      },
    });
  };

  const shoot = async (slot: TruckPhotoSlot) => {
    const granted = cameraPermission?.granted ?? (await requestCamera()).granted;

    if (!granted) {
      onError('Allow camera access to photograph the truck, or pick from the gallery instead.');

      return;
    }

    // Compressed: the office is checking the truck, not zooming in on paint.
    take(
      slot,
      await ImagePicker.launchCameraAsync({
        mediaTypes: 'images',
        quality: 0.6,
      }),
    );
  };

  const pick = async (slot: TruckPhotoSlot) => {
    const granted = libraryPermission?.granted ?? (await requestLibrary()).granted;

    if (!granted) {
      onError('Allow photo access to attach a picture, or use the camera instead.');

      return;
    }

    take(
      slot,
      await ImagePicker.launchImageLibraryAsync({
        mediaTypes: 'images',
        quality: 0.6,
      }),
    );
  };

  /**
   * The tile whose chooser is open.
   *
   * Its own modal rather than `Alert.alert`: on the web build an alert with buttons
   * is a no-op, so tapping a tile did nothing at all.
   */
  const [active, setActive] = useState<TruckPhotoSlot | null>(null);

  const run = (action: () => Promise<void> | void) => {
    setActive(null);
    void action();
  };

  const remove = (slot: TruckPhotoSlot) => {
    const next = { ...photos };
    delete next[slot];
    onChange(next);
  };

  const activeLabel = TRUCK_PHOTO_SLOTS.find((s) => s.slot === active)?.label;

  return (
    <View>
      <View style={styles.grid}>
        {TRUCK_PHOTO_SLOTS.map(({ slot, label, required }) => {
          const photo = photos[slot];
          const needed = required && !optional;

          return (
            <Pressable
              key={slot}
              accessibilityRole="button"
              accessibilityLabel={`${photo ? 'Change' : 'Add'} photo: ${label}${needed ? '' : ', optional'}`}
              onPress={() => setActive(active === slot ? null : slot)}
              style={({ pressed }) => [
                styles.tile,
                photo ? styles.filled : null,
                active === slot && styles.selected,
                pressed && { opacity: 0.7 },
              ]}>
              {photo ? (
                <>
                  <Image source={{ uri: photo.uri }} style={styles.image} contentFit="cover" />
                  <View style={styles.caption}>
                    <Icon name="check" size={12} color={Brand.surface} />
                    <Text style={styles.captionText}>{label}</Text>
                  </View>
                </>
              ) : (
                <>
                  <Icon name="camera" size={20} color={Brand.blue} />
                  <Text style={styles.label}>{label}</Text>
                  <Text style={styles.hint}>{needed ? 'Required' : 'Optional'}</Text>
                </>
              )}
            </Pressable>
          );
        })}
      </View>

      <Modal
        visible={active !== null}
        transparent
        animationType="fade"
        onRequestClose={() => setActive(null)}
        accessibilityViewIsModal>
        <View style={styles.modalRoot}>
          <Pressable
            style={styles.scrim}
            onPress={() => setActive(null)}
            accessibilityRole="button"
            accessibilityLabel="Close"
          />
          {active ? (
            <View style={styles.chooser}>
              <Text style={styles.chooserTitle}>{activeLabel} photo</Text>
              {photos[active] ? (
                <Image
                  source={{ uri: photos[active]!.uri }}
                  style={styles.chooserPreview}
                  contentFit="cover"
                />
              ) : null}
              <View style={styles.chooserRow}>
                <Pressable
                  accessibilityRole="button"
                  onPress={() => run(() => shoot(active))}
                  style={({ pressed }) => [styles.action, pressed && { backgroundColor: Brand.tint }]}>
                  <Icon name="camera" size={16} color={Brand.blue} />
                  <Text style={styles.actionText}>Take photo</Text>
                </Pressable>
                <Pressable
                  accessibilityRole="button"
                  onPress={() => run(() => pick(active))}
                  style={({ pressed }) => [styles.action, pressed && { backgroundColor: Brand.tint }]}>
                  <Icon name="clipboard" size={16} color={Brand.blue} />
                  <Text style={styles.actionText}>From gallery</Text>
                </Pressable>
              </View>
              <View style={styles.chooserRow}>
                {photos[active] ? (
                  <Pressable
                    accessibilityRole="button"
                    onPress={() => run(() => remove(active))}
                    style={styles.link}>
                    <Text style={[styles.linkText, { color: Brand.red }]}>Remove</Text>
                  </Pressable>
                ) : null}
                <Pressable accessibilityRole="button" onPress={() => setActive(null)} style={styles.link}>
                  <Text style={styles.linkText}>Cancel</Text>
                </Pressable>
              </View>
            </View>
          ) : null}
        </View>
      </Modal>
    </View>
  );
}

/** The slots still missing, by label — for the "fill these in" message. */
export function missingPhotos(photos: TruckPhotos): string[] {
  return TRUCK_PHOTO_SLOTS.filter((s) => s.required && !photos[s.slot]).map((s) => s.label.toLowerCase());
}

const styles = StyleSheet.create({
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.two,
    marginTop: 6,
  },
  tile: {
    width: '48%',
    height: 96,
    minHeight: Hit.min,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 2,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderStyle: 'dashed',
    borderColor: Brand.blue,
    overflow: 'hidden',
  },
  filled: { borderStyle: 'solid', borderColor: Brand.line },
  selected: { borderWidth: 2, borderStyle: 'solid', borderColor: Brand.blue },

  modalRoot: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: Spacing.four },
  scrim: {
    position: 'absolute',
    top: 0,
    right: 0,
    bottom: 0,
    left: 0,
    backgroundColor: 'rgba(31,31,31,0.5)',
  },
  chooser: {
    width: '100%',
    maxWidth: 360,
    padding: Spacing.four,
    gap: Spacing.three,
    borderRadius: Radius.panel,
    backgroundColor: Brand.surface,
  },
  chooserTitle: { fontSize: 16, fontWeight: '600', color: Brand.ink },
  chooserPreview: { width: '100%', height: 160, borderRadius: Radius.control, backgroundColor: Brand.tint },
  chooserRow: { flexDirection: 'row', justifyContent: 'flex-end', gap: Spacing.two },
  action: {
    flex: 1,
    minHeight: Hit.min,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.blue,
    backgroundColor: Brand.surface,
  },
  actionText: { fontSize: 13, fontWeight: '600', color: Brand.blue },
  link: {
    minHeight: Hit.min,
    justifyContent: 'center',
    paddingHorizontal: Spacing.two,
  },
  linkText: { fontSize: 13, fontWeight: '600', color: Brand.inkMuted },
  image: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 },
  caption: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    paddingHorizontal: Spacing.two,
    paddingVertical: 4,
    backgroundColor: 'rgba(31,31,31,0.6)',
  },
  captionText: { fontSize: 12, fontWeight: '600', color: Brand.surface },
  label: { fontSize: 13, fontWeight: '600', color: Brand.blue },
  hint: { fontSize: 11, color: Brand.inkMuted },
});

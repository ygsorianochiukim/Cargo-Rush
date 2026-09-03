import { Image } from 'expo-image';
import * as ImagePicker from 'expo-image-picker';
import { useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { ProofPhoto } from '@/models/delivery/delivery.model';
import { ApiRequestError } from '@/services/shared/api.service';
import { tripService } from '@/services/trip/trip.service';
import { Icon } from '@/components/ui/icon';
import { Sheet } from '@/components/ui/sheet';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';

/**
 * The hand-off, done from the cab — in transit → delivered.
 *
 * The status is not something the driver picks from a list. There is exactly
 * one move available while a run is on the road, and it is this one, so the
 * screen asks for the proof instead and lets the transition follow from it.
 *
 * **What it asks for changed, and that is the point.** It used to demand a
 * "proof of delivery reference" — a number the driver had no source for, and
 * therefore made up at the door. It looked like evidence and was not. The
 * reference is now assigned by the API, and this screen asks for the two
 * things a driver genuinely has:
 *
 *  - **A photograph** of the load where it was left, from the camera or the
 *    gallery. Optional, because signal and camera permissions at a warehouse
 *    gate are what they are, and a failed upload must not be a dead end.
 *  - **The name of whoever took it**, typed. Required — it is what makes the
 *    delivery attributable to a person, which is the job the invented
 *    reference was pretending to do.
 *
 * The typed name is a deliberate stand-in for a drawn signature, which needs a
 * canvas and a stroke format. When that arrives it replaces how this is
 * captured, not what it means.
 */
export function ProofOfDeliverySheet({
  open,
  onClose,
  onDelivered,
  reference,
  destination,
}: {
  open: boolean;
  onClose: () => void;
  /** Called after the API confirms, so the caller can refetch the trip. */
  onDelivered: () => void;
  reference: string;
  destination: string;
}) {
  const [receiver, setReceiver] = useState('');
  const [photo, setPhoto] = useState<ProofPhoto | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // The hooks own the permission state, so the screen can say what is wrong
  // instead of a picker silently returning nothing.
  const [cameraPermission, requestCamera] = ImagePicker.useCameraPermissions();
  const [libraryPermission, requestLibrary] = ImagePicker.useMediaLibraryPermissions();

  const trimmedName = receiver.trim();

  const close = () => {
    setReceiver('');
    setPhoto(null);
    setError(null);
    onClose();
  };

  /**
   * Turn a picked asset into the shape `FormData` wants.
   *
   * React Native has no `File`, so the multipart part is a URI plus a name and
   * a type. Both fall back: an asset from the camera often has no filename,
   * and a missing MIME type would make the API reject a perfectly good JPEG.
   */
  const take = (result: ImagePicker.ImagePickerResult) => {
    const asset = result.canceled ? null : result.assets?.[0];

    if (!asset) return;

    setPhoto({
      uri: asset.uri,
      name: asset.fileName ?? `pod-${reference}.jpg`,
      type: asset.mimeType ?? 'image/jpeg',
    });
    setError(null);
  };

  const shoot = async () => {
    const granted = cameraPermission?.granted ?? (await requestCamera()).granted;

    if (!granted) {
      setError('Allow camera access to photograph the load, or pick from the gallery instead.');

      return;
    }

    // Compressed on the way out: a full-resolution phone photo is several
    // megabytes over whatever signal a loading bay has, and the office is
    // reading it to see that the load arrived, not to zoom in on a label.
    take(await ImagePicker.launchCameraAsync({ mediaTypes: 'images', quality: 0.6 }));
  };

  const pick = async () => {
    const granted = libraryPermission?.granted ?? (await requestLibrary()).granted;

    if (!granted) {
      setError('Allow photo access to attach a picture, or use the camera instead.');

      return;
    }

    take(await ImagePicker.launchImageLibraryAsync({ mediaTypes: 'images', quality: 0.6 }));
  };

  const submit = () => {
    // Checked here as well as by the API so the driver is told before the
    // request goes out — a cab is the wrong place to wait on a round trip to
    // learn a field was blank.
    if (trimmedName === '') {
      setError('Enter the name of whoever received the load.');

      return;
    }

    setSaving(true);
    setError(null);

    tripService
      .deliver({ receiver_name: trimmedName, photo })
      .then(() => {
        setReceiver('');
        setPhoto(null);
        onDelivered();
        onClose();
      })
      .catch((e: Error) => {
        // A 422 here is the API refusing the transition — the run is no
        // longer in transit, usually because it was already handed over.
        // Its own wording is better than anything this screen can guess.
        setError(
          e instanceof ApiRequestError
            ? (e.fieldErrors.receiver_name?.[0] ?? e.fieldErrors.photo?.[0] ?? e.message)
            : e.message,
        );
      })
      .finally(() => setSaving(false));
  };

  return (
    <Sheet
      open={open}
      onClose={close}
      title="Mark delivered"
      subtitle={`${reference} · ${destination}`}
      icon="check"
      footer={
        <>
          {error ? (
            <Text style={styles.error} accessibilityLiveRegion="polite">
              {error}
            </Text>
          ) : null}
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Confirm delivery"
            disabled={saving}
            onPress={submit}
            style={[styles.save, saving && { opacity: 0.6 }]}>
            <Text style={styles.saveText}>{saving ? 'Sending…' : 'Confirm delivery'}</Text>
          </Pressable>
          <Pressable accessibilityRole="button" onPress={close} style={styles.cancel}>
            <Text style={styles.cancelText}>Cancel</Text>
          </Pressable>
        </>
      }>
      <ScrollView style={styles.scroll} keyboardShouldPersistTaps="handled">
        <Text style={styles.label}>PHOTO OF THE LOAD</Text>

        {photo ? (
          <View style={styles.preview}>
            <Image source={{ uri: photo.uri }} style={styles.previewImage} contentFit="cover" />
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Remove this photo"
              onPress={() => setPhoto(null)}
              style={styles.previewRemove}>
              <Icon name="close" size={16} color={Brand.surface} />
            </Pressable>
          </View>
        ) : (
          <View style={styles.captureRow}>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Take a photo of the delivered load"
              onPress={shoot}
              style={({ pressed }) => [styles.capture, pressed && { backgroundColor: Brand.tint }]}>
              <Icon name="camera" size={20} color={Brand.blue} />
              <Text style={styles.captureText}>Take photo</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Choose a photo from the gallery"
              onPress={pick}
              style={({ pressed }) => [styles.capture, pressed && { backgroundColor: Brand.tint }]}>
              <Icon name="clipboard" size={20} color={Brand.blue} />
              <Text style={styles.captureText}>From gallery</Text>
            </Pressable>
          </View>
        )}

        <Text style={[styles.label, { marginTop: Spacing.four }]}>RECEIVED BY</Text>
        <TextInput
          value={receiver}
          onChangeText={setReceiver}
          maxLength={120}
          autoCapitalize="words"
          placeholder="Name of the person signing"
          placeholderTextColor={Brand.inkMuted}
          accessibilityLabel="Name of the person who received the load"
          style={styles.input}
        />

        <View style={styles.hint}>
          <Icon name="check" size={14} color={Brand.blue} />
          <Text style={styles.hintText}>
            This closes the run and files its delivery log. The proof number is assigned for you.
          </Text>
        </View>
      </ScrollView>
    </Sheet>
  );
}

const styles = StyleSheet.create({
  scroll: { maxHeight: 380 },
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

  captureRow: { flexDirection: 'row', gap: Spacing.two, marginTop: 6 },
  capture: {
    flex: 1,
    minHeight: Hit.min + 12,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderStyle: 'dashed',
    borderColor: Brand.blue,
  },
  captureText: { fontSize: 13, fontWeight: '600', color: Brand.blue },

  preview: {
    marginTop: 6,
    height: 160,
    borderRadius: Radius.control,
    overflow: 'hidden',
    backgroundColor: Brand.tint,
  },
  previewImage: { width: '100%', height: '100%' },
  previewRemove: {
    position: 'absolute',
    top: Spacing.two,
    right: Spacing.two,
    width: 32,
    height: 32,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.full,
    backgroundColor: 'rgba(31,31,31,0.65)',
  },

  hint: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: Spacing.three },
  hintText: { flex: 1, fontSize: 12, color: Brand.inkMuted },

  save: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.control,
    backgroundColor: Brand.success,
  },
  saveText: { fontSize: 15, fontWeight: '600', color: Brand.surface },
  error: { marginBottom: Spacing.two, fontSize: 13, fontWeight: '500', color: Brand.red },
  cancel: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.control,
  },
  cancelText: { fontSize: 15, fontWeight: '600', color: Brand.ink },
});

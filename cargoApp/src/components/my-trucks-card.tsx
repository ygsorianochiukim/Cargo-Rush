import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import { Card, EmptyState, ErrorState, SkeletonRows, StatusPill } from '@/components/ui/primitives';
import { Sheet } from '@/components/ui/sheet';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { useApi } from '@/hooks/use-api';
import { TruckerVehicle } from '@/models/trucker/trucker.model';
import { ApiRequestError } from '@/services/shared/api.service';
import { truckerService } from '@/services/trucker/trucker.service';

/**
 * A partner's trucks — every one of them, and the way to add another.
 *
 * Sign-up asks for no truck: they are added here, once the fleet has approved
 * the account — until then the card says so rather than offering a button the
 * API will refuse. A run goes under the first truck marked available, so the
 * switch on each row is how a partner keeps a unit that is in the shop off the
 * board without deleting it.
 */
export function MyTrucksCard({ approved }: { approved: boolean }) {
  const trucks = useApi(() => truckerService.vehicles(), []);

  const [adding, setAdding] = useState(false);
  /** The row whose switch is mid-request, so it cannot be pressed twice. */
  const [switching, setSwitching] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const list = trucks.data ?? [];
  const running = list.filter((t) => t.status === 'available').length;

  const toggle = async (truck: TruckerVehicle) => {
    if (switching) return;

    setSwitching(truck.id);
    setFailure(null);

    try {
      await truckerService.saveVehicle(
        { status: truck.status === 'available' ? 'maintenance' : 'available' },
        truck.id,
      );
      trucks.reload();
    } catch (error) {
      setFailure(messageFor(error));
    } finally {
      setSwitching(null);
    }
  };

  return (
    <Card
      heading="My trucks"
      icon="fleet"
      hint={trucks.data ? `${running} of ${list.length} running` : undefined}
      padded={false}>
      {trucks.loading && trucks.data === null ? (
        <View style={{ padding: Spacing.three }}>
          <SkeletonRows count={2} />
        </View>
      ) : trucks.error ? (
        <ErrorState message="Could not load your trucks." onRetry={trucks.reload} />
      ) : list.length === 0 ? (
        <EmptyState
          icon="fleet"
          title="No trucks yet"
          body={
            approved
              ? 'Add a truck so the fleet knows what you can carry.'
              : 'You can add your trucks once the fleet approves your account.'
          }
        />
      ) : (
        list.map((truck, i) => {
          const available = truck.status === 'available';

          return (
            <View key={truck.id} style={[styles.row, i < list.length - 1 && styles.divider]}>
              <View style={{ flex: 1, minWidth: 0, gap: 3 }}>
                <Text style={styles.plate}>{truck.plate}</Text>
                <Text style={styles.sub} numberOfLines={1}>
                  {truck.model} · {truck.capacity_kg.toLocaleString()} kg
                </Text>
                <StatusPill status={truck.status} />
              </View>
              {approved ? (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={
                  available ? `Mark ${truck.plate} as in the shop` : `Put ${truck.plate} back on the road`
                }
                disabled={switching !== null}
                onPress={() => void toggle(truck)}
                style={({ pressed }) => [
                  styles.switch,
                  pressed && { backgroundColor: Brand.tint },
                  switching !== null && { opacity: 0.5 },
                ]}>
                {switching === truck.id ? (
                  <ActivityIndicator color={Brand.blue} />
                ) : (
                  <Text style={styles.switchText}>{available ? 'In the shop' : 'Back on road'}</Text>
                )}
              </Pressable>
              ) : null}
            </View>
          );
        })
      )}

      {failure ? (
        <Text style={styles.failure} accessibilityRole="alert">
          {failure}
        </Text>
      ) : null}

      {approved ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Add a truck"
          onPress={() => setAdding(true)}
          style={({ pressed }) => [styles.add, pressed && { backgroundColor: Brand.tint }]}>
          <Text style={styles.addText}>+ Add a truck</Text>
        </Pressable>
      ) : null}

      {adding ? (
        <AddTruckSheet
          onClose={() => setAdding(false)}
          onAdded={() => {
            setAdding(false);
            trucks.reload();
          }}
        />
      ) : null}
    </Card>
  );
}

/** Plate, model and working load. */
function AddTruckSheet({ onClose, onAdded }: { onClose: () => void; onAdded: () => void }) {
  const [plate, setPlate] = useState('');
  const [model, setModel] = useState('');
  const [capacity, setCapacity] = useState('');

  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const submit = async () => {
    // Once only: a second tap while the first is in flight is a second truck.
    if (busy) return;

    if (!plate.trim() || !model.trim() || !Number(capacity)) {
      setFailure('Fill in the plate, the model and what it can carry.');

      return;
    }

    setBusy(true);
    setFailure(null);
    setFieldErrors({});

    try {
      await truckerService.saveVehicle({
        plate: plate.trim().toUpperCase(),
        model: model.trim(),
        capacity_kg: Number(capacity),
      });
      onAdded();
    } catch (error) {
      if (error instanceof ApiRequestError) setFieldErrors(error.fieldErrors);
      setFailure(messageFor(error));
      setBusy(false);
    }
  };

  return (
    <Sheet
      open
      onClose={onClose}
      title="Add a truck"
      subtitle="What it can carry decides which jobs you are offered."
      icon="fleet"
      footer={
        <>
          <Pressable
            accessibilityRole="button"
            accessibilityState={{ disabled: busy }}
            disabled={busy}
            onPress={() => void submit()}
            style={[styles.confirm, busy && { opacity: 0.5 }]}>
            {busy ? (
              <ActivityIndicator color={Brand.surface} />
            ) : (
              <Text style={styles.confirmText}>Add truck</Text>
            )}
          </Pressable>
          <Pressable accessibilityRole="button" onPress={onClose} style={styles.cancel}>
            <Text style={styles.cancelText}>Cancel</Text>
          </Pressable>
        </>
      }>
      {failure ? (
        <Text style={styles.failure} accessibilityRole="alert">
          {failure}
        </Text>
      ) : null}

      <Field
        label="PLATE"
        value={plate}
        onChange={setPlate}
        placeholder="ABC-1234"
        autoCapitalize="characters"
        error={fieldErrors['plate']?.[0]}
      />
      <Field
        label="MAKE AND MODEL"
        value={model}
        onChange={setModel}
        placeholder="e.g. Isuzu Forward"
        error={fieldErrors['model']?.[0]}
      />
      <Field
        label="CAPACITY (KG)"
        value={capacity}
        onChange={setCapacity}
        placeholder="12000"
        keyboard="number-pad"
        error={fieldErrors['capacity_kg']?.[0]}
      />
    </Sheet>
  );
}

function Field({
  label,
  value,
  onChange,
  placeholder,
  keyboard,
  autoCapitalize,
  error,
}: {
  label: string;
  value: string;
  onChange: (next: string) => void;
  placeholder: string;
  keyboard?: 'default' | 'number-pad';
  autoCapitalize?: 'none' | 'sentences' | 'characters';
  error?: string;
}) {
  return (
    <View style={{ marginTop: Spacing.three }}>
      <Text style={styles.label}>{label}</Text>
      <TextInput
        value={value}
        onChangeText={onChange}
        placeholder={placeholder}
        placeholderTextColor={Brand.inkMuted}
        keyboardType={keyboard ?? 'default'}
        autoCapitalize={autoCapitalize ?? 'sentences'}
        autoCorrect={false}
        accessibilityLabel={label}
        style={[styles.input, error ? { borderColor: Brand.red } : null]}
      />
      {error ? <Text style={styles.fieldError}>{error}</Text> : null}
    </View>
  );
}

function messageFor(error: unknown): string {
  if (error instanceof ApiRequestError) {
    if (error.status === 422) {
      return Object.values(error.fieldErrors)[0]?.[0] ?? error.body.message ?? 'That was not accepted.';
    }

    return error.body.message || `The server refused that request (${error.status}).`;
  }

  return 'Cannot reach the server. Check your signal and try again.';
}

const styles = StyleSheet.create({
  divider: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: Brand.line },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    minHeight: Hit.rowTwoLine,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two + 2,
  },
  plate: { fontSize: 14, fontWeight: '600', color: Brand.ink, fontVariant: ['tabular-nums'] },
  sub: { fontSize: 12, color: Brand.inkMuted },

  switch: {
    minHeight: Hit.min,
    minWidth: 104,
    paddingHorizontal: Spacing.two + 2,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
  },
  switchText: { fontSize: 13, fontWeight: '600', color: Brand.blue },

  add: {
    minHeight: Hit.min,
    alignItems: 'center',
    justifyContent: 'center',
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Brand.line,
  },
  addText: { fontSize: 14, fontWeight: '600', color: Brand.blue },

  failure: {
    marginHorizontal: Spacing.three,
    marginVertical: Spacing.two,
    padding: Spacing.two + 2,
    borderRadius: Radius.control,
    backgroundColor: Brand.redBg,
    color: Brand.red,
    fontSize: 13,
    fontWeight: '500',
  },

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

  confirm: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.control,
    backgroundColor: Brand.blue,
  },
  confirmText: { fontSize: 15, fontWeight: '600', color: Brand.surface },
  cancel: { minHeight: 48, alignItems: 'center', justifyContent: 'center', borderRadius: Radius.control },
  cancelText: { fontSize: 15, fontWeight: '600', color: Brand.ink },
});

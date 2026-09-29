import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import { Card, EmptyState, ErrorState, SkeletonRows, StatusPill } from '@/components/ui/primitives';
import { Sheet } from '@/components/ui/sheet';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { useApi } from '@/hooks/use-api';
import { TruckerDriver } from '@/models/trucker/trucker.model';
import { ApiRequestError } from '@/services/shared/api.service';
import { truckerService } from '@/services/trucker/trucker.service';

/**
 * A trucker's own drivers — every one of them, and the way to add another.
 *
 * Each driver added here gets a login of their own and sees only the runs the
 * owner hands them on My Trips. They are the owner's, never Cargo Rush's, and
 * every row says so under the name. Adding waits for the fleet's approval,
 * like adding trucks.
 */
export function MyDriversCard({ approved, business }: { approved: boolean; business: string }) {
  const drivers = useApi(() => truckerService.drivers(), []);

  const [adding, setAdding] = useState(false);
  /** The driver whose details are open for correcting, or removing. */
  const [editing, setEditing] = useState<TruckerDriver | null>(null);
  const [switching, setSwitching] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const list = drivers.data ?? [];
  const active = list.filter((d) => d.status === 'active').length;

  const toggle = async (driver: TruckerDriver) => {
    if (switching) return;

    setSwitching(driver.id);
    setFailure(null);

    try {
      await truckerService.setDriverStatus(driver.id, driver.status === 'active' ? 'inactive' : 'active');
      drivers.reload();
    } catch (error) {
      setFailure(messageFor(error));
    } finally {
      setSwitching(null);
    }
  };

  return (
    <Card
      heading="My drivers"
      icon="profile"
      hint={drivers.data ? `${active} of ${list.length} active` : undefined}
      padded={false}>
      {drivers.loading && drivers.data === null ? (
        <View style={{ padding: Spacing.three }}>
          <SkeletonRows count={2} />
        </View>
      ) : drivers.error ? (
        <ErrorState message="Could not load your drivers." onRetry={drivers.reload} />
      ) : list.length === 0 ? (
        <EmptyState
          icon="profile"
          title="No drivers yet"
          body={
            approved
              ? 'Add a driver and they get their own login to run the trips you hand them.'
              : 'You can add your drivers once the fleet approves your account.'
          }
        />
      ) : (
        list.map((driver, i) => {
          const on = driver.status === 'active';

          return (
            <View key={driver.id} style={[styles.row, i < list.length - 1 && styles.divider]}>
              <View style={{ flex: 1, minWidth: 0, gap: 3 }}>
                <Text style={styles.name}>{driver.name}</Text>
                <Text style={styles.sub} numberOfLines={1}>
                  {driver.licence_no}
                  {driver.email ? ` · ${driver.email}` : ''}
                </Text>
                {/* Who they drive for, on every row — never mistaken for Cargo Rush's. */}
                <Text style={styles.employer} numberOfLines={1}>
                  {driver.employer?.label ?? business}
                </Text>
                <StatusPill status={driver.status} />
              </View>
              {approved ? (
                <View style={{ gap: Spacing.two }}>
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={on ? `Stand ${driver.name} down` : `Put ${driver.name} back on`}
                  disabled={switching !== null}
                  onPress={() => void toggle(driver)}
                  style={({ pressed }) => [
                    styles.switch,
                    pressed && { backgroundColor: Brand.tint },
                    switching !== null && { opacity: 0.5 },
                  ]}>
                  {switching === driver.id ? (
                    <ActivityIndicator color={Brand.blue} />
                  ) : (
                    <Text style={styles.switchText}>{on ? 'Stand down' : 'Put back on'}</Text>
                  )}
                </Pressable>
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={`Edit ${driver.name}`}
                  onPress={() => setEditing(driver)}
                  style={({ pressed }) => [styles.switch, pressed && { backgroundColor: Brand.tint }]}>
                  <Text style={styles.switchText}>Edit</Text>
                </Pressable>
                </View>
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
          accessibilityLabel="Add a driver"
          onPress={() => setAdding(true)}
          style={({ pressed }) => [styles.add, pressed && { backgroundColor: Brand.tint }]}>
          <Text style={styles.addText}>+ Add a driver</Text>
        </Pressable>
      ) : null}

      {editing ? (
        <EditDriverSheet
          driver={editing}
          onClose={() => setEditing(null)}
          onDone={() => {
            setEditing(null);
            drivers.reload();
          }}
        />
      ) : null}

      {adding ? (
        <AddDriverSheet
          business={business}
          onClose={() => setAdding(false)}
          onAdded={() => {
            setAdding(false);
            drivers.reload();
          }}
        />
      ) : null}
    </Card>
  );
}

/**
 * Correct a driver's name, phone or licence — or take them off the books.
 *
 * Removing asks twice: it ends their login straight away and hands every run
 * they have not started back to the owner. The login email is not editable;
 * it is theirs.
 */
function EditDriverSheet({
  driver,
  onClose,
  onDone,
}: {
  driver: TruckerDriver;
  onClose: () => void;
  onDone: () => void;
}) {
  const [name, setName] = useState(driver.name);
  const [phone, setPhone] = useState(driver.phone ?? '');
  const [licence, setLicence] = useState(driver.licence_no);
  const [confirmRemove, setConfirmRemove] = useState(false);

  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const run = async (work: () => Promise<unknown>) => {
    if (busy) return;

    setBusy(true);
    setFailure(null);
    setFieldErrors({});

    try {
      await work();
      onDone();
    } catch (error) {
      if (error instanceof ApiRequestError) setFieldErrors(error.fieldErrors);
      setFailure(messageFor(error));
      setBusy(false);
    }
  };

  const save = () => {
    if (!name.trim() || !licence.trim()) {
      setFailure('A driver needs a name and a licence number.');

      return;
    }

    void run(() =>
      truckerService.updateDriver(driver.id, {
        name: name.trim(),
        phone: phone.trim() || null,
        licence_no: licence.trim().toUpperCase(),
      }),
    );
  };

  return (
    <Sheet
      open
      onClose={onClose}
      title={confirmRemove ? `Remove ${driver.name}?` : 'Edit driver'}
      subtitle={
        confirmRemove
          ? 'Their login stops working now, and any run they have not started comes back to you.'
          : driver.email ?? undefined
      }
      icon={confirmRemove ? 'incident' : 'profile'}
      danger={confirmRemove}
      footer={
        confirmRemove ? (
          <>
            <Pressable
              accessibilityRole="button"
              disabled={busy}
              onPress={() => void run(() => truckerService.removeDriver(driver.id))}
              style={[styles.danger, busy && { opacity: 0.5 }]}>
              <Text style={styles.confirmText}>{busy ? 'Removing…' : 'Remove driver'}</Text>
            </Pressable>
            <Pressable accessibilityRole="button" onPress={() => setConfirmRemove(false)} style={styles.cancel}>
              <Text style={styles.cancelText}>Keep them</Text>
            </Pressable>
          </>
        ) : (
          <>
            <Pressable
              accessibilityRole="button"
              disabled={busy}
              onPress={save}
              style={[styles.confirm, busy && { opacity: 0.5 }]}>
              {busy ? <ActivityIndicator color={Brand.surface} /> : <Text style={styles.confirmText}>Save</Text>}
            </Pressable>
            <Pressable accessibilityRole="button" onPress={() => setConfirmRemove(true)} style={styles.cancel}>
              <Text style={styles.removeText}>Remove this driver</Text>
            </Pressable>
          </>
        )
      }>
      {failure ? (
        <Text style={styles.failure} accessibilityRole="alert">
          {failure}
        </Text>
      ) : null}

      {confirmRemove ? null : (
        <>
          <Field label="NAME" value={name} onChange={setName} placeholder="Name" autoCapitalize="words" error={fieldErrors['name']?.[0]} />
          <Field label="CONTACT NUMBER (OPTIONAL)" value={phone} onChange={setPhone} placeholder="0917 000 1111" keyboard="phone-pad" error={fieldErrors['phone']?.[0]} />
          <Field label="LICENCE NUMBER" value={licence} onChange={setLicence} placeholder="N01-23-456789" autoCapitalize="characters" error={fieldErrors['licence_no']?.[0]} />
        </>
      )}
    </Sheet>
  );
}

/** Who they are, their licence, and the login they will sign in with. */
function AddDriverSheet({
  business,
  onClose,
  onAdded,
}: {
  business: string;
  onClose: () => void;
  onAdded: () => void;
}) {
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [licence, setLicence] = useState('');
  const [email, setEmail] = useState('');
  const [secret, setSecret] = useState('');

  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const submit = async () => {
    if (busy) return;

    if (!name.trim() || !licence.trim() || !email.trim()) {
      setFailure('Fill in the name, the licence number and an email for their login.');

      return;
    }

    if (secret.length < 8) {
      setFailure('Give them a password of at least 8 characters.');

      return;
    }

    setBusy(true);
    setFailure(null);
    setFieldErrors({});

    try {
      await truckerService.addDriver({
        name: name.trim(),
        phone: phone.trim() || undefined,
        licence_no: licence.trim().toUpperCase(),
        email: email.trim(),
        password: secret,
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
      title="Add a driver"
      subtitle={`They will drive for ${business}, and sign in with this email and password.`}
      icon="profile"
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
              <Text style={styles.confirmText}>Add driver</Text>
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

      <Field label="NAME" value={name} onChange={setName} placeholder="e.g. Nonoy Bacalso" autoCapitalize="words" error={fieldErrors['name']?.[0]} />
      <Field label="CONTACT NUMBER (OPTIONAL)" value={phone} onChange={setPhone} placeholder="0917 000 1111" keyboard="phone-pad" error={fieldErrors['phone']?.[0]} />
      <Field label="LICENCE NUMBER" value={licence} onChange={setLicence} placeholder="N01-23-456789" autoCapitalize="characters" error={fieldErrors['licence_no']?.[0]} />
      <Field label="LOGIN EMAIL" value={email} onChange={setEmail} placeholder="driver@example.ph" keyboard="email-address" autoCapitalize="none" error={fieldErrors['email']?.[0]} />
      <Field label="PASSWORD" value={secret} onChange={setSecret} placeholder="At least 8 characters" secure autoCapitalize="none" error={fieldErrors['password']?.[0]} />
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
  secure,
  error,
}: {
  label: string;
  value: string;
  onChange: (next: string) => void;
  placeholder: string;
  keyboard?: 'default' | 'phone-pad' | 'email-address';
  autoCapitalize?: 'none' | 'sentences' | 'words' | 'characters';
  secure?: boolean;
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
        secureTextEntry={secure}
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
  name: { fontSize: 14, fontWeight: '600', color: Brand.ink },
  sub: { fontSize: 12, color: Brand.inkMuted, fontVariant: ['tabular-nums'] },
  employer: { fontSize: 11, fontWeight: '600', color: Brand.blue },

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
  removeText: { fontSize: 15, fontWeight: '600', color: Brand.red },
  danger: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.control,
    backgroundColor: Brand.red,
  },
});

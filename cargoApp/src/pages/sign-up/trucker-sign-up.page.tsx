import { useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Icon } from '@/components/ui/icon';
import { Wordmark } from '@/components/ui/wordmark';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { useSession } from '@/services/identity/session';
import { apiBaseUrl, ApiRequestError } from '@/services/shared/api.service';

/**
 * Sign up as a trucker — somebody with their own truck, looking for loads.
 *
 * The third way into this app and the one that asks for the most, which is
 * proportionate rather than unfriendly: a bad customer sign-up wastes an
 * afternoon, and a bad trucker sign-up is a stranger driving away with
 * somebody's cargo.
 *
 * ## Why it does not ask which fleet
 *
 * It used to, off the public carrier directory, and that was the wrong question
 * twice over: it asked somebody with a truck to pick a haulier before they had
 * any basis to, and it implied a choice the business does not offer. A trucker
 * registers with Cargo Rush wherever in the country they are, and Cargo Rush
 * vets them.
 *
 * Choosing happens on the **customer's** side instead, per load, between the
 * fleet and whichever vetted truckers are near the pickup. That is the right
 * place for it: a customer decides weekly with the load in front of them, while
 * a partner's relationship is a standing one with a rate and a running balance,
 * and there is exactly one firm holding the other end of it.
 *
 * ## What registering does and does not buy
 *
 * It does not make somebody usable. The account lands **pending** and the job
 * board stays empty until a human at the fleet has read the licence and said
 * yes. The screen says so in the last line, before the button, because
 * discovering it afterwards is how an app gets deleted.
 */
export function TruckerSignUpPage({ onBack }: { onBack: () => void }) {
  const insets = useSafeAreaInsets();
  const { registerTrucker } = useSession();

  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [secret, setSecret] = useState('');
  const [confirmation, setConfirmation] = useState('');

  const [licence, setLicence] = useState('');
  const [plate, setPlate] = useState('');
  const [model, setModel] = useState('');
  const [capacity, setCapacity] = useState('');

  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const submit = async () => {
    if (busy) return;

    // Checked here as well as by the API, so somebody is told which field is
    // missing before a round trip rather than after one.
    if (!name.trim() || !phone.trim() || !email.trim()) {
      setFailure('Fill in your name, your number and your email address.');

      return;
    }

    if (!licence.trim()) {
      setFailure('Your licence number is what the fleet checks before approving you.');

      return;
    }

    if (!plate.trim() || !model.trim() || !Number(capacity)) {
      setFailure('Add your truck — the plate, the model and what it can carry.');

      return;
    }

    if (secret.length < 8) {
      setFailure('Use a password of at least 8 characters.');

      return;
    }

    if (secret !== confirmation) {
      setFailure('The two passwords do not match.');

      return;
    }

    setBusy(true);
    setFailure(null);
    setFieldErrors({});

    try {
      await registerTrucker({
        name: name.trim(),
        contact_phone: phone.trim(),
        email: email.trim(),
        password: secret,
        password_confirmation: confirmation,
        // No fleet. A trucker registers with Cargo Rush wherever in the country
        // they are, and the API resolves it — see the API's registration
        // service for why this is not a choice the form should offer.
        licence_no: licence.trim(),
        plate: plate.trim().toUpperCase(),
        model: model.trim(),
        capacity_kg: Number(capacity),
      });
      // No `setBusy(false)` on success: the app replaces this screen, and
      // re-enabling a button on an unmounting form is a warning for nothing.
    } catch (error) {
      if (error instanceof ApiRequestError) setFieldErrors(error.fieldErrors);
      setFailure(messageFor(error));
      setBusy(false);
    }
  };

  return (
    <KeyboardAvoidingView
      style={styles.root}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView
        contentContainerStyle={[
          styles.scroll,
          { paddingTop: insets.top + Spacing.four, paddingBottom: insets.bottom + Spacing.six },
        ]}
        keyboardShouldPersistTaps="handled">
        <View style={styles.brand}>
          <Wordmark size={24} />
        </View>

        <Pressable onPress={onBack} accessibilityRole="button" style={styles.backBtn}>
          <Icon name="chevron-left" size={16} color={Brand.blue} />
          <Text style={styles.backText}>Back to sign in</Text>
        </Pressable>

        <View style={styles.card}>
          <Text style={styles.heading}>Register your truck</Text>
          <Text style={styles.sub}>
            Take loads from a fleet near you, or find your own through the app. You keep what
            you earn less the fleet&apos;s share, and your wallet shows every peso of it.
          </Text>

          {failure ? (
            <Text style={styles.failure} accessibilityLiveRegion="polite" accessibilityRole="alert">
              {failure}
            </Text>
          ) : null}

          <Text style={styles.section}>YOUR DETAILS</Text>

          <Field
            label="YOUR NAME"
            value={name}
            onChange={setName}
            placeholder="e.g. Boyet Aquino"
            error={fieldErrors['name']?.[0]}
          />

          <Field
            label="CONTACT NUMBER"
            value={phone}
            onChange={setPhone}
            placeholder="0917 000 1111"
            keyboard="phone-pad"
            hint="Dispatch rings this about a load. Required — you are not down the corridor."
            error={fieldErrors['contact_phone']?.[0]}
          />

          <Field
            label="LICENCE NUMBER"
            value={licence}
            onChange={setLicence}
            placeholder="N01-23-456789"
            autoCapitalize="characters"
            hint="What the fleet checks before approving you."
            error={fieldErrors['licence_no']?.[0]}
          />

          <Text style={styles.section}>YOUR TRUCK</Text>
          <Text style={styles.sectionHint}>
            What it can carry decides which jobs you are offered, so get the weight right. You
            can add more trucks later.
          </Text>

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
            hint="The working load, in kilograms."
            error={fieldErrors['capacity_kg']?.[0]}
          />

          <Text style={styles.section}>YOUR LOGIN</Text>

          <Field
            label="EMAIL"
            value={email}
            onChange={setEmail}
            placeholder="you@example.ph"
            keyboard="email-address"
            autoCapitalize="none"
            error={fieldErrors['email']?.[0]}
          />

          <Field
            label="PASSWORD"
            value={secret}
            onChange={setSecret}
            placeholder="••••••••"
            secure
            hint="At least 8 characters."
            error={fieldErrors['password']?.[0]}
          />

          <Field
            label="CONFIRM PASSWORD"
            value={confirmation}
            onChange={setConfirmation}
            placeholder="••••••••"
            secure
          />

          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Register"
            accessibilityState={{ disabled: busy }}
            disabled={busy}
            onPress={submit}
            style={({ pressed }) => [
              styles.submit,
              pressed && { backgroundColor: Brand.blueHover },
              busy && { opacity: 0.5 },
            ]}>
            {busy ? (
              <ActivityIndicator color={Brand.surface} />
            ) : (
              <Text style={styles.submitText}>Register</Text>
            )}
          </Pressable>

          {/*
            Said before the button, not after it.

            The account lands pending and the board is empty until somebody at
            the fleet approves it. Discovering that afterwards, on a screen with
            nothing on it, is how an app gets deleted.
          */}
          <Text style={styles.footnote}>
            The fleet checks your licence and your truck before you can take work. You will get
            a notification as soon as you are approved — usually the same day.
          </Text>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

function Field({
  label,
  value,
  onChange,
  placeholder,
  keyboard,
  secure,
  autoCapitalize,
  hint,
  error,
}: {
  label: string;
  value: string;
  onChange: (next: string) => void;
  placeholder: string;
  keyboard?: 'default' | 'phone-pad' | 'email-address' | 'number-pad';
  secure?: boolean;
  autoCapitalize?: 'none' | 'sentences' | 'words' | 'characters';
  hint?: string;
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
        secureTextEntry={secure}
        autoCapitalize={autoCapitalize ?? 'sentences'}
        autoCorrect={false}
        accessibilityLabel={label}
        style={[styles.input, error ? { borderColor: Brand.red } : null]}
      />
      {error ? (
        <Text style={styles.fieldError} accessibilityLiveRegion="polite">
          {error}
        </Text>
      ) : hint ? (
        <Text style={styles.hint}>{hint}</Text>
      ) : null}
    </View>
  );
}

/**
 * A rejected detail and an unreachable server are different problems.
 *
 * The 422 is read field by field rather than summarised, because the server is
 * the only thing that knows some of these rules — that the address already has
 * an account, or that this licence is already registered with that fleet — and
 * "check your details" would hide the one sentence that fixes it.
 */
function messageFor(error: unknown): string {
  if (error instanceof ApiRequestError) {
    if (error.status === 422) {
      const first = Object.values(error.fieldErrors)[0]?.[0];

      return first ?? error.body.message ?? 'Some of those details were not accepted.';
    }

    if (error.status === 404) {
      return 'That fleet could not be found. Pick another one from the list.';
    }

    if (error.status === 429) {
      return 'Too many attempts from this connection. Try again in a little while.';
    }

    if (error.status >= 500) {
      return 'The server hit an error handling that. Try again shortly.';
    }

    return error.body.message || `The server refused that request (${error.status}).`;
  }

  return `Cannot reach ${apiBaseUrl}. Check your signal, and that the server is running.`;
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: Brand.tint },
  scroll: { flexGrow: 1, paddingHorizontal: Spacing.three },
  brand: { alignItems: 'center', marginBottom: Spacing.three },

  backBtn: {
    alignSelf: 'flex-start',
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    minHeight: Hit.min,
    paddingRight: Spacing.two,
  },
  backText: { fontSize: 14, fontWeight: '600', color: Brand.blue },

  card: {
    backgroundColor: Brand.surface,
    borderRadius: Radius.panel,
    padding: Spacing.four,
    shadowColor: '#000',
    shadowOpacity: 0.1,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 2 },
    elevation: 3,
  },

  heading: { fontSize: 18, fontWeight: '700', color: Brand.ink },
  sub: { marginTop: 4, fontSize: 14, lineHeight: 20, color: Brand.inkMuted },

  failure: {
    marginTop: Spacing.three,
    padding: Spacing.two + 2,
    borderRadius: Radius.control,
    backgroundColor: Brand.redBg,
    color: Brand.red,
    fontSize: 13,
    fontWeight: '500',
  },

  section: {
    marginTop: Spacing.five,
    fontSize: 11,
    fontWeight: '700',
    letterSpacing: 0.8,
    color: Brand.blue,
  },
  sectionHint: { marginTop: 4, fontSize: 12, lineHeight: 17, color: Brand.inkMuted },

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
  hint: { marginTop: 4, fontSize: 12, lineHeight: 17, color: Brand.inkMuted },
  fieldError: { marginTop: 4, fontSize: 12, fontWeight: '500', color: Brand.red },

  submit: {
    marginTop: Spacing.five,
    height: 48,
    borderRadius: Radius.control,
    backgroundColor: Brand.blue,
    alignItems: 'center',
    justifyContent: 'center',
  },
  submitText: { color: Brand.surface, fontSize: 15, fontWeight: '600' },

  footnote: {
    marginTop: Spacing.three,
    fontSize: 12,
    lineHeight: 17,
    color: Brand.inkMuted,
    textAlign: 'center',
  },
});

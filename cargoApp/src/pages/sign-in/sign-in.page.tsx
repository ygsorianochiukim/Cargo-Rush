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
import { SignUpPage } from '@/pages/sign-up/sign-up.page';
import { apiBaseUrl, ApiRequestError } from '@/services/shared/api.service';
import { useSession } from '@/services/identity/session';

/**
 * Sign in.
 *
 * Shown instead of the tabs when there is no session, rather than as a screen
 * inside them: a driver who is not signed in has no dashboard to go back to,
 * and a tab bar over a locked app is a set of dead ends.
 *
 * It holds the sign-up screen rather than routing to it, for the same reason.
 * `expo-router` is not mounted until there is a session — the tabs *are* the
 * router — so the two unauthenticated screens swap here, on one piece of local
 * state. Which also gets the back button right for free: there is nowhere else
 * to go back to.
 */
export function SignInPage() {
  const insets = useSafeAreaInsets();
  const { signIn } = useSession();

  /**
   * Signing in, or signing up.
   *
   * Drivers and office staff only ever see the first: their accounts are made
   * for them. The second signs up a **customer** — somebody who has arrived at
   * the app with a load and no haulier, which is the one account this platform
   * lets a person create for themselves. Registering a *company* is the web's
   * job, and is nothing like the same form.
   */
  const [signingUp, setSigningUp] = useState(false);

  const [email, setEmail] = useState('');
  const [secret, setSecret] = useState('');
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);

  /**
   * Stay signed in after the app is closed.
   *
   * Ticked by default, and that default is the point: a driver signs in once
   * and the app opens on their work every morning after, because a password
   * prompt at the start of a shift, in a cab, is exactly the friction that
   * gets an app put down. Unticking it is for the handset that gets passed
   * around a yard — that session ends with the app, and the token with it.
   */
  const [remember, setRemember] = useState(true);

  const ready = email.trim().length > 0 && secret.length > 0;

  if (signingUp) return <SignUpPage onBack={() => setSigningUp(false)} />;

  const submit = async () => {
    if (!ready || busy) return;

    setBusy(true);
    setFailure(null);

    try {
      await signIn({ email: email.trim(), password: secret }, remember);
    } catch (error) {
      setFailure(messageFor(error));
      setBusy(false);
    }
    // No `setBusy(false)` on success: the tabs replace this screen, and
    // re-enabling a button on an unmounting form is a warning for nothing.
  };

  return (
    <KeyboardAvoidingView
      style={styles.root}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView
        contentContainerStyle={[
          styles.scroll,
          { paddingTop: insets.top + Spacing.six, paddingBottom: insets.bottom + Spacing.six },
        ]}
        keyboardShouldPersistTaps="handled">
        <View style={styles.brand}>
          <Wordmark size={26} />
        </View>

        <View style={styles.card}>
          <Text style={styles.heading}>Sign in</Text>
          <Text style={styles.sub}>Use the account the office set up for you.</Text>

          {failure ? (
            <Text style={styles.failure} accessibilityLiveRegion="polite" accessibilityRole="alert">
              {failure}
            </Text>
          ) : null}

          <Text style={styles.label}>EMAIL</Text>
          <TextInput
            value={email}
            onChangeText={setEmail}
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            textContentType="username"
            placeholder="you@cargorush.ph"
            placeholderTextColor={Brand.inkMuted}
            accessibilityLabel="Email address"
            style={styles.input}
          />

          <Text style={[styles.label, { marginTop: Spacing.three }]}>PASSWORD</Text>
          <TextInput
            value={secret}
            onChangeText={setSecret}
            secureTextEntry
            textContentType="password"
            placeholder="••••••••"
            placeholderTextColor={Brand.inkMuted}
            accessibilityLabel="Password"
            returnKeyType="go"
            onSubmitEditing={submit}
            style={styles.input}
          />

          <Pressable
            accessibilityRole="checkbox"
            accessibilityLabel="Remember me"
            accessibilityHint="Stay signed in on this phone after closing the app"
            accessibilityState={{ checked: remember }}
            onPress={() => setRemember((on) => !on)}
            hitSlop={Spacing.two}
            style={styles.remember}>
            <View style={[styles.box, remember && styles.boxOn]}>
              {remember ? <Icon name="check" size={13} color={Brand.surface} /> : null}
            </View>
            <View style={styles.rememberCopy}>
              <Text style={styles.rememberLabel}>Remember me</Text>
              <Text style={styles.rememberHint}>
                Stay signed in on this phone. Leave it off on a shared handset.
              </Text>
            </View>
          </Pressable>

          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Sign in"
            accessibilityState={{ disabled: !ready || busy }}
            disabled={!ready || busy}
            onPress={submit}
            style={({ pressed }) => [
              styles.submit,
              pressed && { backgroundColor: Brand.blueHover },
              (!ready || busy) && { opacity: 0.5 },
            ]}>
            {busy ? (
              <ActivityIndicator color={Brand.surface} />
            ) : (
              <Text style={styles.submitText}>Sign in</Text>
            )}
          </Pressable>
        </View>

        {/* The other way in, and the only account anybody creates for
            themselves. A driver's and an office account are made for them, so
            this speaks only to the person it is for: somebody with a load and
            nobody carrying it yet. */}
        <View style={styles.signUp}>
          <Text style={styles.signUpText}>
            Need a delivery? Sign up as a customer and choose a carrier near you.
          </Text>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Create a customer account"
            onPress={() => setSigningUp(true)}
            style={styles.signUpBtn}>
            <Text style={styles.signUpBtnText}>Create a customer account</Text>
          </Pressable>
        </View>

        <Text style={styles.footnote}>
          Forgotten your password? The office can reset it for you.
        </Text>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

/**
 * A rejected password and an unreachable server are different problems, and
 * telling a driver "wrong password" when they are simply out of signal sends
 * them down the wrong path entirely.
 *
 * The unreachable case names the address it tried. On a handset that address
 * is derived from whichever machine served the bundle, and seeing it is the
 * quickest way to spot that the API is bound to `127.0.0.1` and the phone was
 * never going to reach it.
 */
function messageFor(error: unknown): string {
  if (error instanceof ApiRequestError) {
    if (error.status === 422) {
      return (
        error.fieldErrors['email']?.[0] ??
        error.body.message ??
        'These credentials do not match our records.'
      );
    }

    if (error.status >= 500) {
      return 'The server hit an error handling that. Ask the office to check the log.';
    }

    return error.body.message || `The server refused that request (${error.status}).`;
  }

  return `Cannot reach ${apiBaseUrl}. Check your signal, and that the server is running.`;
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: Brand.tint },
  scroll: { flexGrow: 1, justifyContent: 'center', paddingHorizontal: Spacing.three },
  brand: { alignItems: 'center', marginBottom: Spacing.five },

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

  heading: { fontSize: 16, fontWeight: '600', color: Brand.ink },
  sub: { marginTop: 4, fontSize: 14, color: Brand.inkMuted },

  failure: {
    marginTop: Spacing.three,
    padding: Spacing.two + 2,
    borderRadius: Radius.control,
    backgroundColor: Brand.redBg,
    color: Brand.red,
    fontSize: 13,
    fontWeight: '500',
  },

  label: {
    marginTop: Spacing.four,
    fontSize: 10,
    fontWeight: '500',
    letterSpacing: 0.6,
    color: Brand.inkMuted,
  },

  input: {
    marginTop: 6,
    height: Hit.min,
    borderWidth: 1,
    borderColor: Brand.line,
    borderRadius: Radius.control,
    paddingHorizontal: Spacing.three,
    fontSize: 15,
    color: Brand.ink,
    backgroundColor: Brand.surface,
  },

  remember: {
    marginTop: Spacing.four,
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.two + 2,
    minHeight: Hit.min,
    paddingVertical: Spacing.one,
  },
  box: {
    width: 20,
    height: 20,
    marginTop: 1,
    borderRadius: Radius.control - 3,
    borderWidth: 1.5,
    borderColor: Brand.line,
    backgroundColor: Brand.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  boxOn: { backgroundColor: Brand.blue, borderColor: Brand.blue },
  rememberCopy: { flex: 1, minWidth: 0 },
  rememberLabel: { fontSize: 14, fontWeight: '600', color: Brand.ink },
  rememberHint: { marginTop: 2, fontSize: 12, color: Brand.inkMuted },

  submit: {
    marginTop: Spacing.four,
    height: 48,
    borderRadius: Radius.control,
    backgroundColor: Brand.blue,
    alignItems: 'center',
    justifyContent: 'center',
  },
  submitText: { color: Brand.surface, fontSize: 15, fontWeight: '600' },

  signUp: { marginTop: Spacing.four, alignItems: 'center', gap: Spacing.two },
  signUpText: { fontSize: 13, color: Brand.inkMuted, textAlign: 'center' },
  signUpBtn: {
    minHeight: Hit.min,
    justifyContent: 'center',
    paddingHorizontal: Spacing.four,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.blue,
    backgroundColor: Brand.surface,
  },
  signUpBtnText: { fontSize: 14, fontWeight: '600', color: Brand.blue },

  footnote: {
    marginTop: Spacing.four,
    textAlign: 'center',
    fontSize: 12,
    color: Brand.inkMuted,
  },
});

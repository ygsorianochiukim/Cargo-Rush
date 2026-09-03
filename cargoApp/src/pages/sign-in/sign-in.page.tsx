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

import { Wordmark } from '@/components/ui/wordmark';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { apiBaseUrl, ApiRequestError } from '@/services/shared/api.service';
import { useSession } from '@/services/identity/session';

/**
 * Sign in.
 *
 * Shown instead of the tabs when there is no session, rather than as a screen
 * inside them: a driver who is not signed in has no dashboard to go back to,
 * and a tab bar over a locked app is a set of dead ends.
 */
export function SignInPage() {
  const insets = useSafeAreaInsets();
  const { signIn } = useSession();

  const [email, setEmail] = useState('');
  const [secret, setSecret] = useState('');
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);

  const ready = email.trim().length > 0 && secret.length > 0;

  const submit = async () => {
    if (!ready || busy) return;

    setBusy(true);
    setFailure(null);

    try {
      await signIn({ email: email.trim(), password: secret });
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
    marginTop: Spacing.four,
    textAlign: 'center',
    fontSize: 12,
    color: Brand.inkMuted,
  },
});

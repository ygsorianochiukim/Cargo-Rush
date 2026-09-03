import { useFonts } from 'expo-font';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { Brand, BrandFont } from '@/constants/theme';
import { AppLayout } from '@/layout/layout';
import { SignInPage } from '@/pages/sign-in/sign-in.page';
import { SessionProvider, useSession } from '@/services/identity/session';
// Imported for its side effect: the background location task has to be
// defined at module scope, because the OS can wake this app purely to
// deliver a position and nothing else will have run.
import '@/services/gps/location-task';

SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  // Race Sport carries the company name and nothing else, so the splash stays
  // up until it is ready — the alternative is the wordmark visibly reflowing
  // from the fallback a beat after the app opens.
  const [fontsLoaded, fontError] = useFonts({
    [BrandFont]: require('@/assets/fonts/RaceSport.ttf'),
  });

  useEffect(() => {
    // A missing brand face is not worth blocking the app over: the wordmark
    // falls back and everything else is unaffected.
    if (fontsLoaded || fontError) SplashScreen.hideAsync();
  }, [fontsLoaded, fontError]);

  if (!fontsLoaded && !fontError) return null;

  return (
    <SafeAreaProvider style={{ flex: 1 }}>
      {/* v1 is light-only (DESIGN.md section 6), so the bar is always dark-on-light. */}
      <StatusBar style="dark" />
      <SessionProvider>
        <Gate />
      </SessionProvider>
    </SafeAreaProvider>
  );
}

/**
 * Sign-in or the app, never both.
 *
 * The tabs are not rendered at all until there is a session. Mounting them
 * behind a modal would fire every screen's fetch first, and a driver would
 * watch five panels fail before being asked to sign in.
 */
function Gate() {
  const { me, restoring } = useSession();

  if (restoring) {
    return (
      <View style={styles.restoring}>
        <ActivityIndicator color={Brand.blue} />
      </View>
    );
  }

  return me === null ? <SignInPage /> : <AppLayout />;
}

const styles = StyleSheet.create({
  restoring: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Brand.tint,
  },
});

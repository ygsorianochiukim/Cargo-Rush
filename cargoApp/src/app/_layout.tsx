import { useFonts } from 'expo-font';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useCallback, useEffect, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { Brand, BrandFont } from '@/constants/theme';
import { AppLayout } from '@/layout/layout';
import { SignInPage } from '@/pages/sign-in/sign-in.page';
import { SplashPage } from '@/pages/splash/splash.page';
import { SessionProvider, useSession } from '@/services/identity/session';
// Imported for its side effect: the background location task has to be
// defined at module scope, because the OS can wake this app purely to
// deliver a position and nothing else will have run.
import '@/services/gps/location-task';

SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  // Race Sport carries the company name and nothing else, so the native splash
  // stays up until it is ready — the alternative is the wordmark visibly
  // reflowing from the fallback a beat after the app opens, and it is the
  // first thing `SplashPage` draws.
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
 * Splash, then sign-in or the app — never the middle of any of it.
 *
 * The tabs are not rendered at all until there is a session. Mounting them
 * behind a modal would fire every screen's fetch first, and a driver would
 * watch five panels fail before being asked to sign in.
 *
 * The splash sits *over* whichever of the two is underneath rather than beside
 * them, so the handover is a cross-fade onto a screen that has already
 * finished laying itself out. Two pieces of state rather than one because the
 * splash has two ends: `settled` is when it may begin leaving, `gone` is when
 * it has left — and only then does it come out of the tree.
 */
function Gate() {
  const { me, restoring } = useSession();

  /** The splash has played its intro and served its minimum hold. */
  const [settled, setSettled] = useState(false);
  /** The fade has finished and the overlay is drawing nothing. */
  const [gone, setGone] = useState(false);

  const ready = settled && !restoring;

  const onIntroDone = useCallback(() => setSettled(true), []);
  const onHidden = useCallback(() => setGone(true), []);

  return (
    <View style={styles.root}>
      {/* Mounted when the splash starts fading, not after it has finished: the
          fade is exactly the time the screen behind has to lay itself out. */}
      {ready ? (me === null ? <SignInPage /> : <AppLayout />) : null}

      {gone ? null : (
        <SplashPage leaving={ready} onIntroDone={onIntroDone} onHidden={onHidden} />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: Brand.tint },
});

import Constants from 'expo-constants';
import { useEffect, useState } from 'react';
import { AccessibilityInfo, StyleSheet, Text, View } from 'react-native';
import Animated, {
  Easing,
  runOnJS,
  useAnimatedStyle,
  useSharedValue,
  withDelay,
  withTiming,
} from 'react-native-reanimated';

import { Wordmark } from '@/components/ui/wordmark';
import { Brand, Spacing } from '@/constants/theme';

/** How long the lockup takes to settle, and the floor on how long it is shown. */
const INTRO_MS = 900;
const HOLD_MS = 1500;
const LEAVE_MS = 320;

export type SplashPageProps = {
  /**
   * True once the app behind the splash is ready to be seen. The splash does
   * not vanish on it — it fades, and only after its own intro has finished.
   */
  leaving: boolean;
  /** The intro has played and the minimum hold has elapsed. */
  onIntroDone: () => void;
  /** The fade is over and nothing is left to draw — the overlay can unmount. */
  onHidden: () => void;
};

/**
 * The launch screen.
 *
 * Sits over everything from the moment the bundle is running until the app has
 * decided whether there is a session, then fades to sign-in or to the tabs.
 *
 * It exists because that decision is a network call. `SessionProvider` restores
 * the stored token and verifies it with `GET /me`, which on a lorry's signal is
 * not instant — and the honest alternatives are both bad: a spinner on a blank
 * field, or the sign-in form flashing up and being replaced a second later by
 * the dashboard of somebody who was already signed in. A held brand screen
 * spends that time saying whose app this is.
 *
 * The background is `Brand.tint`, which is also the background of sign-in and
 * of every screen behind it, and matches the native splash configured in
 * `app.json`. So the whole launch is one colour from the launcher icon to the
 * first screen, with no white flash at either seam.
 *
 * The floor on the hold is deliberate. Restoring usually finishes in
 * milliseconds — on a warm start with no token, immediately — and a brand
 * screen that appears and disappears inside one frame reads as a glitch, not
 * as a brand.
 */
export function SplashPage({ leaving, onIntroDone, onHidden }: SplashPageProps) {
  /**
   * Honour the OS "reduce motion" switch.
   *
   * Read rather than animated-away: with it on, everything below starts at its
   * resting value and only the cross-fade out remains, which is a change of
   * what is on screen rather than movement across it.
   */
  const [reduceMotion, setReduceMotion] = useState(false);

  useEffect(() => {
    let cancelled = false;

    AccessibilityInfo.isReduceMotionEnabled().then((enabled) => {
      if (!cancelled) setReduceMotion(enabled);
    });

    const listener = AccessibilityInfo.addEventListener(
      'reduceMotionChanged',
      setReduceMotion,
    );

    return () => {
      cancelled = true;
      listener.remove();
    };
  }, []);

  const shell = useSharedValue(1);
  const lockup = useSharedValue(0);
  const caption = useSharedValue(0);
  const bar = useSharedValue(0);

  // The intro. Run once, on mount, whatever the session is doing — the hold is
  // a floor on the splash's life, not a race against the network.
  useEffect(() => {
    if (reduceMotion) {
      lockup.value = 1;
      caption.value = 1;
      bar.value = 1;
    } else {
      lockup.value = withDelay(
        60,
        withTiming(1, { duration: 420, easing: Easing.out(Easing.cubic) }),
      );
      caption.value = withDelay(
        320,
        withTiming(1, { duration: 380, easing: Easing.out(Easing.quad) }),
      );
      // Runs the length of the hold rather than the intro: it is a progress
      // bar, and it should still be moving while the app is still deciding.
      bar.value = withDelay(
        INTRO_MS - 400,
        withTiming(1, { duration: HOLD_MS - INTRO_MS + 400, easing: Easing.inOut(Easing.quad) }),
      );
    }

    const floor = setTimeout(onIntroDone, reduceMotion ? 0 : HOLD_MS);

    return () => clearTimeout(floor);
  }, [reduceMotion, lockup, caption, bar, onIntroDone]);

  // The exit. `leaving` only turns true once the app behind is mounted, so the
  // fade reveals a finished screen rather than an empty one filling in.
  useEffect(() => {
    if (!leaving) return;

    shell.value = withTiming(0, { duration: LEAVE_MS, easing: Easing.in(Easing.quad) }, (done) => {
      if (done) runOnJS(onHidden)();
    });
  }, [leaving, shell, onHidden]);

  const shellStyle = useAnimatedStyle(() => ({ opacity: shell.value }));

  const lockupStyle = useAnimatedStyle(() => ({
    opacity: lockup.value,
    transform: [
      { translateY: (1 - lockup.value) * 14 },
      { scale: 0.94 + lockup.value * 0.06 },
    ],
  }));

  const captionStyle = useAnimatedStyle(() => ({ opacity: caption.value }));

  // scaleX from a left-anchored track, so the fill grows rightwards without
  // the layout pass a width animation would cost on every frame.
  const barStyle = useAnimatedStyle(() => ({
    transform: [{ scaleX: Math.max(bar.value, 0.02) }],
  }));

  return (
    <Animated.View
      style={[StyleSheet.absoluteFill, styles.root, shellStyle]}
      pointerEvents={leaving ? 'none' : 'auto'}
      accessibilityViewIsModal
      accessibilityLabel="Cargo Rush is starting">
      <View style={styles.centre}>
        <Animated.View style={lockupStyle}>
          <Wordmark size={34} tagline={false} />
        </Animated.View>

        <Animated.Text style={[styles.tagline, captionStyle]}>
          Fleet Management System
        </Animated.Text>

        <View style={styles.track} accessibilityRole="progressbar">
          <Animated.View style={[styles.fill, barStyle]} />
        </View>
      </View>

      {/* Which build this is. A driver reading it down the phone to the office
          is the fastest way to find out why their app behaves differently from
          the one next to it. */}
      <Text style={styles.version}>
        v{Constants.expoConfig?.version ?? '1.0.0'}
      </Text>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  root: {
    backgroundColor: Brand.tint,
    alignItems: 'center',
    justifyContent: 'center',
  },

  centre: { alignItems: 'center', paddingHorizontal: Spacing.four },

  tagline: {
    marginTop: Spacing.three,
    fontSize: 13,
    fontWeight: '500',
    letterSpacing: 0.6,
    color: Brand.inkMuted,
  },

  track: {
    marginTop: Spacing.five,
    width: 132,
    height: 3,
    borderRadius: 999,
    backgroundColor: Brand.surface,
    overflow: 'hidden',
  },

  fill: {
    width: '100%',
    height: '100%',
    borderRadius: 999,
    backgroundColor: Brand.blue,
    // Anchors the scale to the left edge; without it the fill grows from its
    // own centre and creeps out of both ends of the track.
    transformOrigin: 'left',
  },

  version: {
    position: 'absolute',
    bottom: Spacing.five,
    fontSize: 11,
    color: Brand.inkMuted,
  },
});

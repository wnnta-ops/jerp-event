// Wraps a screen so its content gently fades in each time the screen gains focus.
// Gives smooth, consistent tab transitions on both native and web (where the
// bottom-tab navigator's built-in animation may not run).

import React, { useCallback, useRef } from 'react';
import { Animated, StyleSheet } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';

export default function FadeInOnFocus({ children }: { children: React.ReactNode }) {
  const opacity = useRef(new Animated.Value(0)).current;

  useFocusEffect(
    useCallback(() => {
      opacity.setValue(0);
      const anim = Animated.timing(opacity, { toValue: 1, duration: 220, useNativeDriver: true });
      anim.start();
      return () => anim.stop();
    }, [opacity]),
  );

  return <Animated.View style={[styles.fill, { opacity }]}>{children}</Animated.View>;
}

const styles = StyleSheet.create({ fill: { flex: 1 } });

// Loading skeleton card (.skel/.sk) with a shimmering bar animation.

import React, { useEffect, useRef } from 'react';
import { View, Animated, StyleSheet, Easing } from 'react-native';
import { colors, radius } from '../theme/tokens';

function ShimmerBar({ width }: { width: string | number }) {
  const x = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    const loop = Animated.loop(
      Animated.timing(x, { toValue: 1, duration: 1200, easing: Easing.linear, useNativeDriver: true }),
    );
    loop.start();
    return () => loop.stop();
  }, [x]);

  const opacity = x.interpolate({ inputRange: [0, 0.5, 1], outputRange: [0.55, 1, 0.55] });
  return <Animated.View style={[styles.bar, { width: width as any, opacity }]} />;
}

export function SkeletonCard() {
  return (
    <View style={styles.card}>
      <ShimmerBar width="55%" />
      <ShimmerBar width="80%" />
      <ShimmerBar width="40%" />
    </View>
  );
}

export function SkeletonList({ count = 3 }: { count?: number }) {
  return (
    <>
      {Array.from({ length: count }).map((_, i) => (
        <SkeletonCard key={i} />
      ))}
    </>
  );
}

const styles = StyleSheet.create({
  card: { borderWidth: 1, borderColor: colors.line, borderRadius: radius.lg, padding: 13, marginBottom: 10 },
  bar: { height: 11, borderRadius: 6, marginBottom: 8, backgroundColor: '#E2E8F0' },
});
